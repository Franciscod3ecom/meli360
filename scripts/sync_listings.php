<?php
// scripts/sync_listings.php

// Define um limite de tempo maior para o script, pois a sincronização pode demorar.
set_time_limit(3600); // 1 hora

// Garante que o script só seja executado via CLI
if (php_sapi_name() !== 'cli') {
    die("Este script só pode ser executado a partir da linha de comando.");
}

// Inclui o autoloader e a configuração inicial
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Core/config.php';

// Inclui os helpers necessários
require_once dirname(__DIR__) . '/src/helpers.php';


use App\Models\MercadoLivreUser;
use App\Models\Anuncio;

// Validação do argumento da linha de comando
if (!isset($argv[1]) || !is_numeric($argv[1])) {
    log_message("Erro de execução: ML User ID não fornecido ou inválido.", "ERROR");
    exit(1);
}

$mlUserId = (int) $argv[1];

log_message("Iniciando sincronização para ML User ID: {$mlUserId}", "INFO");

$mlUserModel = new MercadoLivreUser();
$anuncioModel = new Anuncio();

try {
    // 1. Atualiza o status para 'RUNNING'
    $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'RUNNING', 'A sincronização está em andamento.');

    // 2. Obter um token de acesso válido (que se auto-renova se necessário)
    $accessToken = $mlUserModel->getValidAccessToken($mlUserId);
    if (!$accessToken) {
        throw new Exception("Não foi possível obter um token de acesso válido.");
    }

    // 3. Loop de paginação para buscar todos os anúncios
    $limit = 50;
    $offset = 0;
    $total = null;
    $processedCount = 0;

    do {
        $apiUrl = "https://api.mercadolibre.com/users/{$mlUserId}/items/search?limit={$limit}&offset={$offset}";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}"]
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Falha ao buscar anúncios da API do ML. HTTP Code: {$httpCode}. Resposta: {$response}");
        }

        $data = json_decode($response, true);
        if (!isset($data['results'])) {
            throw new Exception("Formato de resposta inesperado da API do ML.");
        }

        if ($total === null) {
            $total = $data['paging']['total'];
            log_message("Total de anúncios encontrados para ML User ID {$mlUserId}: {$total}", "INFO");
        }

        $listingIds = $data['results'];
        if (empty($listingIds)) {
            break; // Sai do loop se não houver mais resultados
        }

        // 4. Buscar detalhes de cada anúncio em lote (respeitando o limite de 20 da API)
        $idChunks = array_chunk($listingIds, 20);

        foreach ($idChunks as $chunk) {
            $detailsUrl = "https://api.mercadolibre.com/items?ids=" . implode(',', $chunk);
            
            $ch_details = curl_init();
            curl_setopt_array($ch_details, [
                CURLOPT_URL => $detailsUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}"]
            ]);
            $detailsResponse = curl_exec($ch_details);
            $detailsHttpCode = curl_getinfo($ch_details, CURLINFO_HTTP_CODE);
            curl_close($ch_details);

            if ($detailsHttpCode !== 200) {
                log_message("Falha ao buscar detalhes dos anúncios. HTTP Code: {$detailsHttpCode}. Resposta: {$detailsResponse}", "WARNING");
                continue; // Pula para o próximo chunk
            }

            $detailsData = json_decode($detailsResponse, true);

            // 5. Salvar cada anúncio no banco de dados
            foreach ($detailsData as $item) {
                if (isset($item['code']) && $item['code'] === 200 && isset($item['body'])) {
                    $anuncioModel->saveOrUpdate($item['body']);
                    $processedCount++;
                }
            }
        }
        
        log_message("Processados {$processedCount} de {$total} anúncios para ML User ID {$mlUserId}...", "INFO");
        $offset += $limit;

    } while ($offset < $total);

    // 6. Atualiza o status para 'COMPLETED'
    $successMessage = "Sincronização concluída com sucesso. {$processedCount} anúncios foram processados.";
    $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'COMPLETED', $successMessage);
    log_message($successMessage, "INFO");

} catch (Exception $e) {
    $errorMessage = "Erro durante a sincronização para ML User ID {$mlUserId}: " . $e->getMessage();
    log_message($errorMessage, "ERROR");
    $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'FAILED', $e->getMessage());
    exit(1);
}

exit(0);
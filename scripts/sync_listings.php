<?php
/**
 * Script de Cron Job para Sincronizar Anúncios do Mercado Livre.
 * Versão 5.0: Pipeline de Sincronização em Fases.
 */

// Setup do ambiente
set_time_limit(300); // 5 minutos
chdir(dirname(__DIR__));
require_once 'public/index.php';

use App\Models\MercadoLivreUser;
use App\Models\Anuncio;

log_message('SYNC_CRON: ================= INÍCIO DO CICLO DE SINCRONIZAÇÃO v5.0 =================');

$mlUserModel = new MercadoLivreUser();
$anuncioModel = new Anuncio();

try {
    // -------------------------------------------------------------------
    // FASE 1: COLETA DE IDS
    // -------------------------------------------------------------------
    log_message("SYNC_CRON: [FASE 1] Verificando contas na fila ('QUEUED')...");
    $connectionToStart = $mlUserModel->findNextInQueue();

    if ($connectionToStart) {
        $mlUserId = $connectionToStart['ml_user_id'];
        $saasUserId = $connectionToStart['saas_user_id'];
        log_message("SYNC_CRON: [FASE 1] Iniciando coleta de IDs para ML User ID: {$mlUserId}");

        $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'RUNNING', 'Fase 1/3: Coletando todos os IDs de anúncios...');
        $accessToken = $mlUserModel->getValidAccessToken($saasUserId, $mlUserId);

        if ($accessToken) {
            $anuncioModel->clearByMlUserId($mlUserId);
            $allItemIds = [];
            $scrollId = null;
            do {
                $url = "https://api.mercadolibre.com/users/{$mlUserId}/items/search?search_type=scan&limit=100" . ($scrollId ? "&scroll_id=" . urlencode($scrollId) : "");
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}"]]);
                $response = curl_exec($ch);
                $data = json_decode($response, true);
                curl_close($ch);
                
                if (isset($data['results'])) {
                    $allItemIds = array_merge($allItemIds, $data['results']);
                    $scrollId = $data['scroll_id'] ?? null;
                } else {
                    throw new Exception("Resposta inesperada da API de scan: " . $response);
                }
            } while ($scrollId);

            $anuncioModel->bulkInsertIds($saasUserId, $mlUserId, $allItemIds);
            $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'RUNNING', 'Fase 2/3: Coleta de IDs concluída. Detalhando anúncios...');
        }
    }

    // -------------------------------------------------------------------
    // FASE 2: DETALHAMENTO BÁSICO
    // -------------------------------------------------------------------
    log_message("SYNC_CRON: [FASE 2] Buscando lote de anúncios para detalhamento básico (status=0)...");
    $anunciosToDetail = $anuncioModel->findAnunciosToProcess(0, 20);

    if (!empty($anunciosToDetail)) {
        $groupedByMlUser = [];
        foreach ($anunciosToDetail as $anuncio) {
            $groupedByMlUser[$anuncio['ml_user_id']][] = $anuncio['ml_item_id'];
        }

        foreach ($groupedByMlUser as $mlUserId => $itemIds) {
            $saasUserId = $mlUserModel->findSaasUserIdByMlUserId($mlUserId);
            if (!$saasUserId) continue;

            $accessToken = $mlUserModel->getValidAccessToken($saasUserId, $mlUserId);
            if (!$accessToken) continue;

            $idsString = implode(',', $itemIds);
            $attributes = 'id,title,price,status,permalink,thumbnail,category_id,available_quantity,sold_quantity,health,attributes,variations';
            $url = "https://api.mercadolibre.com/items?ids={$idsString}&attributes={$attributes}";
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}"]]);
            $response = curl_exec($ch);
            curl_close($ch);
            $detailsData = json_decode($response, true);
            
            if (is_array($detailsData)) {
                $anuncioModel->bulkUpdateBasicDetails($detailsData);
            }
        }
    }

    // -------------------------------------------------------------------
    // FASE 3: ANÁLISE PROFUNDA (FRETE E CATEGORIA)
    // -------------------------------------------------------------------
    log_message("SYNC_CRON: [FASE 3] Buscando lote de anúncios para análise profunda (status=1)...");
    $anunciosToAnalyze = $anuncioModel->findAnunciosToProcess(1, 10);

    if (!empty($anunciosToAnalyze)) {
        // Para simplificar, vamos processar um por um, obtendo o token a cada vez se necessário.
        foreach ($anunciosToAnalyze as $anuncio) {
            $saasUserId = $anuncio['saas_user_id'];
            $mlUserId = $anuncio['ml_user_id'];
            $itemId = $anuncio['ml_item_id'];
            $categoryId = $anuncio['category_id'];

            $accessToken = $mlUserModel->getValidAccessToken($saasUserId, $mlUserId);
            if (!$accessToken) continue;

            // Buscar dados de frete
            $shippingUrl = "https://api.mercadolibre.com/items/{$itemId}/shipping_options";
            $ch_ship = curl_init($shippingUrl);
            curl_setopt_array($ch_ship, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}"]]);
            $shippingResponse = curl_exec($ch_ship);
            curl_close($ch_ship);

            // Buscar dados de categoria
            $categoryUrl = "https://api.mercadolibre.com/categories/{$categoryId}";
            $ch_cat = curl_init($categoryUrl);
            curl_setopt_array($ch_cat, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}"]]);
            $categoryResponse = curl_exec($ch_cat);
            curl_close($ch_cat);

            $anuncioModel->updateDeepAnalysis($itemId, $shippingResponse, $categoryResponse);
            sleep(1); // Pausa para não sobrecarregar a API
        }
    }

    // -------------------------------------------------------------------
    // FASE 4: FINALIZAÇÃO
    // -------------------------------------------------------------------
    // Verifica se alguma conta que estava 'RUNNING' agora não tem mais anúncios para processar
    $runningConnections = $mlUserModel->findConnectionsByStatus('RUNNING');
    foreach ($runningConnections as $conn) {
        $pendingCount = $anuncioModel->countByStatus($conn['ml_user_id'], [0, 1]);
        if ($pendingCount === 0) {
            $mlUserModel->updateSyncStatusByMlUserId($conn['ml_user_id'], 'COMPLETED', 'Sincronização concluída.');
        }
    }

} catch (Exception $e) {
    log_message("SYNC_CRON: [ERRO FATAL] " . $e->getMessage(), 'CRITICAL');
    // Se uma conexão específica estava sendo processada, marca como falha
    if (isset($connectionToStart['ml_user_id'])) {
        $mlUserModel->updateSyncStatusByMlUserId($connectionToStart['ml_user_id'], 'FAILED', 'Erro fatal no script: ' . substr($e->getMessage(), 0, 200));
    }
}

log_message('SYNC_CRON: ================= FIM DO CICLO DE SINCRONIZAÇÃO =================');
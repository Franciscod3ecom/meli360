<?php
/**
 * Script de Cron Job para Sincronizar Anúncios do Mercado Livre.
 * Versão 4.0: Estrutura completa para execução via CLI, com logs detalhados.
 */

// Define um 'chdir' para garantir que os caminhos relativos funcionem corretamente
// a partir da raiz do projeto, independentemente de onde o cron é chamado.
chdir(dirname(__DIR__));

// Carrega o ponto de entrada da aplicação para ter acesso a tudo:
// autoloader, .env, constantes, helpers, etc.
require_once 'public/index.php';

// Usa os namespaces dos modelos que vamos precisar
use App\Models\Anuncio;
use App\Models\MercadoLivreUser;

// --- INÍCIO DA LÓGICA DO CRON ---

log_message('SYNC_CRON: --- Iniciando ciclo de sincronização v4.0 ---');

try {
    // Instancia os modelos após carregar a aplicação
    $anuncioModel = new Anuncio();
    $mlUserModel = new MercadoLivreUser();

    // --- FASE 1: BUSCAR USUÁRIOS COM 'QUEUED' E OBTER IDs ---
    $connection = $mlUserModel->findNextInSyncQueue('QUEUED');

    if ($connection) {
        $saasUserId = $connection['saas_user_id'];
        $mlUserId = $connection['ml_user_id'];
        log_message("SYNC_CRON: [FASE 1] Iniciando para ML User ID: {$mlUserId}");

        $mlUserModel->updateSyncStatus($saasUserId, $mlUserId, 'RUNNING', 'Buscando lista de anúncios na API...');
        $accessToken = $mlUserModel->getValidAccessToken($saasUserId, $mlUserId);

        if (!$accessToken) {
            throw new Exception("Falha ao obter Access Token para o usuário {$saasUserId} / ML ID {$mlUserId}.");
        }

        // Limpa anúncios antigos para esta conta específica
        $anuncioModel->deleteByMlUserId($saasUserId, $mlUserId);
        
        // Busca todos os IDs da API
        $allItemIds = $anuncioModel->fetchAllItemIdsFromApi($mlUserId, $accessToken, function($message, $level = 'INFO') use ($saasUserId, $mlUserId, $mlUserModel) {
            $mlUserModel->updateSyncStatus($saasUserId, $mlUserId, 'RUNNING', $message);
        });

        if (!empty($allItemIds)) {
            $anuncioModel->bulkInsertIds($saasUserId, $mlUserId, $allItemIds);
            $count = count($allItemIds);
            log_message("SYNC_CRON: [FASE 1] {$count} IDs salvos para {$mlUserId}.");
            $mlUserModel->updateSyncStatus($saasUserId, $mlUserId, 'RUNNING', "{$count} anúncios encontrados. Iniciando detalhamento...");
        } else {
            $mlUserModel->updateSyncStatus($saasUserId, $mlUserId, 'COMPLETED', 'Nenhum anúncio ativo encontrado.');
        }
    } else {
        log_message("SYNC_CRON: [FASE 1] Nenhum usuário na fila 'QUEUED'.");
    }

    // --- FASE 2: DETALHAR ANÚNCIOS 'AGUARDANDO' (sync_status = 0) ---
    log_message("SYNC_CRON: [FASE 2] Buscando lote de anúncios para detalhar...");
    
    $anunciosToDetail = $anuncioModel->findAnunciosToDetail(20);

    if (empty($anunciosToDetail)) {
        log_message("SYNC_CRON: [FASE 2] Nenhum anúncio para detalhar neste ciclo.");
        // Não usamos exit aqui para permitir que o log final seja escrito
    } else {
        $groupedAnuncios = [];
        foreach ($anunciosToDetail as $anuncio) {
            $groupedAnuncios[$anuncio['saas_user_id']][] = $anuncio['ml_item_id'];
        }

        foreach ($groupedAnuncios as $saasUserId => $itemIds) {
            log_message("SYNC_CRON: [FASE 2] Detalhando " . count($itemIds) . " anúncios para SaaS ID {$saasUserId}.");
            
            $mlUserId = $anuncioModel->findMlUserIdByItemId($itemIds[0]);
            if (!$mlUserId) continue;

            $accessToken = $mlUserModel->getValidAccessToken($saasUserId, $mlUserId);
            if (!$accessToken) {
                log_message("SYNC_CRON: [FASE 2] [ERRO] Falha ao obter token para {$saasUserId}. Pulando lote.", "ERROR");
                continue;
            }

            $itemDetails = $anuncioModel->fetchItemDetailsFromApi($itemIds, $accessToken);
            if ($itemDetails) {
                $updatedCount = $anuncioModel->bulkUpdateDetails($saasUserId, $mlUserId, $itemDetails);
                log_message("SYNC_CRON: [FASE 2] Detalhes de {$updatedCount} anúncios atualizados para {$saasUserId}.");
            }
            sleep(1); // Pausa para não sobrecarregar a API
        }
    }

    // --- FASE 3: ANÁLISE PROFUNDA (Frete e Categoria) ---
    log_message("SYNC_CRON: [FASE 3] Buscando lote de anúncios para análise profunda...");
    $anunciosToAnalyze = $anuncioModel->findAnunciosToAnalyze(10); // Processa 10 por ciclo para não sobrecarregar

    if (empty($anunciosToAnalyze)) {
        log_message("SYNC_CRON: [FASE 3] Nenhum anúncio para análise profunda neste ciclo.");
    } else {
        // Reutiliza o token do último usuário, se possível, ou busca um novo.
        // Em uma implementação mais complexa, agruparíamos por usuário aqui também.
        if (!isset($accessToken)) {
            $firstAnuncio = $anunciosToAnalyze[0];
            $mlUserId = $anuncioModel->findMlUserIdByItemId($firstAnuncio['ml_item_id']);
            if ($mlUserId) {
                $saasUserId = $mlUserModel->findSaasUserIdByMlUserId($mlUserId); // Este método precisará ser criado
                if ($saasUserId) {
                    $accessToken = $mlUserModel->getValidAccessToken($saasUserId, $mlUserId);
                }
            }
        }

        if (isset($accessToken)) {
            foreach ($anunciosToAnalyze as $anuncio) {
                try {
                    $itemId = $anuncio['ml_item_id'];
                    $categoryId = $anuncio['category_id'];

                    // 1. Buscar dados de frete
                    $shippingUrl = "https://api.mercadolibre.com/items/{$itemId}/shipping_options";
                    $ch_ship = curl_init($shippingUrl);
                    curl_setopt_array($ch_ship, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}"]]);
                    $shippingResponse = curl_exec($ch_ship);
                    curl_close($ch_ship);

                    // 2. Buscar dados de categoria
                    $categoryUrl = "https://api.mercadolibre.com/categories/{$categoryId}";
                    $ch_cat = curl_init($categoryUrl);
                    curl_setopt_array($ch_cat, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}"]]);
                    $categoryResponse = curl_exec($ch_cat);
                    curl_close($ch_cat);

                    // 3. Salvar no banco
                    $anuncioModel->updateDeepAnalysis($itemId, $shippingResponse, $categoryResponse);
                    log_message("SYNC_CRON: [FASE 3] Análise profunda concluída para o item {$itemId}.", "INFO");
                    sleep(1); // Pausa para não exceder os limites da API

                } catch (Exception $e) {
                    log_message("SYNC_CRON: [FASE 3] [ERRO] Falha na análise do item {$itemId}: " . $e->getMessage(), "ERROR");
                    $anuncioModel->markAsFailed($itemId);
                }
            }
        }
    }

} catch (Exception $e) {
    log_message("SYNC_CRON: [ERRO FATAL] " . $e->getMessage(), 'CRITICAL');
    if (isset($saasUserId) && isset($mlUserId) && isset($mlUserModel)) {
        $mlUserModel->updateSyncStatus($saasUserId, $mlUserId, 'ERROR', 'Erro fatal no script: ' . substr($e->getMessage(), 0, 200));
    }
}

log_message('SYNC_CRON: --- Ciclo finalizado ---');
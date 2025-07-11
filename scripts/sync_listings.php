<?php
/**
 * Script de Cron Job para Sincronizar Anúncios do Mercado Livre.
 * Versão 7.0: Pipeline Otimizado com Transações e Lógica de Concorrência.
 *
 * Este script é projetado para ser executado frequentemente (ex: a cada minuto).
 * Ele orquestra a sincronização de anúncios em fases, garantindo robustez
 * e performance através de operações em lote e tratamento de concorrência.
 */

// Setup do ambiente para CLI, isolado do ambiente web.
set_time_limit(3600); // Aumentado para 1 hora para lidar com grandes volumes
ini_set('memory_limit', '512M'); // Aumenta o limite de memória
chdir(dirname(__DIR__));
require_once 'vendor/autoload.php';
require_once 'src/Core/config.php';
require_once 'src/Helpers/log_helper.php';

use App\Models\MercadoLivreUser;
use App\Models\Anuncio;
use App\Core\Database;

log_message('SYNC_CRON: ================= INÍCIO DO CICLO DE SINCRONIZAÇÃO v7.0 =================');

$db = Database::getInstance();
$mlUserModel = new MercadoLivreUser($db);
$anuncioModel = new Anuncio($db);

try {
    // -------------------------------------------------------------------
    // FASE 1: COLETA DE IDS (Inicia uma nova sincronização se houver na fila)
    // -------------------------------------------------------------------
    log_message("SYNC_CRON: [FASE 1] Verificando contas na fila ('QUEUED')...");
    $connection = $mlUserModel->findNextInQueue();

    if ($connection) {
        $saasUserId = (int)$connection['saas_user_id'];
        $mlUserId = (int)$connection['ml_user_id'];
        log_message("SYNC_CRON: [FASE 1] Iniciando coleta de IDs para ML User ID: {$mlUserId}");

        $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'RUNNING', 'Fase 1/4: Coletando todos os IDs de anúncios...');
        $accessToken = $mlUserModel->getValidAccessToken($saasUserId, $mlUserId);

        if ($accessToken) {
            // Limpa anúncios antigos antes de inserir os novos para evitar duplicatas
            $anuncioModel->clearByMlUserId($mlUserId);
            log_message("SYNC_CRON: [FASE 1] Anúncios antigos do ML User ID {$mlUserId} foram limpos.");

            $allItemIds = $anuncioModel->fetchAllItemIdsFromApi($mlUserId, $accessToken, function($message) use ($mlUserId, $mlUserModel) {
                $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'RUNNING', $message);
            });

            if (!empty($allItemIds)) {
                $anuncioModel->bulkInsertIds($saasUserId, $mlUserId, $allItemIds);
                log_message("SYNC_CRON: [FASE 1] ". count($allItemIds) ." IDs inseridos para o ML User ID {$mlUserId}.");
                $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'RUNNING', 'Fase 2/4: Coleta de IDs concluída. Detalhando anúncios...');
            } else {
                 $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'COMPLETED', 'Nenhum anúncio ativo encontrado.');
                 log_message("SYNC_CRON: [FASE 1] Nenhum anúncio encontrado para ML User ID {$mlUserId}. Sincronização concluída.");
            }
        } else {
            $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'FAILED', 'Erro: Falha ao obter Access Token.');
            log_message("SYNC_CRON: [FASE 1] Falha ao obter Access Token para o usuário {$saasUserId}. Marcado como FAILED.", 'ERROR');
        }
    } else {
        log_message("SYNC_CRON: [FASE 1] Nenhuma conta na fila 'QUEUED'.");
    }

    // O script continua para as próximas fases, que processarão os anúncios
    // que já estão no banco de dados, independentemente da FASE 1.

    // -------------------------------------------------------------------
    // FASE 2: DETALHAMENTO BÁSICO (status=0)
    // -------------------------------------------------------------------
    log_message("SYNC_CRON: [FASE 2] Buscando lote de anúncios para detalhamento básico (status=0)...");
    $anunciosParaDetalhar = $anuncioModel->findAnunciosToProcess(0, 20); // Limite da API do ML é 20 por chamada

    if (!empty($anunciosParaDetalhar)) {
        log_message("SYNC_CRON: [FASE 2] Encontrados " . count($anunciosParaDetalhar) . " anúncios para detalhar.");
        $groupedByMlUser = [];
        foreach ($anunciosParaDetalhar as $anuncio) {
            $groupedByMlUser[$anuncio['ml_user_id']][] = $anuncio['ml_item_id'];
        }

        foreach ($groupedByMlUser as $mlUserId => $itemIds) {
            $saasUserId = $mlUserModel->findSaasUserIdByMlUserId($mlUserId);
            if (!$saasUserId) {
                log_message("SYNC_CRON: [FASE 2] SAAS User ID não encontrado para ML User ID {$mlUserId}. Pulando lote.", 'WARNING');
                continue;
            }

            $accessToken = $mlUserModel->getValidAccessToken($saasUserId, $mlUserId);
            if (!$accessToken) {
                log_message("SYNC_CRON: [FASE 2] Access Token inválido para ML User ID {$mlUserId}. Pulando lote.", 'WARNING');
                continue;
            }

            $mlApi = new \App\Models\MercadoLivreApi($accessToken);
            $itemsDetails = $mlApi->getMultipleItemDetails($itemIds, ['id','title','price','status','permalink','thumbnail','category_id','available_quantity','sold_quantity','health','attributes','variations']);

            // Adiciona o ml_item_id a cada item de detalhe para referência em caso de falha
            foreach ($itemsDetails as &$detail) {
                if (isset($detail['body']['id'])) {
                    $detail['ml_item_id'] = $detail['body']['id'];
                }
            }
            unset($detail);


            $updatedCount = $anuncioModel->bulkUpdateBasicDetails($itemsDetails);
            log_message("SYNC_CRON: [FASE 2] Lote para ML User ID {$mlUserId} processado. {$updatedCount} anúncios atualizados para status 1.");
        }
    } else {
        log_message("SYNC_CRON: [FASE 2] Nenhum anúncio com status 0 para processar.");
    }


    // -------------------------------------------------------------------
    // FASE 3: ANÁLISE PROFUNDA (FRETE E CATEGORIA) (status=1)
    // -------------------------------------------------------------------
    log_message("SYNC_CRON: [FASE 3] Buscando lote de anúncios para análise profunda (status=1)...");
    $anunciosParaAnalisar = $anuncioModel->findAnunciosToProcess(1, 10); // Menor lote devido a múltiplas chamadas de API por item

    if (!empty($anunciosParaAnalisar)) {
        log_message("SYNC_CRON: [FASE 3] Encontrados " . count($anunciosParaAnalisar) . " anúncios para análise profunda.");
        $analysisDataBatch = [];
        $saasAccessTokens = []; // Cache de tokens para evitar buscas repetidas

        foreach ($anunciosParaAnalisar as $anuncio) {
            $saasUserId = $anuncio['saas_user_id'];
            $mlUserId = $anuncio['ml_user_id'];
            $itemId = $anuncio['ml_item_id'];
            $categoryId = $anuncio['category_id'];

            // Obter token de acesso (com cache)
            if (!isset($saasAccessTokens[$saasUserId])) {
                 $saasAccessTokens[$saasUserId] = $mlUserModel->getValidAccessToken($saasUserId, $mlUserId);
            }
            $accessToken = $saasAccessTokens[$saasUserId];

            if (!$accessToken || !$categoryId) {
                log_message("SYNC_CRON: [FASE 3] Token ou CategoryID ausente para o item {$itemId}. Marcando como falho.", 'WARNING');
                $anuncioModel->markAsFailed($itemId);
                continue;
            }

            $mlApi = new \App\Models\MercadoLivreApi($accessToken);

            // Busca dados de frete e categoria em paralelo
            $urls = [
                'shipping' => "https://api.mercadolibre.com/items/{$itemId}/shipping_options",
                'category' => "https://api.mercadolibre.com/categories/{$categoryId}"
            ];
            $apiResults = $mlApi->getParallel($urls);

            $shippingData = $apiResults['shipping'] ?? null;
            $categoryData = $apiResults['category'] ?? null;

            // Se alguma chamada falhar, marcamos o anúncio como falho e continuamos
            if (!$shippingData || !$categoryData) {
                log_message("SYNC_CRON: [FASE 3] Falha ao buscar dados de frete/categoria para o item {$itemId}. Marcando como falho.", 'WARNING');
                $anuncioModel->markAsFailed($itemId);
                continue;
            }

            $analysisDataBatch[] = [
                'ml_item_id' => $itemId,
                'shipping_data' => json_encode($shippingData),
                'category_data' => json_encode($categoryData)
            ];
        }

        if (!empty($analysisDataBatch)) {
            $updatedCount = $anuncioModel->bulkUpdateDeepAnalysis($analysisDataBatch);
            log_message("SYNC_CRON: [FASE 3] Lote de análise profunda processado. {$updatedCount} anúncios atualizados para status 2.");
        }

    } else {
        log_message("SYNC_CRON: [FASE 3] Nenhum anúncio com status 1 para processar.");
    }


    // -------------------------------------------------------------------
    // FASE 4: FINALIZAÇÃO (Verifica contas 'RUNNING' que podem ter terminado)
    // -------------------------------------------------------------------
    log_message("SYNC_CRON: [FASE 4] Verificando contas em execução para finalização...");
    $runningConnections = $mlUserModel->findConnectionsByStatus('RUNNING');
    if (!empty($runningConnections)) {
        foreach ($runningConnections as $conn) {
            $pendingCount = $anuncioModel->countByStatus($conn['ml_user_id'], [0, 1]);
            if ($pendingCount === 0) {
                $mlUserModel->updateSyncStatusByMlUserId($conn['ml_user_id'], 'COMPLETED', 'Sincronização concluída com sucesso.');
                log_message("SYNC_CRON: [FASE 4] Sincronização concluída para ML User ID: " . $conn['ml_user_id']);
            } else {
                // Atualiza o progresso
                $totalCount = $anuncioModel->countTotalByMlUserId($conn['ml_user_id']);
                $completedCount = $totalCount - $pendingCount;
                $progress = $totalCount > 0 ? round(($completedCount / $totalCount) * 100) : 0;
                $mlUserModel->updateSyncStatusByMlUserId($conn['ml_user_id'], 'RUNNING', "Fase 3/4: Análise profunda em andamento... ({$progress}%)");
                log_message("SYNC_CRON: [FASE 4] ML User ID: {$conn['ml_user_id']} ainda tem {$pendingCount} anúncios pendentes. Progresso: {$progress}%.");
            }
        }
    } else {
        log_message("SYNC_CRON: [FASE 4] Nenhuma conta no estado 'RUNNING'.");
    }


} catch (PDOException $e) {
    // Erros de banco de dados são críticos.
    log_message('SYNC_CRON: ERRO CRÍTICO DE BANCO DE DADOS. O script será encerrado. Erro: ' . $e->getMessage(), 'CRITICAL');
    // Em um ambiente de produção, aqui poderia haver um alerta para os administradores.
    exit(1); // Sai com código de erro
} catch (Exception $e) {
    // Outras exceções (ex: falhas de API não tratadas, lógica interna)
    log_message('SYNC_CRON: ERRO INESPERADO. O script será encerrado. Erro: ' . $e->getMessage(), 'CRITICAL');
    exit(1);
}

log_message('SYNC_CRON: ================= FIM DO CICLO DE SINCRONIZAÇÃO =================');
echo "Ciclo de sincronização concluído.";
?>
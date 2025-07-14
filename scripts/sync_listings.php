<?php
/**
 * Script de Cron Job para Sincronizar Anúncios do Mercado Livre.
 * Versão 9.0: Utilizando o Centralizador de API.
 *
 * Este script agora delega todas as chamadas de API para a classe MercadoLivreApi,
 * que gerencia o rate limiting, retries e a lógica específica de cada endpoint.
 */

// Setup do ambiente
set_time_limit(3600);
ini_set('memory_limit', '512M');
chdir(dirname(__DIR__));
require_once 'vendor/autoload.php';
require_once 'src/Core/config.php';
require_once 'src/Helpers/log_helper.php';
require_once 'src/Models/MercadoLivreApi.php'; // Inclui o novo modelo

use App\Models\MercadoLivreUser;
use App\Models\Anuncio;
use App\Core\Database;

log_message('SYNC_CRON: ================= INÍCIO DO CICLO DE SINCRONIZAÇÃO v9.0 =================');

$db = Database::getInstance();
$mlUserModel = new MercadoLivreUser();
$anuncioModel = new Anuncio();

try {
    // -------------------------------------------------------------------
    // TAREFA 1: INICIAR COLETA DE IDS PARA UMA CONTA 'QUEUED'
    // -------------------------------------------------------------------
    log_message("SYNC_CRON: [TAREFA 1] Verificando contas na fila ('QUEUED')...");
    $connection = $mlUserModel->findNextInQueue();

    if ($connection) {
        $saasUserId = (int)$connection['saas_user_id'];
        $mlUserId = (int)$connection['ml_user_id'];
        log_message("SYNC_CRON: [TAREFA 1] Processando conta QUEUED: ML User ID {$mlUserId}.");

        $accessToken = $mlUserModel->getValidAccessToken($saasUserId, $mlUserId);
        if (!$accessToken) {
            throw new Exception("Parando sincronização para {$mlUserId}: Falha ao obter token válido.");
        }

        $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'RUNNING', 'Fase 1/4: Coletando IDs de anúncios...');
        $anuncioModel->clearByMlUserId($mlUserId);

        // Usa o novo centralizador de API
        $mlApi = new MercadoLivreApi($accessToken);
        $allItemIds = $mlApi->fetchAllItemIds($mlUserId);

        if ($allItemIds !== null && !empty($allItemIds)) {
            $anuncioModel->bulkInsertIds($saasUserId, $mlUserId, $allItemIds);
            $count = count($allItemIds);
            log_message("SYNC_CRON: [TAREFA 1 SUCESSO] {$count} IDs salvos para {$mlUserId}.");
            $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'RUNNING', "{$count} anúncios na fila para detalhamento.");
        } else if ($allItemIds === null) {
             $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'FAILED', 'Erro ao buscar IDs na API do ML.');
        } else {
            log_message("SYNC_CRON: [TAREFA 1 AVISO] Nenhum ID de anúncio encontrado para {$mlUserId}.");
            $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'COMPLETED', 'Nenhum anúncio ativo encontrado.');
        }
        
        log_message('SYNC_CRON: --- Fim do ciclo (Tarefa 1 concluída) ---');
        exit;
    }
    log_message("SYNC_CRON: [TAREFA 1] Nenhuma conta na fila 'QUEUED'.");

    // -------------------------------------------------------------------
    // TAREFA 2: PROCESSAR DETALHAMENTO BÁSICO (status=0)
    // -------------------------------------------------------------------
    log_message("SYNC_CRON: [TAREFA 2] Buscando lote de anúncios para detalhamento básico (status=0)...");
    // O limite da API é 20, então buscamos lotes de 20 do nosso banco.
    $anunciosParaDetalhar = $anuncioModel->findAnunciosToProcess(0, 20); 

    if (!empty($anunciosParaDetalhar)) {
        $mlUserId = $anunciosParaDetalhar[0]['ml_user_id'];
        $saasUserId = $anunciosParaDetalhar[0]['saas_user_id'];
        $itemIds = array_column($anunciosParaDetalhar, 'ml_item_id');
        
        log_message("SYNC_CRON: [TAREFA 2] Encontrados " . count($itemIds) . " anúncios para detalhar para o ML User ID {$mlUserId}.");

        $accessToken = $mlUserModel->getValidAccessToken($saasUserId, $mlUserId);
        if (!$accessToken) {
            log_message("SYNC_CRON: [TAREFA 2] Falha de token para {$mlUserId}. Pulando lote.", 'ERROR');
        } else {
            $mlApi = new MercadoLivreApi($accessToken);
            $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'RUNNING', 'Fase 2/4: Detalhando anúncios...');
            
            // Chama o método que respeita o limite da API e já trata retries
            $detalhes = $mlApi->fetchItemsDetails($itemIds);

            if ($detalhes) {
                // Salva os detalhes no banco de dados
                $anuncioModel->bulkUpdateDetails($saasUserId, $mlUserId, $detalhes);
                log_message("SYNC_CRON: [TAREFA 2 SUCESSO] Lote de " . count($itemIds) . " anúncios detalhado e salvo.");
            } else {
                log_message("SYNC_CRON: [TAREFA 2 FALHA] API não retornou detalhes para o lote. Verifique os logs de API_ERROR.", 'ERROR');
                // Opcional: Marcar estes IDs como falhos para não tentar de novo imediatamente.
                $anuncioModel->markAsFailed($itemIds, 'details');
            }
        }
        
        // Pausa de 0.5 segundo para ser gentil com a API antes do próximo ciclo do cron
        usleep(500000); 
        log_message('SYNC_CRON: --- Fim do ciclo (Tarefa 2 concluída) ---');
        exit;
    }
    log_message("SYNC_CRON: [TAREFA 2] Nenhum anúncio para detalhamento básico.");

    // -------------------------------------------------------------------
    // TAREFA 3: PROCESSAR ANÁLISE PROFUNDA (FRETE E CATEGORIA) (status=1)
    // -------------------------------------------------------------------
    log_message("SYNC_CRON: [TAREFA 3] Buscando lote de anúncios para análise profunda (status=1)...");
    // Lote menor, pois cada item pode gerar múltiplas chamadas de API
    $anunciosParaAnalisar = $anuncioModel->findAnunciosToProcess(1, 5); 

    if (!empty($anunciosParaAnalisar)) {
        $mlUserId = $anunciosParaAnalisar[0]['ml_user_id'];
        $saasUserId = $anunciosParaAnalisar[0]['saas_user_id'];
        
        log_message("SYNC_CRON: [TAREFA 3] Encontrados " . count($anunciosParaAnalisar) . " anúncios para análise profunda para o ML User ID {$mlUserId}.");

        $accessToken = $mlUserModel->getValidAccessToken($saasUserId, $mlUserId);
        if (!$accessToken) {
            log_message("SYNC_CRON: [TAREFA 3] Falha de token para {$mlUserId}. Pulando lote.", 'ERROR');
        } else {
            $mlApi = new MercadoLivreApi($accessToken);
            $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'RUNNING', 'Fase 3/4: Análise profunda...');
            
            $analysisDataBatch = [];
            $capitais = ['SP' => '01001000', 'RJ' => '20010000', 'MG' => '30112000', 'PR' => '80010000', 'SC' => '88010000'];

            foreach ($anunciosParaAnalisar as $anuncio) {
                $itemId = $anuncio['ml_item_id'];
                $categoryId = $anuncio['category_id'];

                if (empty($categoryId)) {
                    $anuncioModel->markAsFailed([$itemId]);
                    continue;
                }

                // 1. Buscar dados da categoria
                $categoryData = $mlApi->fetchCategoryDetails($categoryId);
                usleep(250000); // Pausa de 0.25s

                // 2. Buscar dados de frete para as capitais
                $shippingCosts = [];
                foreach ($capitais as $uf => $zip) {
                    $shippingOption = $mlApi->fetchShippingOptions($itemId, $zip);
                    $shippingCosts[$uf] = [
                        'zip_code' => $zip,
                        'cost' => $shippingOption['options'][0]['cost'] ?? 0,
                        'free' => isset($shippingOption['options'][0]['free_method'])
                    ];
                    usleep(250000); // Pausa de 0.25s
                }
                
                // Salva os custos de frete na tabela dedicada
                $anuncioModel->saveShippingCosts($anuncio['id'], $itemId, $shippingCosts);

                // Prepara o batch para a tabela principal
                $analysisDataBatch[] = [
                    'ml_item_id' => $itemId,
                    'shipping_data' => json_encode($shippingCosts), // Salva um resumo
                    'category_data' => json_encode($categoryData)
                ];
            }

            if (!empty($analysisDataBatch)) {
                $anuncioModel->bulkUpdateDeepAnalysis($analysisDataBatch);
                log_message("SYNC_CRON: [TAREFA 3 SUCESSO] Lote de " . count($analysisDataBatch) . " anúncios analisado e salvo.");
            }
        }
        
        log_message('SYNC_CRON: --- Fim do ciclo (Tarefa 3 concluída) ---');
        exit;
    }
    log_message("SYNC_CRON: [TAREFA 3] Nenhum anúncio para análise profunda.");

    // -------------------------------------------------------------------
    // TAREFA 4: FINALIZAR SINCRONIZAÇÕES CONCLUÍDAS
    // -------------------------------------------------------------------
    log_message("SYNC_CRON: [TAREFA 4] Verificando contas em execução para finalização...");
    $runningConnections = $mlUserModel->findConnectionsByStatus('RUNNING');
    if (!empty($runningConnections)) {
        foreach ($runningConnections as $conn) {
            $pendingCount = $anuncioModel->countByStatus($conn['ml_user_id'], [0, 1]);
            if ($pendingCount === 0) {
                $mlUserModel->updateSyncStatusByMlUserId($conn['ml_user_id'], 'COMPLETED', 'Sincronização concluída com sucesso.');
                log_message("SYNC_CRON: [TAREFA 4] Sincronização concluída para ML User ID: " . $conn['ml_user_id']);
            } else {
                // Atualiza o progresso
                $totalCount = $anuncioModel->countTotalByMlUserId($conn['ml_user_id']);
                $completedCount = $totalCount - $pendingCount;
                $progress = $totalCount > 0 ? round(($completedCount / $totalCount) * 100) : 0;
                $mlUserModel->updateSyncStatusByMlUserId($conn['ml_user_id'], 'RUNNING', "Fase 3/4: Análise profunda em andamento... ({$progress}%)");
                log_message("SYNC_CRON: [TAREFA 4] ML User ID: {$conn['ml_user_id']} ainda tem {$pendingCount} anúncios pendentes. Progresso: {$progress}%.");
            }
        }
    } else {
        log_message("SYNC_CRON: [TAREFA 4] Nenhuma conta no estado 'RUNNING'.");
    }


} catch (Exception $e) {
    log_message("SYNC_CRON: [ERRO FATAL NO CICLO] " . $e->getMessage(), 'CRITICAL');
    // Se uma exceção foi lançada, a conta já deve ter seu status atualizado para FAILED/ERROR.
    // Isso evita que a conta fique presa em 'RUNNING'.
}

log_message('SYNC_CRON: ================= FIM DO CICLO DE SINCRONIZAÇÃO =================');
echo "Ciclo de sincronização concluído.";
?>
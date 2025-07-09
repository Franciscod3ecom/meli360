<?php
// Bloco de inicialização robusto
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

define('BASE_PATH', realpath(__DIR__ . '/../') . '/');
require_once BASE_PATH . 'vendor/autoload.php';
require_once BASE_PATH . 'src/Helpers/log_helper.php';

try {
    $dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
    $dotenv->load();
} catch (\Exception $e) {
    error_log("CRON_FATAL: .env não encontrado. " . $e->getMessage());
    exit(1);
}

use App\Core\Database;
use App\Models\Anuncio;
use App\Models\MercadoLivreUser;
use App\Models\MercadoLivreApi;

$lockFile = sys_get_temp_dir() . '/meli360_sync.lock';
$lockHandle = fopen($lockFile, 'c');
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    log_message("SYNC_CRON: Outro processo já em andamento.", "INFO");
    exit;
}

log_message("SYNC_CRON: --- Iniciando ciclo ---");
$mlUserId = null;

try {
    $pdo = Database::getInstance();
    $mlUserModel = new MercadoLivreUser();
    
    log_message("SYNC_CRON: [PASSO 1/7] Buscando conta com status 'REQUESTED'...");
    $stmt = $pdo->prepare("SELECT * FROM mercadolibre_users WHERE sync_status = 'REQUESTED' ORDER BY updated_at ASC LIMIT 1");
    $stmt->execute();
    $connection = $stmt->fetch();

    if (!$connection) {
        log_message("SYNC_CRON: [INFO] Nenhuma conta na fila.");
        exit;
    }
    
    $saasUserId = $connection['saas_user_id'];
    $mlUserId = $connection['ml_user_id'];
    log_message("SYNC_CRON: [PASSO 2/7] Conta encontrada! Processando ML User ID: {$mlUserId}");

    $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'SYNCING', 'Iniciando busca de IDs...');
    log_message("SYNC_CRON: [PASSO 3/7] Status da conta {$mlUserId} alterado para 'SYNCING'.");

    $accessToken = $mlUserModel->getValidAccessToken($mlUserId);
    if (!$accessToken) {
        throw new Exception("Falha ao obter Access Token para o ML User ID {$mlUserId}.");
    }
    log_message("SYNC_CRON: [PASSO 4/7] Access Token obtido para ML User ID: {$mlUserId}");

    $mlApi = new MercadoLivreApi();
    $apiResult = $mlApi->getAllItemIds($mlUserId, $accessToken);
    if ($apiResult['error']) {
        throw new Exception("Erro da API do ML: " . $apiResult['error']);
    }
    log_message("SYNC_CRON: [PASSO 5/7] API do ML consultada.");

    $allItemIds = $apiResult['item_ids'];
    $totalFound = is_array($allItemIds) ? count($allItemIds) : 0;
    log_message("SYNC_CRON: [PASSO 6/7] API retornou {$totalFound} IDs para ML User ID: {$mlUserId}.");
    
    $anuncioModel = new Anuncio();
    $pdo->prepare("DELETE FROM anuncios WHERE ml_user_id = ?")->execute([$mlUserId]);
    
    if ($totalFound > 0) {
        $anuncioModel->bulkInsertIds($saasUserId, $mlUserId, $allItemIds);
        $message = "{$totalFound} anúncios encontrados. Fase 1 (IDs) concluída.";
        $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'SYNCING', $message); // MUDANÇA: Mantém SYNCING para a próxima fase
    } else {
        $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'COMPLETED', 'Nenhum anúncio ativo encontrado.');
    }

    log_message("SYNC_CRON: [PASSO 7/7] Sincronização de IDs para ML User ID {$mlUserId} finalizada.");

} catch (\Throwable $e) {
    $errorMsg = "SYNC_CRON: ERRO FATAL: " . $e->getMessage();
    log_message($errorMsg, "ERROR");
    if ($mlUserId) {
        $mlUserModel = $mlUserModel ?? new MercadoLivreUser();
        $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'ERROR', 'Erro fatal no script de sincronização.');
    }
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    log_message("SYNC_CRON: --- Ciclo finalizado ---");
}
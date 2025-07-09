<?php
/**
 * Script de Cron Job para Sincronizar DETALHES dos Anúncios.
 *
 * Executado periodicamente para:
 * 1. Encontrar anúncios que estão aguardando sincronização (`sync_status` = 0).
 * 2. Buscar os detalhes desses anúncios em lotes na API do ML.
 * 3. Atualizar o banco de dados com as informações detalhadas.
 */

define('BASE_PATH', realpath(__DIR__ . '/../') . '/');
require_once BASE_PATH . 'vendor/autoload.php';
require_once BASE_PATH . 'src/Helpers/log_helper.php';

try {
    $dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
    $dotenv->load();
} catch (\Exception $e) {
    exit(1);
}

use App\Core\Database;
use App\Models\Anuncio;
use App\Models\MercadoLivreUser;
use App\Models\MercadoLivreApi;

$lockFile = sys_get_temp_dir() . '/meli360_sync_details.lock';
$lockHandle = fopen($lockFile, 'c');
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    log_message("SYNC_DETAILS_CRON: Outro processo de detalhamento já em andamento.", "INFO");
    exit;
}

log_message("SYNC_DETAILS_CRON: --- Iniciando ciclo de detalhamento ---");

try {
    $pdo = Database::getInstance();
    $anuncioModel = new Anuncio();
    $mlUserModel = new MercadoLivreUser();
    $mlApi = new MercadoLivreApi();

    // Busca um lote de até 20 anúncios que precisam de detalhes
    $stmt = $pdo->prepare("SELECT * FROM anuncios WHERE sync_status = 0 ORDER BY id ASC LIMIT 20");
    $stmt->execute();
    $anunciosToSync = $stmt->fetchAll();

    if (empty($anunciosToSync)) {
        log_message("SYNC_DETAILS_CRON: [INFO] Nenhum anúncio na fila para detalhar.");
        exit;
    }
    
    $mlUserId = $anunciosToSync[0]['ml_user_id'];
    log_message("SYNC_DETAILS_CRON: Processando detalhes para {$mlUserId}. Lote de " . count($anunciosToSync) . " anúncios.");
    
    $accessToken = $mlUserModel->getValidAccessToken($mlUserId);
    if (!$accessToken) {
        throw new Exception("Falha ao obter Access Token para detalhar anúncios do ML User ID {$mlUserId}.");
    }

    $itemIds = array_column($anunciosToSync, 'ml_item_id');
    $apiResult = $mlApi->getItemsDetails($itemIds, $accessToken);
    
    if ($apiResult['error']) {
        throw new Exception("Erro da API do ML ao buscar detalhes: " . $apiResult['error']);
    }

    $countUpdated = 0;
    foreach ($apiResult['data'] as $itemData) {
        if (isset($itemData['code']) && $itemData['code'] == 200 && isset($itemData['body'])) {
            $details = $itemData['body'];
            $anuncioModel->updateDetails($details['id'], $details);
            $countUpdated++;
        }
    }
    log_message("SYNC_DETAILS_CRON: {$countUpdated} anúncios detalhados e atualizados no banco de dados.");

} catch (\Throwable $e) {
    log_message("SYNC_DETAILS_CRON: ERRO FATAL: " . $e->getMessage(), "ERROR");
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    log_message("SYNC_DETAILS_CRON: --- Ciclo finalizado ---");
}
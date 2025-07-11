{
    "name": "seu-usuario/analisador-anuncios-ml",
    "description": "Analisador de Anuncios ML Project",
    "type": "project",
    "require": {
        "php": ">=8.0",
        "defuse/php-encryption": "^2.4"
    },
    "config": {
        "optimize-autoloader": true,
        "preferred-install": "dist"
    }
}

<?php
/**
 * Arquivo: clear_sync.php
 * Versão: v1.0
 * Descrição: Ação de admin para limpar dados de um usuário e reiniciar a sincronização.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/log_helper.php';

// Proteção: Apenas Super Admin pode executar
if (!isset($_SESSION['saas_user_id']) || !isset($_SESSION['is_super_admin']) || !$_SESSION['is_super_admin']) {
    header('Location: login.php?error=unauthorized');
    exit;
}

$targetUserId = filter_input(INPUT_POST, 'user_id_to_clear', FILTER_VALIDATE_INT);

if (!$targetUserId || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: super_admin.php');
    exit;
}

try {
    $pdo = getDbConnection();
    $pdo->beginTransaction();

    // 1. Apaga todos os anúncios do usuário.
    $stmtDelete = $pdo->prepare("DELETE FROM anuncios WHERE saas_user_id = ?");
    $stmtDelete->execute([$targetUserId]);
    $deletedCount = $stmtDelete->rowCount();

    // 2. Reseta o status de sincronização para forçar um reinício completo.
    $stmtUpdate = $pdo->prepare("UPDATE mercadolibre_users SET sync_status = 'REQUESTED', sync_last_message = 'Sincronização reiniciada pelo administrador.', sync_scroll_id = NULL WHERE saas_user_id = ?");
    $stmtUpdate->execute([$targetUserId]);

    $pdo->commit();
    logMessage("ADMIN ACTION: Dados do usuário $targetUserId foram limpos ($deletedCount anúncios) e sync reiniciado pelo admin {$_SESSION['saas_user_id']}.");

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logMessage("ADMIN ACTION ERROR: Falha ao limpar dados do usuário $targetUserId. Erro: " . $e->getMessage());
}

header('Location: super_admin.php');
exit;
?>

<?php
/**
 * Arquivo: config.php (Analisador de Anúncios ML)
 * Versão: v3.1 - Carrega segredos de pasta segura fora do web root.
 * Descrição: Ponto central de configuração e inicialização.
 */

if (!defined('BASE_PATH')) {
    // __DIR__ é a pasta onde este arquivo (config.php) está.
    // Ex: /home/u12345/public_html/analisador
    define('BASE_PATH', __DIR__);
}

// --- Configuração de Erros para PRODUÇÃO ---
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/php_errors.log'); // Log de erros do PHP na pasta do projeto

// --- Carregar Segredos do Arquivo Externo (Seguro) ---
// Esta é a parte mais importante.
// `dirname(BASE_PATH)` sobe um nível (para /public_html).
// `dirname(dirname(BASE_PATH))` sobe dois níveis (para a raiz onde estão public_html e analisador_secure).
// Em seguida, entramos na pasta `analisador_secure`.
$secretsFilePath = dirname(dirname(BASE_PATH)) . '/analisador_secure/secrets.php';

if (!file_exists($secretsFilePath)) {
    http_response_code(500);
    error_log("ERRO CRÍTICO: Arquivo de segredos NÃO ENCONTRADO em '$secretsFilePath'. Verifique o caminho e as permissões.");
    die("Erro crítico de configuração do servidor (Code: SEC01).");
}
$secrets = require $secretsFilePath;
if (!is_array($secrets)) {
    http_response_code(500);
    error_log("ERRO CRÍTICO: Arquivo de segredos ('$secretsFilePath') não retornou um array.");
    die("Erro crítico de configuração do servidor (Code: SEC04).");
}

// --- Composer Autoloader ---
$autoloaderPath = BASE_PATH . '/vendor/autoload.php';
if (!file_exists($autoloaderPath)) {
    http_response_code(500);
    error_log("ERRO CRÍTICO: Autoloader do Composer não encontrado em '$autoloaderPath'.");
    die("Erro crítico de inicialização do sistema (Code: AUT01).");
}
require_once $autoloaderPath;

// --- Sessão ---
date_default_timezone_set('America/Sao_Paulo');
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Definição de Constantes Globais ---
define('LOG_FILE', BASE_PATH . '/sync.log');
define('DB_HOST', $secrets['DB_HOST'] ?? 'localhost');
define('DB_NAME', $secrets['DB_NAME'] ?? '');
define('DB_USER', $secrets['DB_USER'] ?? '');
define('DB_PASS', $secrets['DB_PASS'] ?? '');
define('ML_APP_ID', $secrets['ML_APP_ID'] ?? '');
define('ML_SECRET_KEY', $secrets['ML_SECRET_KEY'] ?? '');
define('ML_REDIRECT_URI', $secrets['ML_REDIRECT_URI'] ?? '');
define('ML_AUTH_URL', 'https://auth.mercadolivre.com.br/authorization');
define('ML_TOKEN_URL', 'https://api.mercadolibre.com/oauth/token');
define('ML_API_BASE_URL', 'https://api.mercadolibre.com');

// --- Verificação Final de Configurações Críticas ---
$criticalConfigs = ['DB_PASS', 'ML_SECRET_KEY', 'APP_ENCRYPTION_KEY'];
foreach ($criticalConfigs as $key) {
    if (empty($secrets[$key])) {
        http_response_code(500);
        error_log("ERRO CRÍTICO Config: Segredo essencial '$key' não definido ou vazio.");
        die("Erro crítico de configuração do servidor (Code: CFG05).");
    }
}

// --- Inclusão de Helpers Essenciais ---
require_once BASE_PATH . '/includes/log_helper.php';
require_once BASE_PATH . '/includes/curl_helper.php';
require_once BASE_PATH . '/includes/helpers.php';
?>


<?php
/**
 * Arquivo: dashboard.php
 * Versão: v9.1 - Utiliza a função helper para gerar a tag de última venda e implementa paginação.
 * Descrição: Painel de controle principal do Analisador de Anúncios ML.
 */

// --- Includes Essenciais ---
require_once __DIR__ . '/config.php'; // Inclui config, que por sua vez já inclui os helpers.
require_once __DIR__ . '/db.php';

// --- Proteção de Acesso ---
if (!isset($_SESSION['saas_user_id'])) {
    header('Location: login.php?error=unauthorized');
    exit;
}

// --- Lógica de Personificação ---
$isImpersonating = isset($_SESSION['impersonating_user_id']);
// O ID a ser usado em todas as consultas é o do usuário personificado, ou o do próprio usuário logado.
$saasUserIdToQuery = $isImpersonating ? $_SESSION['impersonating_user_id'] : $_SESSION['saas_user_id'];
$loggedInUserEmail = $_SESSION['saas_user_email'] ?? 'Usuário';
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;

// --- Configurações de Paginação ---
$itemsPerPageOptions = [100, 200, 500]; // Opções que o usuário pode escolher
$itemsPerPage = (isset($_GET['limit']) && in_array((int)$_GET['limit'], $itemsPerPageOptions)) ? (int)$_GET['limit'] : 100; // Padrão de 100
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage <= 0) {
    $currentPage = 1;
}

// --- Inicialização de Variáveis ---
$mlConnection = null;
$anuncios = [];
$totalAnunciosNoDb = 0;
$totalPages = 1;
$progresso = 0;
$totalAnunciosApi = 0;
$impersonatedUserEmail = '';
$dashboardMessage = null;
$dashboardMessageClass = '';

try {
    $pdo = getDbConnection();
    
    // 1. Buscar dados da conexão ML do usuário que está sendo visualizado
    $stmtML = $pdo->prepare("SELECT * FROM mercadolibre_users WHERE saas_user_id = ?");
    $stmtML->execute([$saasUserIdToQuery]);
    $mlConnection = $stmtML->fetch(PDO::FETCH_ASSOC);

    if ($mlConnection) {
        // 2. Contar o total de anúncios para calcular as páginas
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM anuncios WHERE saas_user_id = ?");
        $countStmt->execute([$saasUserIdToQuery]);
        $totalAnunciosNoDb = (int) $countStmt->fetchColumn();
        
        if ($totalAnunciosNoDb > 0) {
            $totalPages = ceil($totalAnunciosNoDb / $itemsPerPage);
            if ($currentPage > $totalPages) {
                $currentPage = $totalPages;
            }
        } else {
            $totalPages = 1;
        }
        $offset = ($currentPage - 1) * $itemsPerPage;

        // 3. Busca apenas os anúncios da página atual
        $stmtAnuncios = $pdo->prepare("SELECT * FROM anuncios WHERE saas_user_id = :saas_user_id ORDER BY total_sales DESC LIMIT :limit OFFSET :offset");
        $stmtAnuncios->bindParam(':saas_user_id', $saasUserIdToQuery, PDO::PARAM_INT);
        $stmtAnuncios->bindParam(':limit', $itemsPerPage, PDO::PARAM_INT);
        $stmtAnuncios->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmtAnuncios->execute();
        $anuncios = $stmtAnuncios->fetchAll(PDO::FETCH_ASSOC);

        // 4. Extrai o total de anúncios da mensagem de status para a barra de progresso
        if (preg_match('/de (\d+)/', $mlConnection['sync_last_message'] ?? '', $matches)) {
            $totalAnunciosApi = (int) $matches[1];
        } else if ($mlConnection['sync_status'] === 'COMPLETED' && preg_match('/Total de (\d+)/', $mlConnection['sync_last_message'] ?? '', $matches)) {
            $totalAnunciosApi = (int) $matches[1];
        }
        if ($totalAnunciosApi > 0) {
            $progresso = round(($totalAnunciosNoDb / $totalAnunciosApi) * 100);
        }
    }
    
    // 5. Se estiver personificando, busca o email do usuário alvo para exibir na barra
    if ($isImpersonating) {
        $stmtUser = $pdo->prepare("SELECT email FROM saas_users WHERE id = ?");
        $stmtUser->execute([$saasUserIdToQuery]);
        $impersonatedUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
        $impersonatedUserEmail = $impersonatedUser['email'] ?? 'ID: ' . $saasUserIdToQuery;
    }

} catch (Exception $e) {
    logMessage("Erro DB Dashboard v9.1 (SaaS User ID $saasUserIdToQuery): " . $e->getMessage());
    $dashboardMessage = ['type' => 'is-danger', 'text' => '⚠️ Erro ao carregar dados do dashboard.'];
}

// --- Tratamento de Mensagens de Status da URL ---
$message_classes = [
    'is-success' => 'bg-green-100 text-green-800',
    'is-danger' => 'bg-red-100 text-red-800',
    'is-info' => 'bg-blue-100 text-blue-800',
];
if (isset($_GET['status'])) {
    $status_param = $_GET['status'];
    if ($status_param === 'ml_connected') { $dashboardMessage = ['type' => 'is-success', 'text' => '✅ Conta Mercado Livre conectada! Solicite a sincronização dos anúncios abaixo.']; }
    if ($status_param === 'sync_requested') { $dashboardMessage = ['type' => 'is-info', 'text' => 'ℹ️ Sincronização solicitada! O processo ocorrerá em segundo plano. Atualize a página em alguns minutos para ver o progresso.']; }
    if ($status_param === 'ml_disconnected') { $dashboardMessage = ['type' => 'is-success', 'text' => '✅ Conta Mercado Livre desconectada com sucesso.']; }
    if ($status_param === 'disconnect_denied') { $dashboardMessage = ['type' => 'is-danger', 'text' => '❌ Ação de desconectar não é permitida durante a personificação.']; }
    if ($status_param === 'sync_error' || $status_param === 'disconnect_error') { $dashboardMessage = ['type' => 'is-danger', 'text' => '❌ Ocorreu um erro ao processar sua solicitação.']; }
    if ($status_param === 'ml_error') { $code = $_GET['code'] ?? 'unknown'; $dashboardMessage = ['type' => 'is-danger', 'text' => "❌ Erro ao conectar com Mercado Livre (Código: $code). Tente novamente."]; }
}

if ($dashboardMessage && isset($message_classes[$dashboardMessage['type']])) {
    $dashboardMessageClass = $message_classes[$dashboardMessage['type']];
}

// Limpa os parâmetros da URL para não persistirem no refresh
if (isset($_GET['status'])){ 
    echo "<script> if (history.replaceState) { setTimeout(function() { var url = new URL(window.location); url.searchParams.delete('status'); url.searchParams.delete('code'); window.history.replaceState({path:url.href}, '', url.href); }, 1); } </script>";
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Analisador ML</title>
    <?php if ($mlConnection && $mlConnection['sync_status'] === 'SYNCING'): ?>
    <meta http-equiv="refresh" content="30">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-gray-100 text-gray-900">

    <!-- BARRA DE PERSONIFICAÇÃO -->
    <?php if ($isImpersonating): ?>
        <div class="bg-yellow-400 text-black text-center p-2 font-bold sticky top-0 z-50">
            <p>
                ⚠️ Você está vendo como <strong><?php echo htmlspecialchars($impersonatedUserEmail); ?></strong>. 
                <a href="stop_impersonating.php" class="underline hover:text-blue-800">Retornar ao Painel Admin</a>
            </p>
        </div>
    <?php endif; ?>

    <section class="container mx-auto px-4 py-8">
        <header class="bg-white shadow rounded-lg p-4 mb-6 flex justify-between items-center">
            <h1 class="text-xl font-semibold">📈 Analisador de Anúncios ML</h1>
            <div>
                <span class="text-sm text-gray-600 mr-4">Olá, <?php echo htmlspecialchars($loggedInUserEmail); ?></span>
                <?php if ($isSuperAdmin && !$isImpersonating): ?>
                    <a href="super_admin.php" class="text-sm text-purple-600 hover:underline mr-4">Painel Admin</a>
                <?php endif; ?>
                <a href="logout.php" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded shadow-sm text-white bg-red-600 hover:bg-red-700">Sair</a>
            </div>
        </header>

        <?php if ($dashboardMessage): ?>
            <div class="p-4 mb-6 text-sm rounded-lg <?php echo $dashboardMessageClass; ?>" role="alert">
                <?php echo htmlspecialchars($dashboardMessage['text']); ?>
            </div>
        <?php endif; ?>

        <div class="bg-white shadow rounded-lg p-6 mb-6">
            <h2 class="text-lg font-semibold mb-4">🔗 Conexão e Sincronização</h2>
            <?php if ($mlConnection): ?>
                <div class="space-y-3 mb-4 text-sm">
                    <div><span class="font-medium text-gray-600">ID Vendedor ML:</span> <span class="ml-2 font-mono"><?php echo htmlspecialchars($mlConnection['ml_user_id']); ?></span></div>
                    
                    <?php if ($mlConnection['sync_status'] === 'SYNCING'): ?>
                        <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg">
                            <div class="flex items-center space-x-2 mb-2">
                                <svg class="animate-spin h-5 w-5 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                <span class="font-bold text-blue-800">Sincronizando...</span>
                            </div>
                            <p class="text-xs text-blue-700 mb-2"><?php echo htmlspecialchars($mlConnection['sync_last_message']); ?></p>
                            <div class="w-full bg-gray-200 rounded-full h-2.5"><div class="bg-blue-600 h-2.5 rounded-full" style="width: <?php echo $progresso; ?>%"></div></div>
                        </div>
                    <?php else: ?>
                         <div><span class="font-medium text-gray-600">Status da Sincronização:</span> <span class="ml-2 font-mono font-bold"><?php echo htmlspecialchars($mlConnection['sync_status']); ?></span></div>
                        <?php if ($mlConnection['sync_last_message']): ?>
                            <div class="text-xs text-gray-500 italic">Mensagem: <?php echo htmlspecialchars($mlConnection['sync_last_message']); ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="flex items-center space-x-4 mt-4">
                    <?php if (in_array($mlConnection['sync_status'], ['IDLE', 'COMPLETED', 'ERROR'])): ?>
                        <a href="request_sync.php" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700">🔄 Sincronizar/Atualizar Anúncios</a>
                    <?php elseif ($mlConnection['sync_status'] === 'PAUSED'): ?>
                        <a href="toggle_sync.php?action=resume" class="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700">▶️ Retomar</a>
                    <?php elseif (in_array($mlConnection['sync_status'], ['REQUESTED', 'SYNCING'])): ?>
                        <a href="toggle_sync.php?action=pause" class="px-4 py-2 text-sm font-medium text-white bg-yellow-500 rounded-md hover:bg-yellow-600">⏸️ Pausar</a>
                    <?php endif; ?>
                </div>

                <div class="border-t border-gray-200 mt-6 pt-4">
                    <h3 class="text-xs font-semibold text-gray-500 uppercase mb-3">Gerenciar Conexão</h3>
                    <div class="flex items-center space-x-4">
                        <a href="oauth_start.php" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 border border-gray-300 rounded-md hover:bg-gray-200">Reconectar Conta</a>
                        <form action="disconnect_ml.php" method="POST" onsubmit="return confirm('Tem certeza que deseja desconectar sua conta do Mercado Livre?\n\nTODOS os seus dados de anúncios sincronizados serão APAGADOS do nosso sistema.');">
                            <button type="submit" class="px-4 py-2 text-sm font-medium text-red-700 bg-red-100 border border-red-200 rounded-md hover:bg-red-200">Desconectar</button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                 <p class="mb-4 text-sm">Conecte sua conta do Mercado Livre para começar.</p>
                 <a href="oauth_start.php" class="inline-flex items-center px-4 py-2 border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">🔗 Conectar Conta</a>
            <?php endif; ?>
        </div>

        <form id="anuncios-form" action="update_selected.php" method="POST">
            <div class="bg-white shadow rounded-lg p-6">
                <div class="flex flex-wrap justify-between items-center gap-4 mb-4">
                    <h2 class="text-lg font-semibold">📊 Seus Anúncios (<?php echo $totalAnunciosNoDb; ?>)</h2>
                    <div class="flex items-center space-x-2">
                        <button type="submit" class="px-3 py-1.5 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700">Atualizar Selecionados</button>
                        <a href="download_csv.php" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-sm font-medium rounded-md">📥 Baixar CSV</a>
                    </div>
                </div>
                
                <div class="flex flex-wrap justify-between items-center gap-4 mb-4 pb-4 border-b border-gray-200">
                    <div class="flex items-center space-x-2 text-sm">
                        <span>Exibir</span>
                        <select name="limit" id="limit-select" class="p-1 rounded-md border-gray-300" onchange="window.location.href = '?page=1&limit=' + this.value;">
                            <?php foreach($itemsPerPageOptions as $option): ?>
                                <option value="<?php echo $option; ?>" <?php if($itemsPerPage == $option) echo 'selected'; ?>><?php echo $option; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span>por página</span>
                    </div>
                    <?php if ($totalPages > 1): ?>
                    <div class="flex items-center space-x-1 text-sm">
                        <a href="?page=1&limit=<?php echo $itemsPerPage; ?>" class="px-2 py-1 rounded <?php echo $currentPage == 1 ? 'bg-gray-200 text-gray-500 cursor-not-allowed' : 'bg-gray-100 hover:bg-gray-200'; ?>">«</a>
                        <a href="?page=<?php echo max(1, $currentPage - 1); ?>&limit=<?php echo $itemsPerPage; ?>" class="px-2 py-1 rounded <?php echo $currentPage == 1 ? 'bg-gray-200 text-gray-500 cursor-not-allowed' : 'bg-gray-100 hover:bg-gray-200'; ?>">‹</a>
                        <span class="px-3 py-1">Página <?php echo $currentPage; ?> de <?php echo $totalPages; ?></span>
                        <a href="?page=<?php echo min($totalPages, $currentPage + 1); ?>&limit=<?php echo $itemsPerPage; ?>" class="px-2 py-1 rounded <?php echo $currentPage >= $totalPages ? 'bg-gray-200 text-gray-500 cursor-not-allowed' : 'bg-gray-100 hover:bg-gray-200'; ?>">›</a>
                        <a href="?page=<?php echo $totalPages; ?>&limit=<?php echo $itemsPerPage; ?>" class="px-2 py-1 rounded <?php echo $currentPage >= $totalPages ? 'bg-gray-200 text-gray-500 cursor-not-allowed' : 'bg-gray-100 hover:bg-gray-200'; ?>">»</a>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 w-12"><input type="checkbox" id="select-all" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"></th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase">Anúncio</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase">Criação</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase">Visitas</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase">Vendas</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase">Última Venda</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase">Tag de Venda</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($anuncios)): ?>
                                <tr><td colspan="7" class="text-center py-10 text-gray-500">Nenhum anúncio para exibir.</td></tr>
                            <?php else: ?>
                                <?php foreach ($anuncios as $anuncio): ?>
                                    <?php
                                      $tagInfo = formatLastSaleTag(
                                          $anuncio['last_sale_date'],
                                          (int)$anuncio['total_sales'],
                                          (bool)$anuncio['is_synced']
                                      );
                                    ?>
                                    <tr class="<?php if ($anuncio['is_synced'] == 2) echo 'bg-blue-50'; ?>">
                                        <td class="px-4 py-2"><input type="checkbox" name="selected_ids[]" value="<?php echo $anuncio['ml_item_id']; ?>" class="item-checkbox h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"></td>
                                        <td class="px-4 py-2 whitespace-nowrap"><div class="text-sm font-medium truncate max-w-xs" title="<?php echo htmlspecialchars($anuncio['title'] ?? '...'); ?>"><?php echo htmlspecialchars($anuncio['title'] ?? 'Carregando...'); ?></div><div class="text-xs text-gray-500"><?php echo htmlspecialchars($anuncio['ml_item_id']); ?></div></td>
                                        <td class="px-4 py-2 text-sm"><?php echo $anuncio['date_created'] ? date('d/m/Y', strtotime($anuncio['date_created'])) : '...'; ?></td>
                                        <td class="px-4 py-2 text-sm"><?php echo $anuncio['is_synced'] ? number_format($anuncio['total_visits']) : '...'; ?></td>
                                        <td class="px-4 py-2 text-sm"><?php echo $anuncio['is_synced'] ? number_format($anuncio['total_sales']) : '...'; ?></td>
                                        <td class="px-4 py-2 text-sm"><?php echo $anuncio['last_sale_date'] ? date('d/m/Y', strtotime($anuncio['last_sale_date'])) : ($anuncio['is_synced'] ? '-' : '...'); ?></td>
                                        <td class="px-4 py-2 text-sm">
                                            <?php if (!empty($tagInfo['text'])): ?>
                                                <span class="px-2 py-0.5 text-xs font-medium rounded-full <?php echo $tagInfo['class']; ?>">
                                                    <?php echo $tagInfo['text']; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const selectAll = document.getElementById('select-all');
            const itemCheckboxes = document.querySelectorAll('.item-checkbox');
            if (selectAll) {
                selectAll.addEventListener('change', function(e) {
                    itemCheckboxes.forEach(cb => cb.checked = e.target.checked);
                });
            }
        });
    </script>
</body>
</html>


<?php
/**
 * Arquivo: db.php (Analisador de Anúncios ML)
 * Versão: v2.0 - Mantido do Meli AI, continua robusto.
 * Descrição: Funções para conexão com o banco de dados e criptografia segura.
 */

require_once __DIR__ . '/config.php';

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Defuse\Crypto\Exception as DefuseException;

function getDbConnection(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (\PDOException $e) {
            error_log("FATAL DB Connection Error: " . $e->getMessage());
            throw new \PDOException("Falha crítica na conexão com o banco de dados.", (int)$e->getCode());
        }
    }
    return $pdo;
}

function loadEncryptionKey(): Key
{
    global $secrets;
    static $loadedKey = null;
    if ($loadedKey === null) {
        $keyAscii = $secrets['APP_ENCRYPTION_KEY'] ?? null;
        if (empty($keyAscii)) {
            throw new Exception('Chave de criptografia essencial não configurada (SEC10).');
        }
        try {
            $loadedKey = Key::loadFromAsciiSafeString($keyAscii);
        } catch (DefuseException\BadFormatException $e) {
            throw new Exception('Chave de criptografia com formato inválido (SEC11).');
        }
    }
    return $loadedKey;
}

function encryptData(string $data): string
{
    try {
        $key = loadEncryptionKey();
        return Crypto::encrypt($data, $key);
    } catch (\Throwable $e) {
        error_log("ERRO Criptografia: " . $e->getMessage());
        throw new Exception("Encryption failed: " . $e->getMessage());
    }
}

function decryptData(string $encryptedData): string
{
    try {
        $key = loadEncryptionKey();
        return Crypto::decrypt($encryptedData, $key);
    } catch (\Throwable $e) {
        error_log("ERRO Descriptografia: " . $e->getMessage());
        throw new Exception("Decryption failed: " . $e->getMessage());
    }
}
?>

<?php
/**
 * Arquivo: disconnect_ml.php
 * Versão: v1.0
 * Descrição: Desconecta a conta do Mercado Livre do usuário SaaS logado.
 *            - Remove a conexão da tabela 'mercadolibre_users'.
 *            - Remove os anúncios associados da tabela 'anuncios' (via ON DELETE CASCADE).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/log_helper.php';

// --- Proteção de Acesso ---
if (!isset($_SESSION['saas_user_id'])) {
    header('Location: login.php?error=unauthorized');
    exit;
}

// Se o admin estiver personificando, impede a desconexão para evitar acidentes.
if (isset($_SESSION['impersonating_user_id'])) {
    header('Location: dashboard.php?status=disconnect_denied');
    exit;
}

$saasUserId = $_SESSION['saas_user_id'];

// Apenas executa se o método for POST para segurança adicional
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = getDbConnection();
        $pdo->beginTransaction();

        // 1. Deleta a conexão da tabela `mercadolibre_users`.
        // A chave estrangeira com ON DELETE CASCADE na tabela `anuncios` garantirá
        // que todos os anúncios desse usuário também sejam removidos.
        $stmt = $pdo->prepare("DELETE FROM mercadolibre_users WHERE saas_user_id = ?");
        $stmt->execute([$saasUserId]);
        
        $rowCount = $stmt->rowCount();

        $pdo->commit();
        
        if ($rowCount > 0) {
            logMessage("[DISCONNECT] Conta ML desconectada com sucesso para SaaS User ID: $saasUserId");
            header('Location: dashboard.php?status=ml_disconnected');
        } else {
            logMessage("[DISCONNECT] Nenhuma conta ML para desconectar encontrada para SaaS User ID: $saasUserId");
            header('Location: dashboard.php?status=no_action_needed');
        }
        exit;

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        logMessage("[DISCONNECT] ERRO ao desconectar conta para SaaS User ID $saasUserId: " . $e->getMessage());
        header('Location: dashboard.php?status=disconnect_error');
        exit;
    }
} else {
    // Se o acesso não for via POST, apenas redireciona
    header('Location: dashboard.php');
    exit;
}
?>



<!-- ARQUIVO: download_csv.php -->
<?php
/**
 * Arquivo: download_csv.php
 * Descrição: Gera e força o download de um arquivo CSV com os dados dos anúncios.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['saas_user_id'])) {
    http_response_code(403);
    exit("Acesso negado.");
}
$saasUserId = $_SESSION['saas_user_id'];

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT * FROM anuncios WHERE saas_user_id = ? AND is_synced = 1 ORDER BY total_sales DESC");
    $stmt->execute([$saasUserId]);
    $anuncios = $stmt->fetchAll();

    if (empty($anuncios)) { exit("Nenhum anúncio sincronizado para exportar."); }

    $filename = "analise_anuncios_ml_" . date('Ymd') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID Anuncio (MLB)', 'Titulo', 'Data Criacao', 'Visitas', 'Vendas Totais', 'Data Ultima Venda', 'Tag']);

    foreach ($anuncios as $anuncio) {
        $tag = '';
        if ($anuncio['last_sale_date']) {
            if (new DateTime($anuncio['last_sale_date']) < (new DateTime())->modify('-30 days')) {
                $tag = 'Sem Venda >30d';
            }
        } elseif ($anuncio['total_sales'] == 0) {
            $tag = 'Nunca Vendeu';
        }

        fputcsv($output, [
            $anuncio['ml_item_id'],
            $anuncio['title'],
            date('d/m/Y H:i', strtotime($anuncio['date_created'])),
            $anuncio['total_visits'],
            $anuncio['total_sales'],
            $anuncio['last_sale_date'] ? date('d/m/Y H:i', strtotime($anuncio['last_sale_date'])) : '',
            $tag
        ]);
    }

    fclose($output);
    exit;

} catch (\Exception $e) {
    http_response_code(500);
    exit("Erro ao gerar o arquivo: " . $e->getMessage());
}
?>

<?php
/**
 * Arquivo: impersonate.php
 * Versão: v1.0
 * Descrição: Permite que um Super Admin visualize o sistema como outro usuário.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// --- Proteção de Acesso ---
if (!isset($_SESSION['saas_user_id']) || !isset($_SESSION['is_super_admin']) || !$_SESSION['is_super_admin']) {
    // Se o usuário atual não é um super admin logado, nega o acesso.
    header('Location: login.php?error=unauthorized');
    exit;
}

$targetUserId = filter_input(INPUT_GET, 'target_user_id', FILTER_VALIDATE_INT);

if (!$targetUserId) {
    // Se o ID do alvo for inválido, volta para o painel de admin.
    header('Location: super_admin.php');
    exit;
}

// Define uma variável de sessão especial para indicar a personificação.
$_SESSION['impersonating_user_id'] = $targetUserId;
$_SESSION['original_admin_id'] = $_SESSION['saas_user_id']; // Guarda o ID original do admin

// Redireciona para o dashboard, que agora mostrará os dados do usuário alvo.
header('Location: dashboard.php');
exit;
?>

<?php
/**
 * Arquivo: index.php (Analisador de Anúncios ML)
 * Descrição: Página inicial/landing page.
 */
require_once __DIR__ . '/config.php';

if (isset($_SESSION['saas_user_id'])) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analisador de Anúncios ML</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 flex flex-col min-h-screen">
    <main class="main-content flex items-center justify-center">
        <div class="max-w-xl w-full space-y-8 text-center p-8">
            <h1 class="text-4xl font-extrabold text-gray-900 dark:text-white sm:text-5xl">
                📈 Analisador de Anúncios ML
            </h1>
            <p class="mt-4 text-xl text-gray-500 dark:text-gray-400">
                Obtenha insights valiosos sobre seus anúncios do Mercado Livre para otimizar suas vendas.
            </p>
            <div class="mt-10 flex flex-col sm:flex-row sm:justify-center space-y-4 sm:space-y-0 sm:space-x-4">
                <a href="login.php" class="w-full sm:w-auto inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-lg shadow-sm text-white bg-blue-600 hover:bg-blue-700">
                    Acessar Painel
                </a>
                <a href="register.php" class="w-full sm:w-auto inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-lg text-blue-700 bg-blue-100 hover:bg-blue-200">
                    Criar Conta
                </a>
            </div>
        </div>
    </main>
    <footer class="py-6 text-center">
        <p class="text-sm text-gray-500 dark:text-gray-400">© <?php echo date('Y'); ?></p>
    </footer>
</body>
</html>

<?php
/**
 * Arquivo: login.php (Analisador de Anúncios ML)
 * Versão: v2.0 - Adiciona a flag is_super_admin à sessão.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/log_helper.php';

if (isset($_SESSION['saas_user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$errors = [];
$email_value = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $email_value = $_POST['email'] ?? '';

    if (empty($errors)) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT id, email, password_hash, is_super_admin FROM saas_users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['saas_user_id'] = $user['id'];
                $_SESSION['saas_user_email'] = $user['email'];
                // Salva na sessão se o usuário é admin ou não
                $_SESSION['is_super_admin'] = (bool) $user['is_super_admin'];
                header('Location: dashboard.php');
                exit;
            } else {
                $errors[] = "E-mail ou senha incorretos.";
            }
        } catch (\Exception $e) {
            logMessage("Erro DB login: " . $e->getMessage());
            $errors[] = "Erro interno do servidor. Tente novamente.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Analisador de Anúncios ML</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-gray-50 dark:bg-gray-900">
    <section class="flex flex-col items-center justify-center min-h-screen py-12 px-4">
        <div class="max-w-md w-full bg-white dark:bg-gray-800 shadow-md rounded-lg p-8 space-y-6">
            <h1 class="text-3xl font-bold text-center text-gray-900 dark:text-white">Acessar Painel</h1>
            <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md text-sm" role="alert">
                    <ul class="list-disc list-inside">
                        <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <form action="login.php" method="POST" class="space-y-6">
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">E-mail</label>
                    <input class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white" type="email" id="email" name="email" required value="<?php echo htmlspecialchars($email_value); ?>">
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Senha</label>
                    <input class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white" type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-base font-medium text-white bg-blue-600 hover:bg-blue-700">Entrar</button>
            </form>
            <p class="text-sm text-center text-gray-500 dark:text-gray-400">
                 Não tem uma conta? <a href="register.php" class="font-medium text-blue-600 hover:text-blue-500">Cadastre-se aqui</a>.
            </p>
        </div>
    </section>
</body>
</html>

<?php
/**
 * Arquivo: logout.php
 * Descrição: Destrói a sessão do usuário e redireciona para o login.
 */
require_once __DIR__ . '/config.php';
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
header("Location: login.php");
exit;
?>


<?php
/**
 * Arquivo: oauth_callback.php
 * Descrição: Recebe o retorno do ML, troca o código por tokens e salva no DB.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/log_helper.php';
require_once __DIR__ . '/includes/curl_helper.php';

if (!isset($_SESSION['saas_user_id'])) {
    header('Location: login.php?error=session_expired');
    exit;
}
$saasUserId = $_SESSION['saas_user_id'];

// Validação de Segurança (CSRF)
if (empty($_GET['state']) || !isset($_SESSION['oauth_state_expected']) || !hash_equals($_SESSION['oauth_state_expected'], $_GET['state'])) {
    logMessage("Erro Callback CSRF: Estado OAuth inválido para SaaS User ID $saasUserId.");
    header('Location: dashboard.php?status=ml_error&code=csrf_failed');
    exit;
}
unset($_SESSION['oauth_state_expected']);

if (empty($_GET['code'])) {
    logMessage("Erro Callback: Código de autorização não recebido do ML.");
    header('Location: dashboard.php?status=ml_error&code=no_code');
    exit;
}

// Troca código por tokens
$postData = [
    'grant_type' => 'authorization_code',
    'code' => $_GET['code'],
    'client_id' => ML_APP_ID,
    'client_secret' => ML_SECRET_KEY,
    'redirect_uri' => ML_REDIRECT_URI
];
$result = makeCurlRequest(ML_TOKEN_URL, 'POST', ['Accept: application/json'], $postData, false);

if ($result['httpCode'] != 200 || !$result['is_json'] || !isset($result['response']['access_token'])) {
    logMessage("Erro Callback: Falha ao obter tokens do ML. HTTP: {$result['httpCode']}. Resp: " . json_encode($result['response']));
    header('Location: dashboard.php?status=ml_error&code=token_fetch_failed');
    exit;
}

$tokenData = $result['response'];
$accessToken = $tokenData['access_token'];
$refreshToken = $tokenData['refresh_token'];
$mlUserId = $tokenData['user_id'];
$expiresIn = $tokenData['expires_in'] ?? 21600;
$tokenExpiresAt = (new DateTimeImmutable())->modify("+" . (int)$expiresIn . " seconds")->format('Y-m-d H:i:s');

try {
    $encryptedAccessToken = encryptData($accessToken);
    $encryptedRefreshToken = encryptData($refreshToken);

    $pdo = getDbConnection();
    $sql = "INSERT INTO mercadolibre_users (saas_user_id, ml_user_id, access_token, refresh_token, token_expires_at, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, TRUE, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                access_token = VALUES(access_token),
                refresh_token = VALUES(refresh_token),
                token_expires_at = VALUES(token_expires_at),
                is_active = TRUE, updated_at = NOW()";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$saasUserId, $mlUserId, $encryptedAccessToken, $encryptedRefreshToken, $tokenExpiresAt]);

    logMessage("Callback: Tokens salvos/atualizados para ML ID: $mlUserId (SaaS ID: $saasUserId)");
    header('Location: dashboard.php?status=ml_connected');
    exit;

} catch (\Exception $e) {
    logMessage("Erro Callback DB/Cripto: " . $e->getMessage());
    header('Location: dashboard.php?status=ml_error&code=db_save_failed');
    exit;
}
?>

<?php
/**
 * Arquivo: oauth_start.php
 * Descrição: Inicia o fluxo de autorização OAuth2 do Mercado Livre.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/log_helper.php';

if (!isset($_SESSION['saas_user_id'])) {
    header('Location: login.php?error=unauthorized');
    exit;
}
$saasUserId = $_SESSION['saas_user_id'];
logMessage("[OAuth Start] Iniciando fluxo para SaaS User ID: $saasUserId");

try {
    $state = bin2hex(random_bytes(16));
} catch (Exception $e) {
    logMessage("OAuth Start ERRO: Falha ao gerar state. " . $e->getMessage());
    header('Location: dashboard.php?status=ml_error&code=state_gen_failed');
    exit;
}
$_SESSION['oauth_state_expected'] = $state;

$authParams = [
    'response_type' => 'code',
    'client_id'     => ML_APP_ID,
    'redirect_uri'  => ML_REDIRECT_URI,
    'state'         => $state
];
$authUrl = ML_AUTH_URL . '?' . http_build_query($authParams);

header('Location: ' . $authUrl);
exit;
?>



<?php
/**
 * Arquivo: register.php (Analisador de Anúncios ML)
 * Descrição: Página de cadastro de usuário. Simplificada, sem Asaas.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/log_helper.php';

if (isset($_SESSION['saas_user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$errors = [];
$formData = ['email' => $_POST['email'] ?? ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Formato de e-mail inválido.";
    if (strlen($password) < 8) $errors[] = "Senha deve ter no mínimo 8 caracteres.";
    if ($password !== $password_confirm) $errors[] = "As senhas não coincidem.";

    if (empty($errors)) {
        try {
            $pdo = getDbConnection();
            $stmtCheck = $pdo->prepare("SELECT id FROM saas_users WHERE email = :email");
            $stmtCheck->execute([':email' => $email]);
            if ($stmtCheck->fetch()) {
                $errors[] = "Este e-mail já está cadastrado. Tente fazer login.";
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $sqlInsert = "INSERT INTO saas_users (email, password_hash, created_at, updated_at) VALUES (:email, :pwd, NOW(), NOW())";
                $stmtInsert = $pdo->prepare($sqlInsert);
                $stmtInsert->execute([':email' => $email, ':pwd' => $password_hash]);
                $newUserId = $pdo->lastInsertId();

                // Auto-login após cadastro
                session_regenerate_id(true);
                $_SESSION['saas_user_id'] = $newUserId;
                $_SESSION['saas_user_email'] = $email;
                header('Location: dashboard.php');
                exit;
            }
        } catch (\Exception $e) {
            logMessage("Erro DB registro: " . $e->getMessage());
            $errors[] = "Erro interno ao criar conta. Tente novamente.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro - Analisador de Anúncios ML</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-gray-50 dark:bg-gray-900">
    <section class="flex flex-col items-center justify-center min-h-screen py-12 px-4">
        <div class="max-w-md w-full bg-white dark:bg-gray-800 shadow-md rounded-lg p-8 space-y-6">
            <h1 class="text-3xl font-bold text-center text-gray-900 dark:text-white">Criar Conta</h1>
            <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md text-sm" role="alert">
                    <ul class="list-disc list-inside">
                        <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <form action="register.php" method="POST" class="space-y-6">
                 <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">E-mail</label>
                    <input class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white" type="email" id="email" name="email" required value="<?php echo htmlspecialchars($formData['email']); ?>">
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Senha</label>
                    <input class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white" type="password" id="password" name="password" required placeholder="Mínimo 8 caracteres">
                </div>
                <div>
                    <label for="password_confirm" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Confirmar Senha</label>
                    <input class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white" type="password" id="password_confirm" name="password_confirm" required>
                </div>
                <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-base font-medium text-white bg-blue-600 hover:bg-blue-700">Criar Conta</button>
            </form>
            <p class="text-sm text-center text-gray-500 dark:text-gray-400">
                 Já tem uma conta? <a href="login.php" class="font-medium text-blue-600 hover:text-blue-500">Faça login</a>.
            </p>
        </div>
    </section>
</body>
</html>


<?php
/**
 * Arquivo: request_sync.php
 * Versão: v2.0 - Corrigida lógica de personificação.
 * Descrição: Solicita o início de uma sincronização completa para o usuário correto.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/log_helper.php';

if (!isset($_SESSION['saas_user_id'])) {
    header('Location: login.php?error=unauthorized');
    exit;
}

// --- LÓGICA DE PERSONIFICAÇÃO CORRIGIDA ---
$isImpersonating = isset($_SESSION['impersonating_user_id']);
// A ação deve ser executada no usuário que está sendo visualizado.
$saasUserIdToActOn = $isImpersonating ? $_SESSION['impersonating_user_id'] : $_SESSION['saas_user_id'];

try {
    $pdo = getDbConnection();
    // Muda o status para 'REQUESTED' e limpa o scroll_id para forçar um reinício completo.
    $sql = "UPDATE mercadolibre_users SET sync_status = 'REQUESTED', sync_last_message = 'Solicitação recebida, aguardando início...', sync_scroll_id = NULL WHERE saas_user_id = ? AND sync_status NOT IN ('SYNCING', 'REQUESTED')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$saasUserIdToActOn]);

    logMessage("[REQUEST_SYNC] Solicitação feita para SaaS User ID: $saasUserIdToActOn (solicitado por admin: {$_SESSION['saas_user_id']})");

} catch (Exception $e) {
    logMessage("[REQUEST_SYNC] Erro DB ao solicitar sync para SaaS User ID $saasUserIdToActOn: " . $e->getMessage());
    header('Location: dashboard.php?status=sync_error');
    exit;
}

header('Location: dashboard.php');
exit;
?>

<?php
/**
 * Arquivo: stop_impersonating.php
 * Versão: v1.0
 * Descrição: Remove a sessão de personificação e retorna o admin à sua visão normal.
 */
require_once __DIR__ . '/config.php';

// Verifica se o usuário estava realmente personificando alguém
if (isset($_SESSION['impersonating_user_id']) && isset($_SESSION['original_admin_id'])) {
    // Remove as variáveis de sessão da personificação
    unset($_SESSION['impersonating_user_id']);
}

// Redireciona de volta para o painel de super admin
header('Location: super_admin.php');
exit;
?>

/**
 * Arquivo: style.css
 * DescriÃ§Ã£o: Estilos base para a aplicaÃ§Ã£o.
 */
html {
  scroll-behavior: smooth;
}
body {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}
.main-content {
    flex-grow: 1;
}




<?php
/**
 * Arquivo: sync_anuncios.php
 * Versão: v6.1 - Correção definitiva no processamento de lotes de detalhamento.
 *
 * Descrição:
 * - Script CRON com lógica de fases para sincronização de anúncios.
 * - FASE 1 (Sprint de IDs): Em uma única execução, busca continuamente todos os IDs de anúncios
 *   de um usuário usando o método `scroll_id` da API, que é ideal para contas grandes.
 *   Salva apenas os IDs no banco de dados.
 * - FASE 2 (Detalhamento em Lote): Após obter todos os IDs, o script passa a detalhar
 *   os anúncios em lotes. Ele busca um grande número de anúncios não sincronizados
 *   do banco e usa chamadas individuais para cada um para garantir a integridade dos dados.
 * - PRIORIDADE: Antes de tudo, o script verifica se há anúncios marcados para
 *   atualização seletiva e os processa primeiro.
 * - PREVENÇÃO DE CONCORRÊNCIA: Usa um sistema de file lock para garantir que
 *   apenas uma instância deste script rode por vez.
 */

// --- Prevenção de Concorrência (File Lock) ---
$lockFilePath = sys_get_temp_dir() . '/sync_anuncios.lock';
$lockFileHandle = fopen($lockFilePath, 'c');
if ($lockFileHandle === false) {
    // Não foi possível criar o arquivo de lock. Logar um erro seria ideal em produção.
    exit; 
}
if (!flock($lockFileHandle, LOCK_EX | LOCK_NB)) {
    // Outro processo já está rodando. Isso é normal, apenas sai silenciosamente.
    fclose($lockFileHandle);
    exit;
}

// --- Configurações e Includes ---
set_time_limit(1800); // 30 minutos de tempo máximo de execução
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/log_helper.php';
require_once __DIR__ . '/includes/ml_api.php';

define('API_CALL_DELAY_MS', 250);
define('SCROLL_PAGE_SIZE', 100); // Limite máximo da API para busca de IDs
define('MAX_SCROLL_ITERATIONS', 250); // Disjuntor de segurança (250 * 100 = 25.000 IDs por ciclo de CRON)

logMessage("==== [CRON SYNC_ANUNCIOS v6.1 - Correção Final] Iniciando ciclo ====");

$pdo = null;
$connId = null;

try {
    $pdo = getDbConnection();
    $globalNow = new DateTimeImmutable();

    // 1. Buscar Próximo Usuário da Fila
    $sql_conn = "SELECT mlu.* FROM mercadolibre_users mlu
                 WHERE mlu.sync_status IN ('REQUESTED', 'SYNCING') AND mlu.is_active = TRUE
                 ORDER BY mlu.sync_last_run_at ASC, mlu.updated_at ASC
                 LIMIT 1";
    $stmt_conn = $pdo->query($sql_conn);
    $connection = $stmt_conn->fetch(PDO::FETCH_ASSOC);

    if (!$connection) {
        throw new Exception("Fila vazia. Nenhum usuário para sincronizar.", 1); // Código de saída limpa
    }

    $connId = $connection['id'];
    $mlUserId = $connection['ml_user_id'];
    $saasUserId = $connection['saas_user_id'];
    $currentStatus = $connection['sync_status'];
    $itemsToProcessPerCycle = (int)($connection['batch_size'] ?? 100);
    if ($itemsToProcessPerCycle <= 0) $itemsToProcessPerCycle = 100;
    
    $pdo->prepare("UPDATE mercadolibre_users SET sync_status = 'SYNCING', sync_last_run_at = NOW() WHERE id = ?")->execute([$connId]);
    logMessage("--> [ML $mlUserId] Processando. Lote de detalhamento: $itemsToProcessPerCycle. Status inicial: $currentStatus.");

    // 2. Obtenção e Renovação Centralizada do Access Token
    $currentAccessToken = '';
    try {
        logMessage("    [ML $mlUserId] Validando token...");
        $tokenExpiresAt = new DateTimeImmutable($connection['token_expires_at']);
        if ($globalNow >= $tokenExpiresAt->modify("-10 minutes")) {
            logMessage("    [ML $mlUserId] TOKEN EXPIRANDO. Iniciando renovação...");
            $decryptedRefreshToken = decryptData($connection['refresh_token']);
            $refreshResult = refreshMercadoLibreToken($decryptedRefreshToken);
            if ($refreshResult['httpCode'] == 200 && !empty($refreshResult['response']['access_token'])) {
                $newData = $refreshResult['response'];
                $currentAccessToken = $newData['access_token'];
                $newRefreshToken = $newData['refresh_token'] ?? $decryptedRefreshToken;
                $newExpAt = $globalNow->modify("+" . (int)($newData['expires_in'] ?? 21600) . " seconds")->format('Y-m-d H:i:s');
                $encAT = encryptData($currentAccessToken); $encRT = encryptData($newRefreshToken);
                $pdo->prepare("UPDATE mercadolibre_users SET access_token=?, refresh_token=?, token_expires_at=? WHERE id=?")->execute([$encAT, $encRT, $newExpAt, $connId]);
                logMessage("    [ML $mlUserId] SUCESSO: Token renovado e salvo.");
            } else { throw new Exception("Falha na API ao renovar token. HTTP: " . ($refreshResult['httpCode'] ?? 'N/A')); }
        } else {
            $currentAccessToken = decryptData($connection['access_token']);
            logMessage("    [ML $mlUserId] Token ainda válido.");
        }
    } catch (Exception $e) {
        throw new Exception("Erro crítico de autenticação: " . $e->getMessage());
    }
    
    $headers = ['Authorization: Bearer ' . $currentAccessToken];

    // --- Função interna para processar o detalhamento de um lote de IDs de forma segura ---
    function processItemDetails(array $itemIdsToProcess, PDO $pdo, int $saasUserId, int $mlUserId, string $accessToken, array $headers) {
        if (empty($itemIdsToProcess)) {
            return;
        }

        logMessage("      Processando detalhes para " . count($itemIdsToProcess) . " itens...");

        foreach ($itemIdsToProcess as $itemId) {
            try {
                // Valida o ID antes de qualquer chamada à API
                if (empty($itemId) || !is_string($itemId)) {
                    logMessage("      [Item Lote] ID de item inválido (não é string ou está vazio) encontrado no lote. Pulando.");
                    continue;
                }
                
                // Busca os detalhes individualmente para garantir a integridade e correção dos dados
                $itemDetails = getMercadoLibreItemDetails($itemId, $accessToken);
                usleep(API_CALL_DELAY_MS * 1000);
                
                if ($itemDetails['httpCode'] != 200 || empty($itemDetails['response'])) {
                    logMessage("      [Item $itemId] Falha ao buscar detalhes principais. HTTP: {$itemDetails['httpCode']}. Marcando como sincronizado para não tentar novamente.");
                    // Marca como sincronizado para não ficar em loop infinito se o item foi removido do ML
                    $pdo->prepare("UPDATE anuncios SET is_synced = 1, title = '[Item não encontrado ou removido]' WHERE saas_user_id = ? AND ml_item_id = ?")->execute([$saasUserId, $itemId]);
                    continue;
                }
                $itemData = $itemDetails['response'];

                $visitsResult = getMercadoLibreItemVisits($itemId, $accessToken);
                usleep(API_CALL_DELAY_MS * 1000);
                
                $last_sale_date = null;
                if (($itemData['sold_quantity'] ?? 0) > 0) {
                    $orderUrl = "https://api.mercadolibre.com/orders/search?seller={$mlUserId}&item={$itemId}&sort=date_desc&limit=1";
                    $orderResult = makeCurlRequest($orderUrl, 'GET', $headers);
                    if ($orderResult['httpCode'] == 200 && !empty($orderResult['response']['results'])) {
                        $last_sale_date = (new DateTime($orderResult['response']['results'][0]['date_closed']))->format('Y-m-d H:i:s');
                    }
                    usleep(API_CALL_DELAY_MS * 1000);
                }

                $sqlUpsert = "UPDATE anuncios SET title = ?, date_created = ?, total_visits = ?, total_sales = ?, last_sale_date = ?, is_synced = 1 WHERE saas_user_id = ? AND ml_item_id = ?";
                $pdo->prepare($sqlUpsert)->execute([
                    $itemData['title'] ?? 'N/A',
                    isset($itemData['date_created']) ? (new DateTime($itemData['date_created']))->format('Y-m-d H:i:s') : null,
                    $visitsResult['response']['total_visits'] ?? 0,
                    $itemData['sold_quantity'] ?? 0,
                    $last_sale_date,
                    $saasUserId,
                    $itemId
                ]);

            } catch (Exception $detailError) {
                logMessage("      [Item $itemId] ERRO no detalhamento individual: " . $detailError->getMessage());
            }
        }
    }


    // 3. PRIORIDADE 1: ATUALIZAÇÃO SELETIVA
    logMessage("    [ML $mlUserId] Etapa 1: Verificando por anúncios com atualização solicitada (is_synced = 2)...");
    $stmtToUpdate = $pdo->prepare("SELECT ml_item_id FROM anuncios WHERE saas_user_id = ? AND is_synced = 2 LIMIT " . $itemsToProcessPerCycle);
    $stmtToUpdate->execute([$saasUserId]);
    $itemsToUpdate = $stmtToUpdate->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($itemsToUpdate)) {
        $countToUpdate = count($itemsToUpdate);
        logMessage("    [ML $mlUserId] Encontrados $countToUpdate anúncios para atualização seletiva. Processando...");
        $pdo->prepare("UPDATE mercadolibre_users SET sync_last_message = 'Atualizando $countToUpdate anúncios selecionados...' WHERE id = ?")->execute([$connId]);
        processItemDetails($itemsToUpdate, $pdo, $saasUserId, $mlUserId, $currentAccessToken, $headers);
        throw new Exception("Lote de atualização seletiva processado. Continuando na próxima execução.", 4);
    }
    
    logMessage("    [ML $mlUserId] Nenhum anúncio para atualização seletiva. Prosseguindo...");


    // 4. PRIORIDADE 2: SINCRONIZAÇÃO NORMAL (Lógica de Fases)
    $isFirstRun = ($currentStatus === 'REQUESTED');
    $scrollId = $connection['sync_scroll_id'];
    $isFetchingIds = !empty($scrollId) || $isFirstRun;

    // FASE 1: "SPRINT" PARA BUSCAR TODOS OS IDs
    if ($isFetchingIds) {
        if ($isFirstRun) {
            logMessage("    [ML $mlUserId] FASE 1 (SPRINT): Primeira execução. Limpando dados antigos...");
            $pdo->prepare("UPDATE mercadolibre_users SET sync_scroll_id = NULL WHERE id = ?")->execute([$connId]);
            $pdo->prepare("DELETE FROM anuncios WHERE saas_user_id = ?")->execute([$saasUserId]);
            $scrollId = null; 
        }

        $iterationCount = 0;
        $totalIdsFetchedThisRun = (int)$pdo->query("SELECT COUNT(*) FROM anuncios WHERE saas_user_id = {$saasUserId}")->fetchColumn();
        
        while ($iterationCount < MAX_SCROLL_ITERATIONS) {
            $iterationCount++;

            $url = "https://api.mercadolibre.com/users/{$mlUserId}/items/search?search_type=scan&limit=" . SCROLL_PAGE_SIZE;
            if (!empty($scrollId)) {
                $url = "https://api.mercadolibre.com/users/{$mlUserId}/items/search?search_type=scan&scroll_id=" . urlencode($scrollId);
            }

            logMessage("    [ML $mlUserId] FASE 1 (SPRINT): Buscando página #$iterationCount...");
            $resultItems = makeCurlRequest($url, 'GET', $headers);
            usleep(API_CALL_DELAY_MS * 1000);

            if ($resultItems['httpCode'] != 200) { throw new Exception("Falha na chamada à API de itens (Fase 1). HTTP: {$resultItems['httpCode']}."); }
            
            $itemIds = $resultItems['response']['results'] ?? [];
            $newScrollId = $resultItems['response']['scroll_id'] ?? null;
            $scrollId = $newScrollId;

            if (empty($itemIds)) {
                logMessage("    [ML $mlUserId] FASE 1 (SPRINT) CONCLUÍDA: Todos os " . $totalIdsFetchedThisRun . " IDs foram buscados.");
                $pdo->prepare("UPDATE mercadolibre_users SET sync_scroll_id = NULL, sync_last_message = 'Lista de anúncios obtida. Iniciando detalhamento...' WHERE id = ?")->execute([$connId]);
                $isFetchingIds = false;
                break; 
            }

            $sqlInsertIgnore = "INSERT IGNORE INTO anuncios (saas_user_id, ml_item_id) VALUES (?, ?)";
            $stmtInsert = $pdo->prepare($sqlInsertIgnore);
            foreach ($itemIds as $itemId) {
                if (!empty($itemId)) {
                    $stmtInsert->execute([$saasUserId, $itemId]);
                }
            }
            $totalIdsFetchedThisRun += count($itemIds);
            
            $totalApi = $resultItems['response']['paging']['total'] ?? 0;
            $progressMessage = "Buscando lista de anúncios... {$totalIdsFetchedThisRun} de {$totalApi} IDs encontrados.";
            $pdo->prepare("UPDATE mercadolibre_users SET sync_last_message = ?, sync_scroll_id = ? WHERE id = ?")->execute([$progressMessage, $scrollId, $connId]);
        }

        if ($isFetchingIds) {
            logMessage("    [ML $mlUserId] FASE 1 (SPRINT): Limite de iterações atingido. Continuará na próxima execução.");
            throw new Exception("Sprint de IDs pausado para segurança. Continuando no próximo ciclo.", 5);
        }
    }

    // FASE 2: DETALHAR OS ANÚNCIOS JÁ SALVOS
    logMessage("    [ML $mlUserId] FASE 2: Detalhando anúncios com is_synced = 0...");
    $stmtToDetail = $pdo->prepare("SELECT ml_item_id FROM anuncios WHERE saas_user_id = ? AND is_synced = 0 LIMIT " . $itemsToProcessPerCycle);
    $stmtToDetail->execute([$saasUserId]);
    $itemsToDetail = $stmtToDetail->fetchAll(PDO::FETCH_COLUMN);

    if (empty($itemsToDetail)) {
        logMessage("    [ML $mlUserId] FASE 2 CONCLUÍDA: Nenhum anúncio para detalhar. Sincronização finalizada.");
        $totalFinal = (int)$pdo->query("SELECT COUNT(*) FROM anuncios WHERE saas_user_id = $saasUserId")->fetchColumn();
        $pdo->prepare("UPDATE mercadolibre_users SET sync_status='COMPLETED', sync_last_message=? WHERE id=?")->execute(["Sincronização concluída. Total de $totalFinal anúncios.", $connId]);
        throw new Exception("Sincronização finalizada com sucesso.", 3);
    }
    
    $totalAnunciosNoDb = (int) $pdo->query("SELECT COUNT(*) FROM anuncios WHERE saas_user_id = $saasUserId")->fetchColumn();
    $totalSynced = (int) $pdo->query("SELECT COUNT(*) FROM anuncios WHERE saas_user_id = $saasUserId AND is_synced = 1")->fetchColumn();
    $progressMessage = "Detalhando... Anúncios processados: " . ($totalSynced + count($itemsToDetail)) . " de " . $totalAnunciosNoDb;
    $pdo->prepare("UPDATE mercadolibre_users SET sync_last_message = ? WHERE id = ?")->execute([$progressMessage, $connId]);
    logMessage("    [ML $mlUserId] $progressMessage");
    
    processItemDetails($itemsToDetail, $pdo, $saasUserId, $mlUserId, $currentAccessToken, $headers);

} catch (\Throwable $e) {
    if (in_array($e->getCode(), [1, 2, 3, 4, 5])) { 
        logMessage($e->getMessage());
    } else {
        $errorMessage = "!!!! ERRO FATAL CRON SYNC_ANUNCIOS: " . $e->getMessage();
        logMessage($errorMessage);
        if (isset($connId) && $pdo) {
             $pdo->prepare("UPDATE mercadolibre_users SET sync_status='ERROR', sync_scroll_id=NULL, sync_last_message=? WHERE id=?")->execute(['Erro fatal no script: ' . substr($e->getMessage(), 0, 240), $connId]);
        }
    }
}

logMessage("==== [CRON SYNC_ANUNCIOS v6.1 - Correção Final] Ciclo finalizado ====\n");

flock($lockFileHandle, LOCK_UN);
fclose($lockFileHandle);
?>



<?php
/**
 * Arquivo: super_admin.php
 * Versão: v2.1 - Ajusta opções de lote para refletir o limite da API (100).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/log_helper.php';

// --- Proteção de Acesso ---
if (!isset($_SESSION['saas_user_id']) || !isset($_SESSION['is_super_admin']) || !$_SESSION['is_super_admin']) {
    header('Location: login.php?error=unauthorized');
    exit;
}

$feedbackMessage = null;
$feedbackType = 'info';

// Processar atualização do formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $targetUserId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

    if ($_POST['action'] === 'update_batch' && isset($_POST['batch_size'])) {
        $batchSize = filter_input(INPUT_POST, 'batch_size', FILTER_VALIDATE_INT);
        
        if ($targetUserId && $batchSize && in_array($batchSize, [50, 75, 100])) {
            try {
                $pdo = getDbConnection();
                $stmt = $pdo->prepare("UPDATE mercadolibre_users SET batch_size = ? WHERE saas_user_id = ?");
                $stmt->execute([$batchSize, $targetUserId]);
                $feedbackMessage = "Lote de importação para o usuário ID $targetUserId atualizado para $batchSize.";
                $feedbackType = 'success';
            } catch (Exception $e) {
                $feedbackMessage = "Erro ao atualizar o lote: " . $e->getMessage();
                $feedbackType = 'error';
            }
        } else {
            $feedbackMessage = "Dados inválidos para atualização do lote.";
            $feedbackType = 'error';
        }
    }
}

$allUsers = [];
try {
    $pdo = getDbConnection();
    $stmt = $pdo->query(
        "SELECT u.id, u.email, u.is_super_admin, m.ml_user_id, m.sync_status, m.batch_size
         FROM saas_users u
         LEFT JOIN mercadolibre_users m ON u.id = m.saas_user_id
         ORDER BY u.created_at DESC"
    );
    $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $feedbackMessage = "Erro ao carregar dados dos usuários: " . $e->getMessage();
    $feedbackType = 'error';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin - Analisador ML</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-gray-100 text-gray-900">
    <section class="container mx-auto px-4 py-8">
        <header class="bg-white shadow rounded-lg p-4 mb-6 flex justify-between items-center">
            <h1 class="text-xl font-semibold">🛡️ Super Admin Panel</h1>
            <div>
                <a href="dashboard.php" class="text-sm text-blue-600 hover:underline mr-4">Meu Dashboard</a>
                <a href="logout.php" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded shadow-sm text-white bg-red-600 hover:bg-red-700">Sair</a>
            </div>
        </header>

        <?php if ($feedbackMessage): ?>
            <div class="p-4 mb-4 text-sm rounded-lg <?php echo $feedbackType === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>" role="alert">
                <?php echo htmlspecialchars($feedbackMessage); ?>
            </div>
        <?php endif; ?>

        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4">👥 Gerenciamento de Usuários e Sincronização</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider">Usuário</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider">Status Sinc.</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider">Lote/Ciclo (Máx. 100)</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($allUsers as $user): ?>
                            <tr>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <div class="text-sm font-medium"><?php echo htmlspecialchars($user['email']); ?></div>
                                    <div class="text-xs text-gray-500">
                                        ID SaaS: <?php echo $user['id']; ?> | ID ML: <?php echo htmlspecialchars($user['ml_user_id'] ?? 'N/A'); ?>
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-sm">
                                    <span class="px-2 py-0.5 text-xs font-bold rounded-full <?php 
                                            switch($user['sync_status'] ?? 'IDLE') {
                                                case 'COMPLETED': echo 'bg-green-100 text-green-800'; break;
                                                case 'SYNCING': echo 'bg-blue-100 text-blue-800'; break;
                                                case 'REQUESTED': echo 'bg-yellow-100 text-yellow-800'; break;
                                                case 'PAUSED': echo 'bg-gray-200 text-gray-800'; break;
                                                case 'ERROR': echo 'bg-red-100 text-red-800'; break;
                                                default: echo 'bg-gray-100 text-gray-600';
                                            }
                                        ?>"><?php echo htmlspecialchars($user['sync_status'] ?? 'N/A'); ?></span>
                                </td>
                                <td class="px-4 py-2">
                                    <?php if ($user['ml_user_id']): ?>
                                        <form action="super_admin.php" method="POST" class="flex items-center space-x-2">
                                            <input type="hidden" name="action" value="update_batch">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <select name="batch_size" class="w-24 p-1 border-gray-300 rounded-md shadow-sm">
                                                <option value="50" <?php if (($user['batch_size'] ?? 50) == 50) echo 'selected'; ?>>50 (Padrão)</option>
                                                <option value="75" <?php if (($user['batch_size'] ?? 50) == 75) echo 'selected'; ?>>75</option>
                                                <option value="100" <?php if (($user['batch_size'] ?? 50) == 100) echo 'selected'; ?>>100 (Máximo)</option>
                                            </select>
                                            <button type="submit" class="px-2 py-1 text-xs text-white bg-gray-600 rounded hover:bg-gray-700">Salvar</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2 text-sm whitespace-nowrap">
                                    <div class="flex items-center space-x-4">
                                        <?php if ($user['id'] != $_SESSION['saas_user_id']): ?>
                                            <a href="impersonate.php?target_user_id=<?php echo $user['id']; ?>" class="font-medium text-blue-600 hover:text-blue-500">Ver como Usuário</a>
                                        <?php else: ?>
                                            <span class="text-xs italic text-gray-500">(Você)</span>
                                        <?php endif; ?>
                                        <?php if ($user['id'] != $_SESSION['saas_user_id'] && $user['ml_user_id']): ?>
                                        <form action="clear_sync.php" method="POST" onsubmit="return confirm('ATENÇÃO: Isso apagará TODOS os anúncios deste usuário do banco e reiniciará a sincronização do zero. Deseja continuar?');">
                                            <input type="hidden" name="user_id_to_clear" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="font-medium text-red-600 hover:text-red-500 text-xs">(Limpar)</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</body>
</html>




<?php
/**
 * Arquivo: sync_anuncios.php
 * Versão: v6.1 - Correção definitiva no processamento de lotes de detalhamento.
 *
 * Descrição:
 * - Script CRON com lógica de fases para sincronização de anúncios.
 * - FASE 1 (Sprint de IDs): Em uma única execução, busca continuamente todos os IDs de anúncios
 *   de um usuário usando o método `scroll_id` da API, que é ideal para contas grandes.
 *   Salva apenas os IDs no banco de dados.
 * - FASE 2 (Detalhamento em Lote): Após obter todos os IDs, o script passa a detalhar
 *   os anúncios em lotes. Ele busca um grande número de anúncios não sincronizados
 *   do banco e usa chamadas individuais para cada um para garantir a integridade dos dados.
 * - PRIORIDADE: Antes de tudo, o script verifica se há anúncios marcados para
 *   atualização seletiva e os processa primeiro.
 * - PREVENÇÃO DE CONCORRÊNCIA: Usa um sistema de file lock para garantir que
 *   apenas uma instância deste script rode por vez.
 */

// --- Prevenção de Concorrência (File Lock) ---
$lockFilePath = sys_get_temp_dir() . '/sync_anuncios.lock';
$lockFileHandle = fopen($lockFilePath, 'c');
if ($lockFileHandle === false) {
    // Não foi possível criar o arquivo de lock. Logar um erro seria ideal em produção.
    exit; 
}
if (!flock($lockFileHandle, LOCK_EX | LOCK_NB)) {
    // Outro processo já está rodando. Isso é normal, apenas sai silenciosamente.
    fclose($lockFileHandle);
    exit;
}

// --- Configurações e Includes ---
set_time_limit(1800); // 30 minutos de tempo máximo de execução
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/log_helper.php';
require_once __DIR__ . '/includes/ml_api.php';

define('API_CALL_DELAY_MS', 250);
define('SCROLL_PAGE_SIZE', 100); // Limite máximo da API para busca de IDs
define('MAX_SCROLL_ITERATIONS', 250); // Disjuntor de segurança (250 * 100 = 25.000 IDs por ciclo de CRON)

logMessage("==== [CRON SYNC_ANUNCIOS v6.1 - Correção Final] Iniciando ciclo ====");

$pdo = null;
$connId = null;

try {
    $pdo = getDbConnection();
    $globalNow = new DateTimeImmutable();

    // 1. Buscar Próximo Usuário da Fila
    $sql_conn = "SELECT mlu.* FROM mercadolibre_users mlu
                 WHERE mlu.sync_status IN ('REQUESTED', 'SYNCING') AND mlu.is_active = TRUE
                 ORDER BY mlu.sync_last_run_at ASC, mlu.updated_at ASC
                 LIMIT 1";
    $stmt_conn = $pdo->query($sql_conn);
    $connection = $stmt_conn->fetch(PDO::FETCH_ASSOC);

    if (!$connection) {
        throw new Exception("Fila vazia. Nenhum usuário para sincronizar.", 1); // Código de saída limpa
    }

    $connId = $connection['id'];
    $mlUserId = $connection['ml_user_id'];
    $saasUserId = $connection['saas_user_id'];
    $currentStatus = $connection['sync_status'];
    $itemsToProcessPerCycle = (int)($connection['batch_size'] ?? 100);
    if ($itemsToProcessPerCycle <= 0) $itemsToProcessPerCycle = 100;
    
    $pdo->prepare("UPDATE mercadolibre_users SET sync_status = 'SYNCING', sync_last_run_at = NOW() WHERE id = ?")->execute([$connId]);
    logMessage("--> [ML $mlUserId] Processando. Lote de detalhamento: $itemsToProcessPerCycle. Status inicial: $currentStatus.");

    // 2. Obtenção e Renovação Centralizada do Access Token
    $currentAccessToken = '';
    try {
        logMessage("    [ML $mlUserId] Validando token...");
        $tokenExpiresAt = new DateTimeImmutable($connection['token_expires_at']);
        if ($globalNow >= $tokenExpiresAt->modify("-10 minutes")) {
            logMessage("    [ML $mlUserId] TOKEN EXPIRANDO. Iniciando renovação...");
            $decryptedRefreshToken = decryptData($connection['refresh_token']);
            $refreshResult = refreshMercadoLibreToken($decryptedRefreshToken);
            if ($refreshResult['httpCode'] == 200 && !empty($refreshResult['response']['access_token'])) {
                $newData = $refreshResult['response'];
                $currentAccessToken = $newData['access_token'];
                $newRefreshToken = $newData['refresh_token'] ?? $decryptedRefreshToken;
                $newExpAt = $globalNow->modify("+" . (int)($newData['expires_in'] ?? 21600) . " seconds")->format('Y-m-d H:i:s');
                $encAT = encryptData($currentAccessToken); $encRT = encryptData($newRefreshToken);
                $pdo->prepare("UPDATE mercadolibre_users SET access_token=?, refresh_token=?, token_expires_at=? WHERE id=?")->execute([$encAT, $encRT, $newExpAt, $connId]);
                logMessage("    [ML $mlUserId] SUCESSO: Token renovado e salvo.");
            } else { throw new Exception("Falha na API ao renovar token. HTTP: " . ($refreshResult['httpCode'] ?? 'N/A')); }
        } else {
            $currentAccessToken = decryptData($connection['access_token']);
            logMessage("    [ML $mlUserId] Token ainda válido.");
        }
    } catch (Exception $e) {
        throw new Exception("Erro crítico de autenticação: " . $e->getMessage());
    }
    
    $headers = ['Authorization: Bearer ' . $currentAccessToken];

    // --- Função interna para processar o detalhamento de um lote de IDs de forma segura ---
    function processItemDetails(array $itemIdsToProcess, PDO $pdo, int $saasUserId, int $mlUserId, string $accessToken, array $headers) {
        if (empty($itemIdsToProcess)) {
            return;
        }

        logMessage("      Processando detalhes para " . count($itemIdsToProcess) . " itens...");

        foreach ($itemIdsToProcess as $itemId) {
            try {
                // Valida o ID antes de qualquer chamada à API
                if (empty($itemId) || !is_string($itemId)) {
                    logMessage("      [Item Lote] ID de item inválido (não é string ou está vazio) encontrado no lote. Pulando.");
                    continue;
                }
                
                // Busca os detalhes individualmente para garantir a integridade e correção dos dados
                $itemDetails = getMercadoLibreItemDetails($itemId, $accessToken);
                usleep(API_CALL_DELAY_MS * 1000);
                
                if ($itemDetails['httpCode'] != 200 || empty($itemDetails['response'])) {
                    logMessage("      [Item $itemId] Falha ao buscar detalhes principais. HTTP: {$itemDetails['httpCode']}. Marcando como sincronizado para não tentar novamente.");
                    // Marca como sincronizado para não ficar em loop infinito se o item foi removido do ML
                    $pdo->prepare("UPDATE anuncios SET is_synced = 1, title = '[Item não encontrado ou removido]' WHERE saas_user_id = ? AND ml_item_id = ?")->execute([$saasUserId, $itemId]);
                    continue;
                }
                $itemData = $itemDetails['response'];

                $visitsResult = getMercadoLibreItemVisits($itemId, $accessToken);
                usleep(API_CALL_DELAY_MS * 1000);
                
                $last_sale_date = null;
                if (($itemData['sold_quantity'] ?? 0) > 0) {
                    $orderUrl = "https://api.mercadolibre.com/orders/search?seller={$mlUserId}&item={$itemId}&sort=date_desc&limit=1";
                    $orderResult = makeCurlRequest($orderUrl, 'GET', $headers);
                    if ($orderResult['httpCode'] == 200 && !empty($orderResult['response']['results'])) {
                        $last_sale_date = (new DateTime($orderResult['response']['results'][0]['date_closed']))->format('Y-m-d H:i:s');
                    }
                    usleep(API_CALL_DELAY_MS * 1000);
                }

                $sqlUpsert = "UPDATE anuncios SET title = ?, date_created = ?, total_visits = ?, total_sales = ?, last_sale_date = ?, is_synced = 1 WHERE saas_user_id = ? AND ml_item_id = ?";
                $pdo->prepare($sqlUpsert)->execute([
                    $itemData['title'] ?? 'N/A',
                    isset($itemData['date_created']) ? (new DateTime($itemData['date_created']))->format('Y-m-d H:i:s') : null,
                    $visitsResult['response']['total_visits'] ?? 0,
                    $itemData['sold_quantity'] ?? 0,
                    $last_sale_date,
                    $saasUserId,
                    $itemId
                ]);

            } catch (Exception $detailError) {
                logMessage("      [Item $itemId] ERRO no detalhamento individual: " . $detailError->getMessage());
            }
        }
    }


    // 3. PRIORIDADE 1: ATUALIZAÇÃO SELETIVA
    logMessage("    [ML $mlUserId] Etapa 1: Verificando por anúncios com atualização solicitada (is_synced = 2)...");
    $stmtToUpdate = $pdo->prepare("SELECT ml_item_id FROM anuncios WHERE saas_user_id = ? AND is_synced = 2 LIMIT " . $itemsToProcessPerCycle);
    $stmtToUpdate->execute([$saasUserId]);
    $itemsToUpdate = $stmtToUpdate->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($itemsToUpdate)) {
        $countToUpdate = count($itemsToUpdate);
        logMessage("    [ML $mlUserId] Encontrados $countToUpdate anúncios para atualização seletiva. Processando...");
        $pdo->prepare("UPDATE mercadolibre_users SET sync_last_message = 'Atualizando $countToUpdate anúncios selecionados...' WHERE id = ?")->execute([$connId]);
        processItemDetails($itemsToUpdate, $pdo, $saasUserId, $mlUserId, $currentAccessToken, $headers);
        throw new Exception("Lote de atualização seletiva processado. Continuando na próxima execução.", 4);
    }
    
    logMessage("    [ML $mlUserId] Nenhum anúncio para atualização seletiva. Prosseguindo...");


    // 4. PRIORIDADE 2: SINCRONIZAÇÃO NORMAL (Lógica de Fases)
    $isFirstRun = ($currentStatus === 'REQUESTED');
    $scrollId = $connection['sync_scroll_id'];
    $isFetchingIds = !empty($scrollId) || $isFirstRun;

    // FASE 1: "SPRINT" PARA BUSCAR TODOS OS IDs
    if ($isFetchingIds) {
        if ($isFirstRun) {
            logMessage("    [ML $mlUserId] FASE 1 (SPRINT): Primeira execução. Limpando dados antigos...");
            $pdo->prepare("UPDATE mercadolibre_users SET sync_scroll_id = NULL WHERE id = ?")->execute([$connId]);
            $pdo->prepare("DELETE FROM anuncios WHERE saas_user_id = ?")->execute([$saasUserId]);
            $scrollId = null; 
        }

        $iterationCount = 0;
        $totalIdsFetchedThisRun = (int)$pdo->query("SELECT COUNT(*) FROM anuncios WHERE saas_user_id = {$saasUserId}")->fetchColumn();
        
        while ($iterationCount < MAX_SCROLL_ITERATIONS) {
            $iterationCount++;

            $url = "https://api.mercadolibre.com/users/{$mlUserId}/items/search?search_type=scan&limit=" . SCROLL_PAGE_SIZE;
            if (!empty($scrollId)) {
                $url = "https://api.mercadolibre.com/users/{$mlUserId}/items/search?search_type=scan&scroll_id=" . urlencode($scrollId);
            }

            logMessage("    [ML $mlUserId] FASE 1 (SPRINT): Buscando página #$iterationCount...");
            $resultItems = makeCurlRequest($url, 'GET', $headers);
            usleep(API_CALL_DELAY_MS * 1000);

            if ($resultItems['httpCode'] != 200) { throw new Exception("Falha na chamada à API de itens (Fase 1). HTTP: {$resultItems['httpCode']}."); }
            
            $itemIds = $resultItems['response']['results'] ?? [];
            $newScrollId = $resultItems['response']['scroll_id'] ?? null;
            $scrollId = $newScrollId;

            if (empty($itemIds)) {
                logMessage("    [ML $mlUserId] FASE 1 (SPRINT) CONCLUÍDA: Todos os " . $totalIdsFetchedThisRun . " IDs foram buscados.");
                $pdo->prepare("UPDATE mercadolibre_users SET sync_scroll_id = NULL, sync_last_message = 'Lista de anúncios obtida. Iniciando detalhamento...' WHERE id = ?")->execute([$connId]);
                $isFetchingIds = false;
                break; 
            }

            $sqlInsertIgnore = "INSERT IGNORE INTO anuncios (saas_user_id, ml_item_id) VALUES (?, ?)";
            $stmtInsert = $pdo->prepare($sqlInsertIgnore);
            foreach ($itemIds as $itemId) {
                if (!empty($itemId)) {
                    $stmtInsert->execute([$saasUserId, $itemId]);
                }
            }
            $totalIdsFetchedThisRun += count($itemIds);
            
            $totalApi = $resultItems['response']['paging']['total'] ?? 0;
            $progressMessage = "Buscando lista de anúncios... {$totalIdsFetchedThisRun} de {$totalApi} IDs encontrados.";
            $pdo->prepare("UPDATE mercadolibre_users SET sync_last_message = ?, sync_scroll_id = ? WHERE id = ?")->execute([$progressMessage, $scrollId, $connId]);
        }

        if ($isFetchingIds) {
            logMessage("    [ML $mlUserId] FASE 1 (SPRINT): Limite de iterações atingido. Continuará na próxima execução.");
            throw new Exception("Sprint de IDs pausado para segurança. Continuando no próximo ciclo.", 5);
        }
    }

    // FASE 2: DETALHAR OS ANÚNCIOS JÁ SALVOS
    logMessage("    [ML $mlUserId] FASE 2: Detalhando anúncios com is_synced = 0...");
    $stmtToDetail = $pdo->prepare("SELECT ml_item_id FROM anuncios WHERE saas_user_id = ? AND is_synced = 0 LIMIT " . $itemsToProcessPerCycle);
    $stmtToDetail->execute([$saasUserId]);
    $itemsToDetail = $stmtToDetail->fetchAll(PDO::FETCH_COLUMN);

    if (empty($itemsToDetail)) {
        logMessage("    [ML $mlUserId] FASE 2 CONCLUÍDA: Nenhum anúncio para detalhar. Sincronização finalizada.");
        $totalFinal = (int)$pdo->query("SELECT COUNT(*) FROM anuncios WHERE saas_user_id = $saasUserId")->fetchColumn();
        $pdo->prepare("UPDATE mercadolibre_users SET sync_status='COMPLETED', sync_last_message=? WHERE id=?")->execute(["Sincronização concluída. Total de $totalFinal anúncios.", $connId]);
        throw new Exception("Sincronização finalizada com sucesso.", 3);
    }
    
    $totalAnunciosNoDb = (int) $pdo->query("SELECT COUNT(*) FROM anuncios WHERE saas_user_id = $saasUserId")->fetchColumn();
    $totalSynced = (int) $pdo->query("SELECT COUNT(*) FROM anuncios WHERE saas_user_id = $saasUserId AND is_synced = 1")->fetchColumn();
    $progressMessage = "Detalhando... Anúncios processados: " . ($totalSynced + count($itemsToDetail)) . " de " . $totalAnunciosNoDb;
    $pdo->prepare("UPDATE mercadolibre_users SET sync_last_message = ? WHERE id = ?")->execute([$progressMessage, $connId]);
    logMessage("    [ML $mlUserId] $progressMessage");
    
    processItemDetails($itemsToDetail, $pdo, $saasUserId, $mlUserId, $currentAccessToken, $headers);

} catch (\Throwable $e) {
    if (in_array($e->getCode(), [1, 2, 3, 4, 5])) { 
        logMessage($e->getMessage());
    } else {
        $errorMessage = "!!!! ERRO FATAL CRON SYNC_ANUNCIOS: " . $e->getMessage();
        logMessage($errorMessage);
        if (isset($connId) && $pdo) {
             $pdo->prepare("UPDATE mercadolibre_users SET sync_status='ERROR', sync_scroll_id=NULL, sync_last_message=? WHERE id=?")->execute(['Erro fatal no script: ' . substr($e->getMessage(), 0, 240), $connId]);
        }
    }
}

logMessage("==== [CRON SYNC_ANUNCIOS v6.1 - Correção Final] Ciclo finalizado ====\n");

flock($lockFileHandle, LOCK_UN);
fclose($lockFileHandle);
?>



<?php
/**
 * Arquivo: toggle_sync.php
 * Versão: v2.0 - Corrigida lógica de personificação.
 * Descrição: Pausa ou retoma uma sincronização para o usuário correto.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['saas_user_id'])) {
    header('Location: login.php?error=unauthorized');
    exit;
}

// --- LÓGICA DE PERSONIFICAÇÃO CORRIGIDA ---
$isImpersonating = isset($_SESSION['impersonating_user_id']);
$saasUserIdToActOn = $isImpersonating ? $_SESSION['impersonating_user_id'] : $_SESSION['saas_user_id'];

$action = $_GET['action'] ?? null;
if (!$action || !in_array($action, ['pause', 'resume'])) {
    header('Location: dashboard.php');
    exit;
}

try {
    $pdo = getDbConnection();
    $newStatus = '';
    $allowedCurrentStatuses = [];

    if ($action === 'pause') {
        $newStatus = 'PAUSED';
        $allowedCurrentStatuses = ['REQUESTED', 'SYNCING'];
    } elseif ($action === 'resume') {
        $newStatus = 'REQUESTED';
        $allowedCurrentStatuses = ['PAUSED'];
    }

    $sql = "UPDATE mercadolibre_users SET sync_status = ? WHERE saas_user_id = ? AND sync_status IN ('" . implode("','", $allowedCurrentStatuses) . "')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$newStatus, $saasUserIdToActOn]);

} catch (Exception $e) {}

header('Location: dashboard.php');
exit;
?>


<?php
/**
 * Arquivo: update_selected.php
 * Versão: v1.0
 * Descrição: Recebe os IDs dos anúncios selecionados e os marca para atualização.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['saas_user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$isImpersonating = isset($_SESSION['impersonating_user_id']);
$saasUserIdToActOn = $isImpersonating ? $_SESSION['impersonating_user_id'] : $_SESSION['saas_user_id'];
$selectedIds = $_POST['selected_ids'] ?? [];

if (!empty($selectedIds) && is_array($selectedIds)) {
    try {
        $pdo = getDbConnection();
        // Garante que os IDs são seguros para usar na query
        $sanitizedIds = array_filter($selectedIds, function($id) {
            return preg_match('/^MLB\d+$/', $id);
        });

        if (!empty($sanitizedIds)) {
            $placeholders = implode(',', array_fill(0, count($sanitizedIds), '?'));
            
            // Marca os anúncios selecionados para serem atualizados na próxima execução do CRON
            $sql = "UPDATE anuncios SET is_synced = 2 WHERE saas_user_id = ? AND ml_item_id IN ($placeholders)";
            
            $params = array_merge([$saasUserIdToActOn], $sanitizedIds);
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

    } catch (Exception $e) {
        // Tratar erro, se necessário
    }
}

// Redireciona de volta para o dashboard
header('Location: dashboard.php');
exit;
?>


<?php
/**
 * Arquivo: includes/curl_helper.php
 * Versão: v1.3
 * Descrição: Helper para realizar requisições cURL.
 */
function makeCurlRequest(string $url, string $method = 'GET', array $headers = [], $postData = null, bool $isJson = false): array
{
    $ch = curl_init();
    $curlOptions = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_USERAGENT => 'Analisador-Anuncios-ML/1.0',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH']) && $postData !== null) {
        $payload = $isJson ? json_encode($postData) : http_build_query($postData);
        $curlOptions[CURLOPT_POSTFIELDS] = $payload;
    }

    curl_setopt_array($ch, $curlOptions);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrorNum = curl_errno($ch);
    $curlErrorMsg = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['httpCode' => 0, 'error' => "cURL Error #{$curlErrorNum}: {$curlErrorMsg}", 'response' => null, 'is_json' => false];
    }

    $responseData = json_decode((string)$response, true);
    $isJsonResult = (json_last_error() === JSON_ERROR_NONE);

    return ['httpCode' => $httpCode, 'error' => null, 'response' => $isJsonResult ? $responseData : $response, 'is_json' => $isJsonResult];
}
?>

<?php
/**
 * Arquivo: includes/helpers.php
 * Versão: v2.0 - Adiciona função para formatar o tempo da última venda.
 * Descrição: Funções auxiliares (helpers) reutilizáveis na aplicação.
 */

if (!function_exists('formatLastSaleTag')) {
    /**
     * Gera a tag de tempo decorrido desde a última venda.
     * Retorna a tag "Nunca Vendeu" ou "Sem Venda >30d" quando aplicável,
     * ou uma string dinâmica como "Hoje", "Ontem", "há X dias".
     *
     * @param string|null $lastSaleDate A data da última venda em formato 'Y-m-d H:i:s'.
     * @param int $totalSales O número total de vendas do anúncio.
     * @param bool $isSynced Se o anúncio já teve seus detalhes sincronizados.
     * @return array Retorna um array com 'text' e 'class' para a tag.
     */
    function formatLastSaleTag(?string $lastSaleDate, int $totalSales, bool $isSynced): array
    {
        // Se o anúncio ainda não foi detalhado, retorna vazio
        if (!$isSynced) {
            return ['text' => '', 'class' => ''];
        }

        // Se nunca vendeu, prioriza essa informação
        if ($totalSales === 0) {
            return ['text' => 'Nunca Vendeu', 'class' => 'bg-red-100 text-red-800'];
        }

        // Se tem vendas mas não temos a data da última venda (caso raro)
        if ($lastSaleDate === null) {
            return ['text' => 'Sem Venda >30d', 'class' => 'bg-yellow-100 text-yellow-800'];
        }

        try {
            $now = new DateTime();
            $saleDate = new DateTime($lastSaleDate);
            $diff = $now->diff($saleDate);
            $days = (int) $diff->days;
            
            // Compara apenas a data, ignorando a hora, para "Hoje" e "Ontem"
            $nowDateOnly = new DateTime($now->format('Y-m-d'));
            $saleDateOnly = new DateTime($saleDate->format('Y-m-d'));
            $dateDiffDays = (int) $nowDateOnly->diff($saleDateOnly)->days;

            if ($dateDiffDays === 0) {
                return ['text' => 'Hoje', 'class' => 'bg-green-100 text-green-800'];
            }
            if ($dateDiffDays === 1) {
                return ['text' => 'Ontem', 'class' => 'bg-green-100 text-green-800'];
            }
            if ($days < 30) {
                return ['text' => "há {$days} dias", 'class' => 'bg-green-100 text-green-800'];
            }
            
            // Se chegou aqui, tem mais de 30 dias
            return ['text' => 'Sem Venda >30d', 'class' => 'bg-yellow-100 text-yellow-800'];

        } catch (Exception $e) {
            // Em caso de erro de data, retorna a tag padrão de >30d
            return ['text' => 'Sem Venda >30d', 'class' => 'bg-yellow-100 text-yellow-800'];
        }
    }
}
?>


<?php
/**
 * Arquivo: includes/log_helper.php
 * Versão: v1.0
 * Descrição: Helper para logging centralizado.
 */
function logMessage(string $message): void
{
    try {
        if (!defined('LOG_FILE')) {
             error_log("FATAL: Constante LOG_FILE não definida! Mensagem: " . $message);
             return;
        }
        $logFilePath = LOG_FILE;
        $timestamp = date('Y-m-d H:i:s');
        $pid = getmypid() ?: 'N/A';
        $logLine = "[$timestamp PID:$pid] $message\n";

        // Tenta escrever no arquivo de log com bloqueio exclusivo
        @file_put_contents($logFilePath, $logLine, FILE_APPEND | LOCK_EX);

    } catch (\Exception $e) {
        error_log("Exceção CRÍTICA na função logMessage(): " . $e->getMessage() . " | Mensagem original: " . $message);
    }
}
?>


<?php
/**
 * Arquivo: includes/ml_api.php (Analisador de Anúncios ML)
 * Versão: v2.1 - Corrige a chamada para obter visitas de anúncios.
 * Descrição: Funções para interagir com a API do Mercado Livre.
 */

require_once __DIR__ . '/log_helper.php';
require_once __DIR__ . '/curl_helper.php';

/**
 * Renova o Access Token do Mercado Livre usando o Refresh Token.
 * @param string $refreshToken O Refresh Token válido (descriptografado).
 * @return array<string, mixed> O resultado da chamada à API de token.
 */
function refreshMercadoLibreToken(string $refreshToken): array
{
    $url = ML_TOKEN_URL;
    $headers = ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'];
    $postData = [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id' => ML_APP_ID,
        'client_secret' => ML_SECRET_KEY
    ];
    logMessage("[refreshMercadoLibreToken] Enviando requisição de refresh...");
    return makeCurlRequest($url, 'POST', $headers, $postData, false);
}

/**
 * Obtém detalhes de um item (anúncio) específico no Mercado Livre.
 * Otimizado para buscar apenas os campos necessários.
 * @param string $itemId O ID do item (formato MLBxxxxxxxxx).
 * @param string $accessToken O Access Token válido (descriptografado).
 * @return array<string, mixed> O resultado da chamada à API.
 */
function getMercadoLibreItemDetails(string $itemId, string $accessToken): array
{
    // Apenas os atributos que realmente usamos
    $attributes = 'title,sold_quantity,date_created';
    $url = ML_API_BASE_URL . '/items/' . $itemId . '?attributes=' . $attributes;
    $headers = ['Authorization: Bearer ' . $accessToken];
    return makeCurlRequest($url, 'GET', $headers);
}

/**
 * **CORRIGIDO**
 * Obtém as visitas totais de um anúncio específico.
 * Segue a documentação do endpoint /visits/items?ids=ITEM_ID.
 *
 * @param string $itemId O ID do item (formato MLBxxxxxxxxx).
 * @param string $accessToken O Access Token válido (descriptografado).
 * @return array<string, mixed> Retorna um array normalizado, como se a resposta fosse {'response': {'total_visits': VALOR}}.
 */
function getMercadoLibreItemVisits(string $itemId, string $accessToken): array
{
    // Constrói a URL exatamente como na documentação
    $url = ML_API_BASE_URL . '/visits/items?ids=' . urlencode($itemId);
    $headers = ['Authorization: Bearer ' . $accessToken];

    logMessage("[getMercadoLibreItemVisits] Buscando visitas para item: $itemId. URL: $url");
    $result = makeCurlRequest($url, 'GET', $headers);

    // Verifica se a chamada foi bem-sucedida e se a resposta tem o formato esperado
    if ($result['httpCode'] === 200 && $result['is_json'] && isset($result['response'][$itemId])) {
        $totalVisits = (int) $result['response'][$itemId];
        logMessage("[getMercadoLibreItemVisits] Sucesso! Visitas para $itemId: $totalVisits");

        // Normaliza a resposta para um formato consistente com as outras funções
        return [
            'httpCode' => 200,
            'error' => null,
            'response' => ['total_visits' => $totalVisits],
            'is_json' => true
        ];
    }

    // Se a chamada falhou ou a resposta não veio como esperado, retorna o erro original
    logMessage("[getMercadoLibreItemVisits] ERRO ao buscar visitas para $itemId. HTTP: {$result['httpCode']}. Resposta: " . json_encode($result['response']));
    return [
        'httpCode' => $result['httpCode'],
        'error' => $result['error'] ?? 'Formato de resposta inesperado da API de visitas.',
        'response' => $result['response'],
        'is_json' => $result['is_json']
    ];
}
?>



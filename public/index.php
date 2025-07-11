<?php
// Carrega o autoloader do Composer.
require_once __DIR__ . '/../vendor/autoload.php';

// Carrega as variáveis de ambiente do arquivo .env
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
} catch (\Dotenv\Exception\InvalidPathException $e) {
    // Este erro ocorre se o arquivo .env não for encontrado no caminho esperado.
    http_response_code(503); // Service Unavailable
    error_log('CRITICAL: Arquivo .env não encontrado ou diretório não legível. Verifique a configuração do servidor. Detalhes: ' . $e->getMessage());
    die('O serviço está temporariamente indisponível devido a um erro de configuração.');
}

// Inicia a sessão em todas as requisições.
// Deve ser chamado antes de qualquer output e depois de carregar as configurações.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Carrega o arquivo de configuração central (que define BASE_PATH).
require_once __DIR__ . '/../src/Core/config.php';

// Carrega os helpers essenciais explicitamente
require_once BASE_PATH . '/src/Helpers/log_helper.php';
require_once BASE_PATH . '/src/Helpers/view_helper.php';
require_once BASE_PATH . '/src/Helpers/flash_helper.php';
require_once BASE_PATH . '/src/Helpers/csrf_helper.php';
require_once BASE_PATH . '/src/Helpers/encryption_helper.php';

// Cria uma instância do roteador
$router = new \Bramus\Router\Router();

// Carrega as definições de rota
require_once __DIR__ . '/../src/routes.php';
$router->run();
<?php
namespace App\Core;
use Dotenv\Dotenv;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (file_exists(BASE_PATH . '/.env')) {
    $dotenv = Dotenv::createImmutable(BASE_PATH);
    $dotenv->load();
} else {
    die('Erro Crítico: Arquivo .env não encontrado. Crie o arquivo a partir do .env.example e preencha suas credenciais.');
}

// --- Validação de Variáveis de Ambiente Essenciais ---
$requiredEnvVars = [
    'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    'ML_APP_ID', 'ML_SECRET_KEY', 'ML_REDIRECT_URI',
    'ENCRYPTION_KEY', 'GEMINI_API_KEY', 'ASAAS_API_KEY'
];

$missingVars = [];
foreach ($requiredEnvVars as $var) {
    if (empty($_ENV[$var])) {
        $missingVars[] = $var;
    }
}

if (!empty($missingVars)) {
    die('Erro Crítico de Configuração: As seguintes variáveis de ambiente essenciais não estão definidas no seu arquivo .env: ' . implode(', ', $missingVars));
}


// --- Configuração de Erros baseada no APP_ENV ---
if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1'); // Habilita o log de erros em produção
    // error_log(BASE_PATH . '/logs/php_errors.log'); // Opcional: definir um arquivo de log
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

// --- Definição de Constantes ---
// (O resto do arquivo permanece o mesmo, carregando as variáveis de $_ENV)
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_PORT', $_ENV['DB_PORT'] ?? '3306');
define('DB_DATABASE', $_ENV['DB_DATABASE'] ?? '');
define('DB_USERNAME', $_ENV['DB_USERNAME'] ?? '');
define('DB_PASSWORD', $_ENV['DB_PASSWORD'] ?? '');
define('APP_ENCRYPTION_KEY', $_ENV['ENCRYPTION_KEY'] ?? '');
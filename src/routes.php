<?php
/**
 * Definições de todas as rotas da aplicação MELI 360.
 * Este arquivo é incluído pelo public/index.php e tem acesso à variável $router.
 */

// Importa as classes dos Controladores para um código mais limpo e organizado.

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\MercadoLivreController;
use App\Controllers\AdminController; // Adicione este 'use' no topo do arquivo


// --- ROTAS PÚBLICAS (acessíveis sem login) ---

$router->get('/', function() {
    // Se o usuário já estiver logado, o leva para o dashboard.
    if (isset($_SESSION['user_id'])) {
        header('Location: /dashboard');
        exit;
    }
    // Se não, o leva para a página de login.
    header('Location: /login');
    exit;
});

// Rotas para o fluxo de autenticação de usuários da plataforma.
$router->get('/login', AuthController::class . '@showLoginForm');
$router->post('/login', AuthController::class . '@login');
$router->get('/register', AuthController::class . '@showRegisterForm');
$router->post('/register', AuthController::class . '@register');
$router->get('/logout', AuthController::class . '@logout');

// Rota de Callback do Mercado Livre.
// Deve ser pública para que o servidor do ML consiga nos enviar o código de autorização.
$router->get('/ml/callback', MercadoLivreController::class . '@handleCallback');


// --- ROTAS PROTEGIDAS (requerem que o usuário esteja logado) ---

// Middleware de verificação de autenticação.
// Ele é executado ANTES de qualquer rota que corresponda ao padrão '/dashboard.*'.
$router->before('GET|POST', '/dashboard.*', function() {
    if (!isset($_SESSION['user_id'])) {
        // Usa o sistema de flash messages para uma melhor experiência do usuário.
        set_flash_message('auth_error', 'Você precisa estar logado para acessar esta página.');
        header('Location: /login');
        exit();
    }
});

// A rota principal do Dashboard (Visão Geral).
$router->get('/dashboard', DashboardController::class . '@index');

// A rota para a nova página de Análise de Anúncios.
$router->get('/dashboard/analysis', DashboardController::class . '@analysis');

// A rota para o usuário solicitar uma nova sincronização de anúncios.
$router->get('/dashboard/sync', MercadoLivreController::class . '@requestSync');

// A rota para iniciar o processo de conexão com o Mercado Livre.
$router->get('/dashboard/conectar/mercadolivre', MercadoLivreController::class . '@redirectToAuth');


// --- ROTA DE FALLBACK (404 - PÁGINA NÃO ENCONTRADA) ---

// Esta rota especial é executada se nenhuma outra rota definida acima corresponder à URL solicitada.
$router->set404(function() {
    http_response_code(404);
    view('errors.404');
});

// Middleware para proteger todas as rotas que começam com /admin
$router->before('GET|POST', '/admin/.*', function() {
    // 1. Verifica se está logado
    if (!isset($_SESSION['user_id'])) {
        set_flash_message('auth_error', 'Você precisa estar logado para acessar a área administrativa.');
        header('Location: /login');
        exit();
    }
    // 2. Verifica se o papel do usuário é 'admin'
    if ($_SESSION['user_role'] !== 'admin') {
        // Se não for admin, mostra uma view de acesso negado.
        http_response_code(403);
        view('errors.403');
        exit();
    }
});

// Rota para acionar a sincronização manualmente
$router->get('/admin/sync', AdminController::class . '@triggerSync');
<?php
/**
 * Definições de todas as rotas da aplicação MELI 360.
 */

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\MercadoLivreController;
use App\Controllers\AdminController;
use App\Controllers\WebhookController;
use App\Controllers\AccountController;
use App\Controllers\SettingsController;
use App\Controllers\ImpersonationController;
use App\Controllers\BillingController;

// --- ROTAS PÚBLICAS (acessíveis sem login) ---

$router->get('/', function() {
    if (isset($_SESSION['user_id'])) {
        header('Location: /dashboard');
    } else {
        header('Location: /login');
    }
    exit;
});

$router->get('/login', AuthController::class . '@showLoginForm');
$router->post('/login', AuthController::class . '@login');
$router->get('/register', AuthController::class . '@showRegisterForm');
$router->post('/register', AuthController::class . '@register');
$router->get('/logout', AuthController::class . '@logout');

$router->get('/ml/callback', MercadoLivreController::class . '@handleCallback');

// Rota para receber notificações do Mercado Livre (perguntas, etc.)
$router->post('/webhooks/mercadolivre', WebhookController::class . '@handleMercadoLivre');

// Rota para receber notificações do Asaas (pagamentos, etc.)
$router->post('/webhooks/asaas', WebhookController::class . '@handleAsaas');

// --- ROTAS PROTEGIDAS (requerem que o usuário esteja logado) ---

$router->before('GET|POST', '/dashboard.*|/billing.*|/dashboard/settings', function() {
    if (!isset($_SESSION['user_id'])) {
        set_flash_message('auth_error', 'Você precisa estar logado para acessar esta página.');
        header('Location: /login');
        exit();
    }
});

// --- Middleware de Assinatura Ativa ---
// Protege as funcionalidades premium.
$router->before('GET|POST', '/dashboard/analysis|/dashboard/responder|/dashboard/anuncio/.*', function() {
    // Admins e Consultants têm acesso livre para fins de suporte.
    if (in_array($_SESSION['user_role'], ['admin', 'consultant'])) {
        return;
    }

    $subscriptionModel = new \App\Models\Subscription();
    if (!$subscriptionModel->isActive($_SESSION['user_id'])) {
        set_flash_message('billing_error', 'Você precisa de uma assinatura ativa para acessar esta funcionalidade.');
        header('Location: /billing/plans');
        exit();
    }
});

// Dashboard e Funcionalidades Principais
$router->get('/dashboard', DashboardController::class . '@index');
$router->get('/dashboard/analysis', DashboardController::class . '@analysis');
$router->get('/dashboard/responder', DashboardController::class . '@responder');
$router->get('/dashboard/anuncio/(\w+)', DashboardController::class . '@anuncioDetails');
$router->get('/dashboard/sync/{mlUserId}', DashboardController::class . '@requestSync');
$router->get('/dashboard/conectar/mercadolivre', MercadoLivreController::class . '@redirectToAuth');

// Gestão de Contas e Configurações
$router->get('/dashboard/set-active-account/(\d+)', AccountController::class . '@setActiveAccount');
$router->get('/dashboard/settings', SettingsController::class . '@index');
$router->post('/dashboard/settings/update', SettingsController::class . '@update');

// Billing
$router->get('/billing/plans', BillingController::class . '@plans');
$router->post('/billing/subscribe/(\d+)', BillingController::class . '@subscribe');

// Personificação
$router->get('/impersonate/stop', ImpersonationController::class . '@stop');

// --- ROTAS DE ADMIN ---

$router->before('GET|POST', '/admin/.*', function() {
    if (!isset($_SESSION['user_id'])) {
        set_flash_message('auth_error', 'Você precisa estar logado.');
        header('Location: /login');
        exit();
    }
    if ($_SESSION['user_role'] !== 'admin') {
        http_response_code(403);
        view('errors.403');
        exit();
    }
});

$router->get('/admin/dashboard', AdminController::class . '@dashboard');
$router->get('/admin/user/(\d+)', AdminController::class . '@viewUser');
$router->post('/admin/user/(\d+)/update', AdminController::class . '@updateUser');
$router->get('/admin/impersonate/start/(\d+)', ImpersonationController::class . '@start');
$router->get('/admin/sync', AdminController::class . '@triggerSync');

// --- ROTA DE FALLBACK (404) ---

$router->set404(function() {
    http_response_code(404);
    view('errors.404');
});

$router->run();
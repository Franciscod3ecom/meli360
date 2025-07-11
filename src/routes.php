<?php
/**
 * Definições de todas as rotas da aplicação MELI 360.
 * Este arquivo é incluído pelo public/index.php e tem acesso à variável $router.
 */

// Importa as classes dos Controladores para um código mais limpo e organizado.

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\MercadoLivreController;
use App\Controllers\AdminController;
use App\Controllers\AccountController;
use App\Controllers\SettingsController;

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

// Rota para receber notificações do Mercado Livre (perguntas, etc.)
$router->post('/webhooks/mercadolivre', 'App\Controllers\WebhookController@handleMercadoLivre');

// Rota para receber notificações do Asaas (pagamentos, etc.)
$router->post('/webhooks/asaas', 'App\Controllers\WebhookController@handleAsaas');


// --- ROTAS PROTEGIDAS (requerem que o usuário esteja logado) ---

// Middleware de verificação de autenticação.
// Ele é executado ANTES de qualquer rota que corresponda ao padrão '/dashboard.*'.
$router->before('GET|POST', '/dashboard.*|/billing.*', function() {
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

// A rota para a página do Respondedor IA.
$router->get('/dashboard/responder', DashboardController::class . '@responder');

// A rota para o usuário solicitar uma nova sincronização de anúncios.
$router->get('/dashboard/sync/(\d+)', MercadoLivreController::class . '@requestSync');

// A rota para definir a conta ML ativa na sessão.
$router->get('/dashboard/set-active-account/(\d+)', AccountController::class . '@setActiveAccount');

// Rota de Configurações
$router->get('/dashboard/settings', SettingsController::class . '@index');
$router->post('/dashboard/settings/update', SettingsController::class . '@update');

// A rota para iniciar o processo de conexão com o Mercado Livre.
$router->get('/dashboard/conectar/mercadolivre', MercadoLivreController::class . '@redirectToAuth');

// --- ROTAS DE BILLING ---
$router->get('/billing/plans', 'App\Controllers\BillingController@plans');
$router->post('/billing/subscribe/(\d+)', 'App\Controllers\BillingController@subscribe');


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

// Rota principal do painel de administração
$router->get('/admin', AdminController::class . '@index');
$router->get('/admin/dashboard', AdminController::class . '@index');

// Rota para ver os detalhes de um usuário específico
$router->get('/admin/user/(\d+)', AdminController::class . '@showUserDetails');

// Rota para salvar as alterações do usuário
$router->post('/admin/user/(\d+)/update', AdminController::class . '@updateUser');

// Rota para iniciar a personificação (apenas para admins)
$router->get('/admin/impersonate/start/(\d+)', 'App\Controllers\ImpersonationController@start');

// Rota para acionar a sincronização manualmente
$router->get('/admin/sync', AdminController::class . '@triggerSync');

// Rota para parar a personificação (acessível por todos, mas só funciona se estiver personificando)
$router->get('/impersonate/stop', 'App\Controllers\ImpersonationController@stop');

// --- ROTAS PARA CONSULTORES ---

$router->before('GET|POST', '/consultant.*', function() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit();
    }
    if ($_SESSION['user_role'] !== 'consultant') {
        http_response_code(403);
        view('errors.403');
        exit();
    }
});

$router->get('/consultant/dashboard', 'App\Controllers\ConsultantController@index');
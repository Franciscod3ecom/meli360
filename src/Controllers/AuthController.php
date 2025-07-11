<?php
/**
 * Controlador para Autenticação
 *
 * Gerencia as ações de login, logout e registro de usuários.
 */

namespace App\Controllers;

use App\Core\Validator;
use App\Models\User;

class AuthController
{
    /**
     * Exibe a página com o formulário de login.
     * @return void
     */
    public function showLoginForm(): void
    {
        // Carrega a view do formulário de login.
        // Futuramente, podemos passar dados para a view, como mensagens de erro.
        require_once BASE_PATH . '/src/Views/auth/login.phtml';
    }

    /**
     * Processa a tentativa de login do usuário.
     * @return void
     */
    public function login(): void
    {
        // Validação CSRF: A primeira e mais importante verificação.
        if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
            log_message('Falha na validação do token CSRF no login.', 'WARNING');
            http_response_code(403); // Forbidden
            view('errors.403', ['message' => 'A requisição foi bloqueada por motivos de segurança.']);
            exit;
        }

        // 1. Validação robusta usando a classe Validator
        $validator = new Validator($_POST);
        $validator
            ->validate('email', 'required', 'O campo e-mail é obrigatório.')
            ->validate('email', 'email', 'Por favor, insira um e-mail válido.')
            ->validate('password', 'required', 'O campo senha é obrigatório.');

        if ($validator->fails()) {
            set_flash_message('auth_error', $validator->getFirstError());
            header('Location: /login');
            exit;
        }

        $email = $_POST['email'];
        $password = $_POST['password'];

        // 2. Utiliza o Modelo para encontrar o usuário no banco
        $userModel = new User();
        $user = $userModel->findByEmail($email);

        // 3. Verifica se o usuário existe e se a senha está correta
        if ($user && password_verify($password, $user['password_hash'])) {
            // Sucesso no login!
            $this->createUserSession($user['id'], $user['email'], $user['role']);

            // Redireciona para o dashboard principal.
            header('Location: /dashboard');
            exit;
        } else {
            // Mensagem genérica por segurança, usando o sistema de flash messages.
            set_flash_message('auth_error', 'E-mail ou senha inválidos.');
            header('Location: /login');
            exit;
        }
    }

    /**
     * Exibe a página com o formulário de registro.
     * @return void
     */
    public function showRegisterForm(): void
    {
        require_once BASE_PATH . '/src/Views/auth/register.phtml';
    }

    /**
     * Processa a tentativa de registro de um novo usuário.
     * @return void
     */
    public function register(): void
    {
        // Validação CSRF: A primeira e mais importante verificação.
        if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
            log_message('Falha na validação do token CSRF no registro.', 'WARNING');
            http_response_code(403); // Forbidden
            view('errors.403', ['message' => 'A requisição foi bloqueada por motivos de segurança.']);
            exit;
        }

        // 1. Validação robusta dos dados do formulário
        $validator = new Validator($_POST);
        $validator
            ->validate('name', 'required', 'O campo nome é obrigatório.')
            ->validate('email', 'required', 'O campo e-mail é obrigatório.')
            ->validate('email', 'email', 'Por favor, insira um e-mail válido.')
            ->validate('password', 'required', 'O campo senha é obrigatório.')
            ->validate('password', 'min:8', 'A senha deve ter no mínimo 8 caracteres.')
            ->validate('password_confirmation', 'matches:password', 'As senhas não coincidem.')
            ->validate('whatsapp', 'required', 'O campo WhatsApp é obrigatório.');

        if ($validator->fails()) {
            set_flash_message('register_error', $validator->getFirstError()); // Use uma chave diferente para a página de registro
            header('Location: /register');
            exit;
        }

        $name = $_POST['name'];
        $email = $_POST['email'];
        $password = $_POST['password'];
        $whatsapp = $_POST['whatsapp'];

        // Validação adicional para o formato do WhatsApp
        $whatsapp_cleaned = preg_replace('/[^\d]/', '', $whatsapp);
        if (!preg_match('/^\d{10,11}$/', $whatsapp_cleaned)) {
            set_flash_message('register_error', 'Formato do WhatsApp inválido. Use apenas DDD + Número.');
            $_SESSION['form_data'] = ['name' => $name, 'email' => $email, 'whatsapp' => $whatsapp];
            header('Location: /register');
            exit;
        }
        $whatsapp_jid = "55" . $whatsapp_cleaned . "@s.whatsapp.net";

        // 2. Verifica se o e-mail já está em uso
        $userModel = new User();
        if ($userModel->findByEmail($email)) {
            set_flash_message('register_error', 'Este e-mail já está cadastrado.');
            $_SESSION['form_data'] = ['name' => $name, 'email' => $email, 'whatsapp' => $whatsapp];
            header('Location: /register');
            exit;
        }

        // 3. Cria o hash da senha
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // 4. Cria o usuário no banco de dados
        $userId = $userModel->create($name, $email, $passwordHash, 'user', $whatsapp_jid);

        if ($userId) {
            // 5. Cria a sessão do usuário
            $this->createUserSession($userId, $email, 'user'); // Papel padrão
            set_flash_message('dashboard_status', 'Registro realizado com sucesso! Bem-vindo(a).', 'success');
            header('Location: /dashboard');
            exit;
        } else {
            set_flash_message('register_error', 'Ocorreu um erro ao criar sua conta. Tente novamente.');
            $_SESSION['form_data'] = ['name' => $name, 'email' => $email, 'whatsapp' => $whatsapp];
            header('Location: /register');
            exit;
        }
    }

    /**
     * Realiza o logout do usuário, destruindo a sessão.
     * @return void
     */
    public function logout(): void
    {
        // Limpa todas as variáveis de sessão
        $_SESSION = [];

        // Destrói o cookie de sessão
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        // Finalmente, destrói a sessão.
        session_destroy();

        // Redireciona para a página de login com uma mensagem de sucesso.
        set_flash_message('auth_success', 'Você foi desconectado com sucesso.', 'success');
        header('Location: /login');
        exit;
    }

    /**
     * Inicia a sessão para um usuário.
     *
     * @param int $id O ID do usuário.
     * @param string $email O email do usuário.
     * @param string $role O papel (role) do usuário.
     */
    private function createUserSession(int $id, string $email, string $role): void
    {
        generate_csrf_token(true); // Força a regeneração do token CSRF para a nova sessão.
        session_regenerate_id(true); // Previne session fixation
        $_SESSION['user_id'] = $id;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_role'] = $role;
    }
}
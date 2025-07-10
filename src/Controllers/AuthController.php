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
            ->validate('password_confirmation', 'matches:password', 'As senhas não coincidem.');

        if ($validator->fails()) {
            set_flash_message('register_error', $validator->getFirstError());
            // Salvar os dados do formulário (exceto senhas) para repopular
            $_SESSION['form_data'] = ['name' => $_POST['name'], 'email' => $_POST['email']];
            header('Location: /register');
            exit;
        }

        $name = $_POST['name'];
        $email = $_POST['email'];
        $password = $_POST['password'];

        // 2. Verifica se o e-mail já está em uso
        $userModel = new User();
        if ($userModel->findByEmail($email)) {
            set_flash_message('register_error', 'Este e-mail já está cadastrado.');
            $_SESSION['form_data'] = ['name' => $name, 'email' => $email];
            header('Location: /register');
            exit;
        }

        // 3. Cria o hash da senha
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // 4. Cria o usuário no banco de dados
        $userId = $userModel->create($name, $email, $passwordHash);

        if ($userId) {
            // 5. Cria a sessão do usuário
            $this->createUserSession($userId, $email, 'user'); // 'user' como role padrão

            // 6. Redireciona para o dashboard
            header('Location: /dashboard');
            exit;
        } else {
            set_flash_message('register_error', 'Ocorreu um erro ao criar sua conta. Tente novamente.');
            $_SESSION['form_data'] = ['name' => $name, 'email' => $email];
            header('Location: /register');
            exit;
        }
    }

    /**
     * Cria a sessão para um usuário autenticado.
     *
     * @param int $id O ID do usuário.
     * @param string $email O email do usuário.
     * @param string $role O papel (role) do usuário.
     * @return void
     */
    protected function createUserSession(int $id, string $email, string $role): void
    {
        // Regenera o ID da sessão para prevenir ataques de session fixation.
        session_regenerate_id(true);

        $_SESSION['user_id'] = $id;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_role'] = $role;
        $_SESSION['logged_in'] = true;
    }

    /**
     * Faz o logout do usuário, destruindo sua sessão.
     * @return void
     */
    public function logout(): void
    {
        // Limpa todas as variáveis de sessão.
        $_SESSION = [];

        // Destrói a sessão.
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        session_destroy();

        // Redireciona para a página de login com uma mensagem de sucesso.
        set_flash_message('logout_success', 'Você saiu com sucesso.');
        header('Location: /login');
        exit;
    }
}
<?php

class ImpersonationController
{
    /**
     * Inicia a personificação de um usuário.
     *
     * @param int $targetUserId O ID do usuário a ser personificado.
     */
    public function start(int $targetUserId): void
    {
        // Garante que o usuário logado é um admin ou um consultor.
        if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'consultant'])) {
            $this->forbidden();
        }
        // Previne personificação aninhada.
        if (isset($_SESSION['original_user'])) {
            $this->forbidden();
        }

        $userModel = new User();
        $targetUser = $userModel->findById($targetUserId);

        if (!$targetUser) {
            $this->redirectWithError('Usuário alvo para personificação não encontrado.');
        }

        // Se for um consultor, verifica se o alvo é seu cliente.
        if ($_SESSION['user_role'] === 'consultant') {
            $clients = $userModel->findClientsByConsultantId($_SESSION['user_id']);
            $clientIds = array_column($clients, 'id');
            if (!in_array($targetUserId, $clientIds)) {
                $this->forbidden("Você não tem permissão para personificar este usuário.");
            }
        }

        // Salva a sessão original
        $_SESSION['original_user'] = [
            'user_id' => $_SESSION['user_id'],
            'user_email' => $_SESSION['user_email'],
            'user_role' => $_SESSION['user_role'],
        ];

        // Sobrescreve a sessão atual com os dados do usuário alvo
        (new AuthController())->createUserSession($targetUser['id'], $targetUser['email'], $targetUser['role']);
        
        header('Location: /dashboard');
        exit;
    }

    /**
     * Para a sessão de personificação e retorna ao usuário original.
     */
    public function stop(): void
    {
        if (!isset($_SESSION['original_user'])) {
            header('Location: /dashboard');
            exit;
        }

        $originalUser = $_SESSION['original_user'];
        $originalRole = $originalUser['user_role'];

        // Restaura a sessão original
        (new AuthController())->createUserSession($originalUser['user_id'], $originalUser['email'], $originalUser['user_role']);
        unset($_SESSION['original_user']);

        // Redireciona para o painel correto
        if ($originalRole === 'admin') {
            header('Location: /admin/dashboard');
        } elseif ($originalRole === 'consultant') {
            header('Location: /consultant/dashboard');
        } else {
            header('Location: /dashboard');
        }
        exit;
    }

    private function forbidden(string $message = 'Acesso Negado.')
    {
        http_response_code(403);
        view('errors.403', ['message' => $message]);
        exit;
    }

    private function redirectWithError(string $message)
    {
        set_flash_message('admin_error', $message);
        $redirect_url = ($_SESSION['user_role'] === 'admin') ? '/admin/dashboard' : '/consultant/dashboard';
        header('Location: ' . $redirect_url);
        exit;
    }
}
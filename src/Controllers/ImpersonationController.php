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
            $isClient = false;
            foreach ($clients as $client) {
                if ($client['id'] === $targetUserId) {
                    $isClient = true;
                    break;
                }
            }
            if (!$isClient) {
                $this->forbidden("Consultores só podem personificar seus próprios clientes.");
            }
        }

        // Salva os dados do usuário original (admin/consultor) na sessão.
        $_SESSION['original_user'] = [
            'id' => $_SESSION['user_id'],
            'email' => $_SESSION['user_email'],
            'role' => $_SESSION['user_role']
        ];

        // Define a sessão para o usuário alvo.
        $_SESSION['user_id'] = $targetUser['id'];
        $_SESSION['user_email'] = $targetUser['email'];
        $_SESSION['user_role'] = $targetUser['role'];
        
        // Limpa o ID da conta ML ativa para evitar inconsistências.
        unset($_SESSION['active_ml_account_id']);

        set_flash_message('success', "Você agora está navegando como {$targetUser['email']}.");
        header('Location: /dashboard');
        exit();
    }

    /**
     * Para a personificação e restaura a sessão original.
     */
    public function stop(): void
    {
        if (!isset($_SESSION['original_user'])) {
            header('Location: /dashboard');
            exit();
        }

        $originalUser = $_SESSION['original_user'];

        // Restaura a sessão original.
        $_SESSION['user_id'] = $originalUser['id'];
        $_SESSION['user_email'] = $originalUser['email'];
        $_SESSION['user_role'] = $originalUser['role'];

        // Limpa os dados da personificação.
        unset($_SESSION['original_user']);
        unset($_SESSION['active_ml_account_id']);

        set_flash_message('success', 'Você retornou à sua conta original.');
        
        if ($originalUser['role'] === 'admin') {
            header('Location: /admin/dashboard');
        } else {
            header('Location: /dashboard');
        }
        exit();
    }

    /**
     * Redireciona com uma mensagem de erro.
     */
    private function redirectWithError(string $message): void
    {
        set_flash_message('error', $message);
        header('Location: /admin/dashboard');
        exit();
    }

    /**
     * Exibe uma página de acesso negado.
     */
    private function forbidden(string $message = 'Você não tem permissão para realizar esta ação.'): void
    {
        http_response_code(403);
        view('errors.403', ['message' => $message]);
        exit();
    }
}
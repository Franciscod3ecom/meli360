<?php
namespace App\Controllers;

use App\Models\User;
use App\Models\Anuncio;

class AdminController
{
    /**
     * Exibe o dashboard principal da área administrativa.
     */
    public function dashboard(): void
    {
        $userModel = new User();
        $users = $userModel->getAllUsersWithConnections();
        view('admin.dashboard', ['users' => $users]);
    }

    /**
     * Exibe os detalhes de um usuário específico para gerenciamento.
     */
    public function viewUser(int $userId): void
    {
        $userModel = new User();
        $anuncioModel = new Anuncio();

        $user = $userModel->findById($userId);
        if (!$user) {
            header('Location: /admin/dashboard');
            exit;
        }

        $anuncios = $anuncioModel->findAllBySaasUserId($userId);
        $consultants = $userModel->getConsultants();
        $assignedConsultant = $userModel->getAssignedConsultant($userId);

        view('admin.user_details', [
            'user' => $user,
            'anuncios' => $anuncios,
            'consultants' => $consultants,
            'assignedConsultant' => $assignedConsultant
        ]);
    }

    /**
     * Inicia a personificação de um usuário.
     */
    public function impersonateStart(int $userId): void
    {
        $userModel = new User();
        $targetUser = $userModel->findById($userId);

        if ($targetUser && $_SESSION['user_role'] === 'admin') {
            $_SESSION['original_user'] = [
                'user_id' => $_SESSION['user_id'],
                'user_email' => $_SESSION['user_email'],
                'user_role' => $_SESSION['user_role'],
            ];
            (new AuthController())->createUserSession($targetUser['id'], $targetUser['email'], $targetUser['role']);
            header('Location: /dashboard');
            exit;
        }
        header('Location: /admin/dashboard');
        exit;
    }

    /**
     * Para a sessão de personificação.
     */
    public function impersonateStop(): void
    {
        if (isset($_SESSION['original_user'])) {
            $originalUser = $_SESSION['original_user'];
            (new AuthController())->createUserSession($originalUser['user_id'], $originalUser['user_email'], $originalUser['user_role']);
            unset($_SESSION['original_user']);
        }
        header('Location: /admin/dashboard');
        exit;
    }

    /**
     * Atualiza os dados de um usuário (role, consultor, etc.).
     */
    public function updateUser(int $id): void
    {
        if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            view('errors.403');
            exit;
        }

        $userModel = new User();
        
        $newRole = $_POST['role'];
        if (in_array($newRole, ['user', 'consultant', 'admin'])) {
            $userModel->updateUserRole($id, $newRole);
        }

        $consultantId = $_POST['consultant_id'];
        if ($consultantId === 'none') {
            $userModel->unassignConsultant($id);
        } elseif (is_numeric($consultantId)) {
            $userModel->assignConsultant($id, (int)$consultantId);
        }

        set_flash_message('admin_success', 'Usuário atualizado com sucesso!');
        header('Location: /admin/user/' . $id);
        exit;
    }

    /**
     * Executa manualmente o script de sincronização de anúncios.
     */
    public function triggerSync(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo "========================================\n";
        echo " EXECUTANDO SINCRONIZAÇÃO MANUALMENTE \n";
        echo "========================================\n\n";
        set_time_limit(300);
        try {
            include_once BASE_PATH . '/scripts/sync_listings.php';
        } catch (\Exception $e) {
            echo "\n\n========================================\n";
            echo " ERRO FATAL DURANTE A EXECUÇÃO \n";
            echo "========================================\n\n";
            echo "Erro: " . $e->getMessage() . "\n";
            echo "Arquivo: " . $e->getFile() . "\n";
            echo "Linha: " . $e->getLine() . "\n";
        }
        echo "\n\n--- Execução manual finalizada ---";
    }
}
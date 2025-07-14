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
     * Atualiza os dados de um usuário (status, papel, etc.).
     */
    public function updateUser(int $userId): void
    {
        $userModel = new User();
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $role = $_POST['role'] ?? 'user';

        // Validação simples para o papel
        if (!in_array($role, ['user', 'consultant', 'admin'])) {
            set_flash_message('error', 'Papel de usuário inválido.');
            header("Location: /admin/user/{$userId}");
            exit();
        }

        $success = $userModel->updateUserStatusAndRole($userId, $isActive, $role);

        if ($success) {
            set_flash_message('success', 'Usuário atualizado com sucesso.');
        } else {
            set_flash_message('error', 'Falha ao atualizar o usuário.');
        }

        header("Location: /admin/user/{$userId}");
        exit();
    }

    /**
     * Inicia a personificação de um usuário.
     * O ID do admin original é salvo na sessão para poder retornar.
     */
    public function impersonateStart(int $userId): void
    {
        $userModel = new User();
        $targetUser = $userModel->findById($userId);

        // Só permite se o usuário atual for admin e não estiver já personificando
        if ($targetUser && $_SESSION['user_role'] === 'admin' && !isset($_SESSION['original_user_id'])) {
            // Salva o estado original do admin
            $_SESSION['original_user_id'] = $_SESSION['user_id'];
            $_SESSION['original_user_role'] = $_SESSION['user_role'];

            // Assume a identidade do usuário alvo
            $_SESSION['user_id'] = $targetUser['id'];
            $_SESSION['user_email'] = $targetUser['email'];
            $_SESSION['user_role'] = $targetUser['role'];
            
            set_flash_message('success', "Você agora está personificando " . htmlspecialchars($targetUser['email']));
            header('Location: /dashboard');
            exit;
        }
        
        set_flash_message('error', 'Não foi possível personificar o usuário.');
        header('Location: /admin/dashboard');
        exit;
    }

    /**
     * Para a sessão de personificação e retorna ao admin original.
     */
    public function impersonateStop(): void
    {
        if (isset($_SESSION['original_user_id'])) {
            $userModel = new User();
            $adminUser = $userModel->findById($_SESSION['original_user_id']);

            if ($adminUser) {
                // Restaura a sessão do admin
                $_SESSION['user_id'] = $adminUser['id'];
                $_SESSION['user_email'] = $adminUser['email'];
                $_SESSION['user_role'] = $adminUser['role'];

                // Limpa os dados da personificação
                unset($_SESSION['original_user_id']);
                unset($_SESSION['original_user_role']);

                set_flash_message('success', 'Personificação encerrada. Bem-vindo de volta!');
                header('Location: /admin/dashboard');
                exit;
            }
        }
        
        set_flash_message('error', 'Não havia uma sessão de personificação ativa.');
        header('Location: /dashboard'); // Redireciona para o dashboard normal se algo der errado
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
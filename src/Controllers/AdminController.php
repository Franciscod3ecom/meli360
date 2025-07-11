<?php
namespace App\Controllers;

use App\Models\User;

class AdminController
{
    /**
     * Exibe o dashboard principal da área administrativa.
     * Lista todos os usuários e suas conexões.
     */
    public function index(): void
    {
        $userModel = new User();
        // Este método buscará todos os usuários e seus dados de conexão ML
        $users = $userModel->getAllUsersWithConnections();

        view('admin.dashboard', ['users' => $users]);
    }

    /**
     * Exibe os detalhes de um usuário específico para gerenciamento.
     *
     * @param int $id O ID do usuário (saas_user_id).
     */
    public function showUserDetails(int $id): void
    {
        $userModel = new User();
        $anuncioModel = new \App\Models\Anuncio();

        $user = $userModel->findById($id);

        if (!$user) {
            set_flash_message('admin_error', 'Usuário não encontrado.');
            header('Location: /admin/dashboard');
            exit;
        }

        $anuncios = $anuncioModel->findAllBySaasUserId($id);
        $consultants = $userModel->getConsultants();
        $assignedConsultant = $userModel->getAssignedConsultant($id);

        view('admin.user_details', [
            'user' => $user,
            'anuncios' => $anuncios,
            'consultants' => $consultants,
            'assignedConsultant' => $assignedConsultant
        ]);
    }

    /**
     * Atualiza os dados de um usuário (role, consultor, etc.).
     *
     * @param int $id O ID do usuário a ser atualizado.
     */
    public function updateUser(int $id): void
    {
        if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            view('errors.403');
            exit;
        }

        $userModel = new User();
        
        // Atualiza a Role
        $newRole = $_POST['role'];
        if (in_array($newRole, ['user', 'consultant', 'admin'])) {
            $userModel->updateUserRole($id, $newRole);
        }

        // Atualiza a associação do consultor
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
     * Esta rota deve ser protegida para ser acessível apenas por administradores.
     *
     * @return void
     */
    public function triggerSync(): void
    {
        // Define um cabeçalho para que o navegador exiba o texto como pré-formatado
        header('Content-Type: text/plain; charset=utf-8');

        echo "========================================\n";
        echo " EXECUTANDO SINCRONIZAÇÃO MANUALMENTE \n";
        echo "========================================\n\n";

        // Altera o limite de tempo de execução para o script não parar no meio
        set_time_limit(300); // 5 minutos

        // Usa 'include' para executar o script de cron como se estivéssemos na linha de comando.
        // Toda a saída (echos) do script será exibida na tela do navegador.
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
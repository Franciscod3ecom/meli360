<?php
namespace App\Controllers;

use App\Models\User;
use App\Models\Plan;

class SettingsController
{
    /**
     * Exibe a página de configurações do usuário.
     */
    public function index(): void
    {
        $userModel = new User();
        $planModel = new Plan();

        $user = $userModel->findById($_SESSION['user_id']);
        $plans = $planModel->getActivePlans(); // Para futura exibição de planos

        view('settings.index', [
            'user' => $user,
            'plans' => $plans
        ]);
    }

    /**
     * Atualiza os dados do perfil do usuário.
     */
    public function update(): void
    {
        if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            view('errors.403');
            exit;
        }

        $saasUserId = $_SESSION['user_id'];
        $userModel = new User();

        // Atualizar nome
        if (!empty($_POST['name'])) {
            $userModel->updateName($saasUserId, $_POST['name']);
        }

        // Atualizar WhatsApp
        if (!empty($_POST['whatsapp'])) {
            $whatsapp_cleaned = preg_replace('/[^\d]/', '', $_POST['whatsapp']);
            if (preg_match('/^\d{10,11}$/', $whatsapp_cleaned)) {
                $whatsapp_jid = "55" . $whatsapp_cleaned . "@s.whatsapp.net";
                $userModel->updateWhatsappJid($saasUserId, $whatsapp_jid);
            }
        }

        // Atualizar Senha
        if (!empty($_POST['current_password']) && !empty($_POST['new_password']) && !empty($_POST['password_confirmation'])) {
            if ($_POST['new_password'] === $_POST['password_confirmation']) {
                $user = $userModel->findById($saasUserId);
                if (password_verify($_POST['current_password'], $user['password_hash'])) {
                    $newPasswordHash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                    $userModel->updatePassword($saasUserId, $newPasswordHash);
                    set_flash_message('settings_success', 'Senha alterada com sucesso!');
                } else {
                    set_flash_message('settings_error', 'A senha atual está incorreta.');
                }
            } else {
                set_flash_message('settings_error', 'A nova senha e a confirmação não coincidem.');
            }
        }

        if (!has_flash_message('settings_error')) {
            set_flash_message('settings_success', 'Perfil atualizado com sucesso!');
        }

        header('Location: /dashboard/settings');
        exit;
    }
}

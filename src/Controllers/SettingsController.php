<?php
namespace App\Controllers;

use App\Models\User;
use App\Core\Validator;

class SettingsController
{
    /**
     * Exibe a página de configurações do usuário.
     */
    public function index(): void
    {
        $userModel = new User();
        $user = $userModel->findById($_SESSION['user_id']);

        view('settings.index', ['user' => $user]);
    }

    /**
     * Processa a atualização dos dados do usuário.
     */
    public function update(): void
    {
        $userId = $_SESSION['user_id'];
        $name = $_POST['name'];
        $whatsappJid = $_POST['whatsapp_jid'];
        $password = $_POST['password'];
        $passwordConfirm = $_POST['password_confirm'];

        $errors = [];

        if (!Validator::string($name, 1, 255)) {
            $errors[] = "O nome é inválido.";
        }
        
        // Validação simples para o JID do WhatsApp (ex: 5511999998888@s.whatsapp.net)
        if (!empty($whatsappJid) && !filter_var("test@s.whatsapp.net", FILTER_VALIDATE_EMAIL) && strlen($whatsappJid) < 15) {
            $errors[] = "O número do WhatsApp (JID) parece inválido. Deve ser no formato 5511999998888@s.whatsapp.net";
        }

        if (!empty($password)) {
            if ($password !== $passwordConfirm) {
                $errors[] = "As senhas não coincidem.";
            }
            if (!Validator::string($password, 8)) {
                $errors[] = "A nova senha deve ter pelo menos 8 caracteres.";
            }
        }

        if (!empty($errors)) {
            set_flash_message('error', implode('<br>', $errors));
            header('Location: /dashboard/settings');
            exit;
        }

        $userModel = new User();
        $success = $userModel->updateProfile($userId, $name, $whatsappJid, $password);

        if ($success) {
            set_flash_message('success', 'Suas informações foram atualizadas com sucesso.');
        } else {
            set_flash_message('error', 'Ocorreu um erro ao atualizar suas informações.');
        }

        header('Location: /dashboard/settings');
        exit;
    }
}

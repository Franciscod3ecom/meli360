<?php
namespace App\Controllers;

use App\Models\MercadoLivreUser;

class AccountController
{
    /**
     * Define a conta do Mercado Livre ativa na sessão do usuário e redireciona.
     * @param int $ml_user_id O ID da conta do ML a ser ativada.
     */
    public function setActiveAccount(int $ml_user_id): void
    {
        // Validação de segurança: Verifica se a conta pertence ao usuário logado
        $mlUserModel = new MercadoLivreUser();
        if ($mlUserModel->doesAccountBelongToUser($_SESSION['user_id'], $ml_user_id)) {
            $_SESSION['active_ml_account_id'] = $ml_user_id;
            $_SESSION['active_ml_nickname'] = $mlUserModel->getNicknameByMlUserId($ml_user_id);
            log_message("Sessão atualizada. Conta ML ativa: {$ml_user_id} ({$_SESSION['active_ml_nickname']}) para SaaS User: {$_SESSION['user_id']}");
        } else {
            log_message("Tentativa de definir conta ML inválida ({$ml_user_id}) para SaaS User: {$_SESSION['user_id']}", "WARNING");
        }
        // Redireciona para a página de Análise, que é o destino natural após selecionar uma conta.
        header('Location: /dashboard/analysis');
        exit;
    }
}

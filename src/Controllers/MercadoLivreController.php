<?php
namespace App\Controllers;

use App\Models\MercadoLivreUser;

class MercadoLivreController
{
    /**
     * Inicia o fluxo de autorização OAuth2, redirecionando o usuário para o Mercado Livre.
     */
    public function redirectToAuth(): void
    {
        if (empty($_ENV['ML_APP_ID']) || empty($_ENV['ML_REDIRECT_URI'])) {
            die('Erro Crítico: ML_APP_ID ou ML_REDIRECT_URI não estão configurados no arquivo .env');
        }

        if (empty($_SESSION['oauth2state'])) {
            $_SESSION['oauth2state'] = bin2hex(random_bytes(16));
        }

        $authUrl = "https://auth.mercadolivre.com.br/authorization?" . http_build_query([
            'response_type' => 'code',
            'client_id'     => $_ENV['ML_APP_ID'],
            'state'         => $_SESSION['oauth2state'],
            'redirect_uri'  => $_ENV['ML_REDIRECT_URI'],
        ]);
        
        header('Location: ' . $authUrl);
        exit;
    }

    /**
     * Lida com o retorno do Mercado Livre após a autorização do usuário.
     */
    public function handleCallback(): void
    {
        if (empty($_GET['state']) || empty($_SESSION['oauth2state']) || $_GET['state'] !== $_SESSION['oauth2state']) {
            unset($_SESSION['oauth2state']);
            set_flash_message('error', 'Erro de segurança. Por favor, tente conectar novamente.');
            header('Location: /dashboard');
            exit;
        }
        unset($_SESSION['oauth2state']);

        if (empty($_GET['code'])) {
            set_flash_message('error', 'Código de autorização não recebido do Mercado Livre.');
            header('Location: /dashboard');
            exit;
        }

        $mlUserModel = new MercadoLivreUser();
        $tokenData = $mlUserModel->exchangeCodeForTokens($_GET['code']);

        if ($tokenData && isset($tokenData['access_token'])) {
            $accessToken = $tokenData['access_token'];

            // NOVA ETAPA: Buscar detalhes do usuário para obter o nickname
            $nickname = $this->fetchNickname($accessToken);
            $mlUserId = $tokenData['user_id'];

            // Salva tudo no banco de dados
            $mlUserModel->saveOrUpdateTokens(
                $_SESSION['user_id'],
                $mlUserId,
                $accessToken,
                $tokenData['refresh_token'],
                $tokenData['expires_in'],
                $nickname // Passa o nickname para o método de salvamento
            );

            set_flash_message('success', "Conta do Mercado Livre '{$nickname}' conectada com sucesso!");
            header('Location: /dashboard');
            exit;
        } else {
            set_flash_message('error', 'Erro ao obter os tokens do Mercado Livre. Tente novamente.');
            header('Location: /dashboard');
            exit;
        }
    }

    /**
     * Busca o nickname do usuário na API do Mercado Livre.
     *
     * @param string $accessToken
     * @return string|null
     */
    private function fetchNickname(string $accessToken): ?string
    {
        $ch = curl_init('https://api.mercadolibre.com/users/me');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            return $data['nickname'] ?? null;
        }
        return null;
    }

    /**
     * Coloca uma conta específica na fila para sincronização.
     * @param int $ml_user_id
     */
    public function requestSync(int $ml_user_id): void
    {
        log_message("SYNC_REQUEST: Recebida solicitação para ML User ID {$ml_user_id} pelo SaaS User ID {$_SESSION['user_id']}.");
        $mlUserModel = new \App\Models\MercadoLivreUser();

        if ($mlUserModel->doesAccountBelongToUser($_SESSION['user_id'], $ml_user_id)) {
            $success = $mlUserModel->updateSyncStatusByMlUserId($ml_user_id, 'QUEUED', 'Sincronização solicitada pelo usuário.');
            
            if ($success) {
                log_message("SYNC_REQUEST: Status para ML User ID {$ml_user_id} atualizado para QUEUED com sucesso.");
                header('Location: /dashboard/analysis?sync_status=requested');
            } else {
                log_message("SYNC_REQUEST: Falha ao atualizar o status no banco para ML User ID {$ml_user_id}.", "ERROR");
                header('Location: /dashboard/analysis?sync_status=db_error');
            }
        } else {
            log_message("SYNC_REQUEST: Tentativa de sincronizar conta não pertencente ao usuário.", "WARNING");
            header('Location: /dashboard/analysis?sync_status=permission_denied');
        }
        exit;
    }
}
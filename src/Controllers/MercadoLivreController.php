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
     * Troca o código de autorização por tokens e os salva no banco de dados.
     */
    public function handleCallback(): void
    {
        if (empty($_GET['state']) || empty($_SESSION['oauth2state']) || $_GET['state'] !== $_SESSION['oauth2state']) {
            unset($_SESSION['oauth2state']);
            die('Erro de segurança: State inválido. Por favor, tente conectar novamente.');
        }
        unset($_SESSION['oauth2state']);

        if (empty($_GET['code'])) {
            die('Erro: Código de autorização não recebido do Mercado Livre.');
        }

        $code = $_GET['code'];
        $tokenUrl = 'https://api.mercadolibre.com/oauth/token';
        $postData = http_build_query([
            'grant_type'    => 'authorization_code',
            'client_id'     => $_ENV['ML_APP_ID'],
            'client_secret' => $_ENV['ML_SECRET_KEY'],
            'code'          => $code,
            'redirect_uri'  => $_ENV['ML_REDIRECT_URI'],
        ]);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $tokenUrl, CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded']
        ]);
        $response = curl_exec($ch);
        $tokenData = json_decode($response, true);
        
        if (!isset($tokenData['access_token'])) {
             die('Erro ao obter os tokens do Mercado Livre. Resposta: ' . htmlspecialchars($response));
        }
        
        $userUrl = 'https://api.mercadolibre.com/users/me';
        curl_setopt_array($ch, [
            CURLOPT_URL => $userUrl, CURLOPT_POST => 0,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tokenData['access_token']]
        ]);
        $userResponse = curl_exec($ch);
        curl_close($ch);
        $userData = json_decode($userResponse, true);
        $nickname = $userData['nickname'] ?? 'N/A';
        
        $mlUserModel = new MercadoLivreUser();
        $mlUserModel->saveOrUpdateTokens(
            $_SESSION['user_id'],
            $tokenData['user_id'],
            $nickname,
            $tokenData['access_token'],
            $tokenData['refresh_token'],
            $tokenData['expires_in']
        );

        header('Location: /dashboard?status=ml_connected');
        exit;
    }

    /**
     * Solicita a sincronização para UMA conta específica do Mercado Livre.
     */
    public function requestSync(): void
    {
        if (!isset($_GET['ml_user_id']) || !is_numeric($_GET['ml_user_id'])) {
            header('Location: /dashboard/analysis?status=invalid_request');
            exit;
        }
        $mlUserId = (int)$_GET['ml_user_id'];
        $saasUserId = $_SESSION['user_id'];

        $mlUserModel = new MercadoLivreUser();
        
        $connection = $mlUserModel->findByMlUserId($mlUserId);
        if (!$connection || $connection['saas_user_id'] != $saasUserId) {
            header('Location: /dashboard/analysis?status=permission_denied');
            exit;
        }

        $success = $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'REQUESTED', 'Sincronização solicitada pelo usuário.');
        
        if ($success) {
            header('Location: /dashboard/analysis?status=sync_requested');
        } else {
            header('Location: /dashboard/analysis?status=sync_request_failed');
        }
        exit;
    }
}
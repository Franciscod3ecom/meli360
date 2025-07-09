<?php
/**
 * Cliente OAuth2 para a API do Mercado Livre.
 */

namespace App\Core\MercadoLivre;

class OAuthClient
{
    private string $appId;
    private string $clientSecret;
    private string $redirectUri;
    private const AUTH_URL = 'https://auth.mercadolivre.com.br/authorization';
    private const TOKEN_URL = 'https://api.mercadolibre.com/oauth/token';

    /**
     * Carrega as credenciais do .env.
     * @throws \Exception se as credenciais não estiverem configuradas.
     */
    public function __construct()
    {
        $this->appId = $_ENV['ML_APP_ID'] ?? '';
        $this->clientSecret = $_ENV['ML_SECRET_KEY'] ?? '';
        $this->redirectUri = $_ENV['ML_REDIRECT_URI'] ?? '';

        if (empty($this->appId) || empty($this->clientSecret) || empty($this->redirectUri)) {
            throw new \Exception('Credenciais do Mercado Livre (ML_APP_ID, ML_SECRET_KEY, ML_REDIRECT_URI) não configuradas no arquivo .env.');
        }
    }

    /**
     * Gera a URL de autorização para redirecionar o usuário.
     */
    public function getAuthorizationUrl(int $saasUserId): string
    {
        $_SESSION['ml_oauth_state'] = $saasUserId;
        $params = [
            'response_type' => 'code',
            'client_id'     => $this->appId,
            'redirect_uri'  => $this->redirectUri,
            'state'         => $saasUserId,
        ];
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Troca o código de autorização por um access token.
     */
    public function getAccessToken(string $code): ?array
    {
        $postData = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->appId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ];
        return $this->sendTokenRequest($postData);
    }

    private function sendTokenRequest(array $postData): ?array
    {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_HTTPHEADER => ['Accept: application/json']
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            return json_decode($response, true);
        }

        log_message("Falha na requisição de token para o ML. HTTP Code: $httpCode. Response: $response", 'ERROR');
        return null;
    }
}
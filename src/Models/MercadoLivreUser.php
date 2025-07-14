<?php
namespace App\Models;

use App\Core\Database;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;

class MercadoLivreUser
{
    private \PDO $db;
    private Key $key;

    public function __construct()
    {
        $this->db = Database::getInstance();
        if (empty($_ENV['ENCRYPTION_KEY'])) {
            throw new \Exception("Chave de criptografia (ENCRYPTION_KEY) não está definida no .env");
        }
        $this->key = Key::loadFromAsciiSafeString($_ENV['ENCRYPTION_KEY']);
    }

    public function exchangeCodeForTokens(string $code): ?array
    {
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
        curl_close($ch);
        $tokenData = json_decode($response, true);

        return isset($tokenData['access_token']) ? $tokenData : null;
    }

    public function getUserInfo(string $accessToken): ?array
    {
        $userUrl = 'https://api.mercadolivre.com/users/me';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $userUrl, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_RETURNTRANSFER => true
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true);
    }

    /**
     * Salva ou atualiza os tokens de um usuário do Mercado Livre no banco de dados.
     *
     * @param int    $saasUserId      ID do usuário no sistema SaaS.
     * @param int    $mlUserId        ID do usuário no Mercado Livre.
     * @param string $accessToken     Token de acesso.
     * @param string $refreshToken    Token para renovação.
     * @param int    $expiresIn       Tempo de expiração em segundos.
     * @param string|null $nickname   Nickname do usuário no Mercado Livre.
     * @return bool
     */
    public function saveOrUpdateTokens(int $saasUserId, int $mlUserId, string $accessToken, string $refreshToken, int $expiresIn, ?string $nickname): bool
    {
        $encryptedAccessToken = Crypto::encrypt($accessToken, $this->key);
        $encryptedRefreshToken = Crypto::encrypt($refreshToken, $this->key);
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

        // A query agora sempre atualiza o nickname, garantindo que ele nunca fique como "N/A"
        $sql = "INSERT INTO mercadolibre_users (saas_user_id, ml_user_id, nickname, access_token, refresh_token, expires_at, sync_status)
                VALUES (:saas_user_id, :ml_user_id, :nickname, :access_token, :refresh_token, :expires_at, 'NOT_SYNCED')
                ON DUPLICATE KEY UPDATE
                    access_token = VALUES(access_token),
                    refresh_token = VALUES(refresh_token),
                    expires_at = VALUES(expires_at),
                    nickname = VALUES(nickname),
                    sync_status = IF(sync_status = 'COMPLETED', 'NOT_SYNCED', sync_status),
                    updated_at = NOW()";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':saas_user_id' => $saasUserId,
            ':ml_user_id' => $mlUserId,
            ':nickname' => $nickname,
            ':access_token' => $encryptedAccessToken,
            ':refresh_token' => $encryptedRefreshToken,
            ':expires_at' => $expiresAt
        ]);
    }

    /**
     * Busca todas as contas de um usuário SaaS com estatísticas agregadas de anúncios.
     *
     * @param int $saasUserId
     * @return array
     */
    public function findAllBySaasUserIdWithStats(int $saasUserId): array
    {
        $sql = "SELECT 
                    mu.*,
                    (SELECT COUNT(*) FROM anuncios a WHERE a.ml_user_id = mu.ml_user_id) as total_anuncios,
                    (SELECT COUNT(*) FROM anuncios a WHERE a.ml_user_id = mu.ml_user_id AND a.status = 'active') as active_anuncios
                FROM mercadolibre_users mu
                WHERE mu.saas_user_id = :saas_user_id
                ORDER BY mu.nickname ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':saas_user_id' => $saasUserId]);
        return $stmt->fetchAll();
    }

    /**
     * Busca uma conta ML específica para um usuário SaaS.
     * Usado para validar o acesso na página de análise de conta.
     *
     * @param int $saasUserId
     * @param int $mlUserId
     * @return array|false
     */
    public function findByMlUserIdForUser(int $saasUserId, int $mlUserId): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM mercadolibre_users WHERE saas_user_id = :saas_user_id AND ml_user_id = :ml_user_id");
        $stmt->execute([':saas_user_id' => $saasUserId, ':ml_user_id' => $mlUserId]);
        return $stmt->fetch();
    }
    
    public function findAllBySaasUserId(int $saasUserId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM mercadolibre_users WHERE saas_user_id = :saas_user_id ORDER BY nickname ASC");
        $stmt->execute([':saas_user_id' => $saasUserId]);
        return $stmt->fetchAll();
    }
    
    public function findByMlUserId(int $mlUserId): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM mercadolibre_users WHERE ml_user_id = :ml_user_id LIMIT 1");
        $stmt->execute([':ml_user_id' => $mlUserId]);
        return $stmt->fetch();
    }

    /**
     * NOVO: Busca uma conexão específica garantindo que ela pertence ao usuário SaaS.
     */
    public function findBySaasUserIdAndMlUserId(int $saasUserId, int $mlUserId): array|false {
        $stmt = $this->db->prepare("SELECT * FROM mercadolibre_users WHERE saas_user_id = :saas_id AND ml_user_id = :ml_id LIMIT 1");
        $stmt->execute([':saas_id' => $saasUserId, ':ml_id' => $mlUserId]);
        return $stmt->fetch();
    }
    
    public function updateSyncStatusByMlUserId(int $mlUserId, string $status, ?string $message = null): bool
    {
        log_message("Atualizando status para ML User ID {$mlUserId}: Status='{$status}', Mensagem='{$message}'", "DEBUG");
        
        $this->db->beginTransaction();
        try {
            $sql = "UPDATE mercadolibre_users SET 
                        sync_status = :status, 
                        sync_last_message = :message,
                        updated_at = NOW()";

            // Se o status é QUEUED, reinicia o scroll_id para uma nova busca completa
            if ($status === 'QUEUED') {
                $sql .= ", sync_scroll_id = NULL";
            }

            $sql .= " WHERE ml_user_id = :ml_user_id";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                ':ml_user_id' => $mlUserId,
                ':status' => $status,
                ':message' => $message,
            ]);
            $this->db->commit();
            return $success;
        } catch (\Exception $e) {
            $this->db->rollBack();
            log_message("Erro ao atualizar status da sincronização: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    public function getValidAccessToken(int $saasUserId, int $mlUserId): ?string
    {
        log_message("TOKEN_MANAGER: Iniciando busca de token para ML User ID: {$mlUserId} (SaaS User: {$saasUserId}).");
        $connection = $this->findBySaasUserIdAndMlUserId($saasUserId, $mlUserId);
        
        if (!$connection) {
            log_message("TOKEN_MANAGER: Conexão não encontrada para a combinação saas_user_id/ml_user_id.", "ERROR");
            return null;
        }

        $expiresAt = new \DateTime($connection['token_expires_at']);
        if ($expiresAt < (new \DateTime())->modify('+10 minutes')) {
            log_message("TOKEN_MANAGER: Token para ml_user_id={$mlUserId} expirado ou perto de expirar. Tentando renovar...");
            return $this->refreshAndGetNewToken($connection);
        }

        try {
            log_message("TOKEN_MANAGER: Token para ml_user_id={$mlUserId} ainda é válido. Descriptografando...");
            $token = Crypto::decrypt($connection['access_token'], $this->key);
            log_message("TOKEN_MANAGER: Token descriptografado com sucesso para ml_user_id={$mlUserId}.");
            return $token;
        } catch (\Exception $e) {
            log_message("TOKEN_MANAGER: Falha CRÍTICA ao descriptografar access_token válido: " . $e->getMessage(), "CRITICAL");
            return null;
        }
    }

    private function refreshAndGetNewToken(array $connection): ?string
    {
        try {
            $refreshToken = Crypto::decrypt($connection['refresh_token'], $this->key);
        } catch (\Exception $e) {
            log_message("TOKEN_MANAGER: Falha CRÍTICA ao descriptografar refresh_token para ml_user_id={$connection['ml_user_id']}: " . $e->getMessage(), "CRITICAL");
            return null;
        }

        $tokenUrl = 'https://api.mercadolibre.com/oauth/token';
        $postData = http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $_ENV['ML_APP_ID'],
            'client_secret' => $_ENV['ML_SECRET_KEY'],
            'refresh_token' => $refreshToken,
        ]);

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $postData, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded']
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $tokenData = json_decode($response, true);

        if ($httpCode === 200 && isset($tokenData['access_token'])) {
            log_message("TOKEN_MANAGER: Refresh para ml_user_id={$connection['ml_user_id']} bem-sucedido. Salvando novos tokens.");
            $this->saveOrUpdateTokens(
                $connection['saas_user_id'], $connection['ml_user_id'], $connection['nickname'],
                $tokenData['access_token'], $tokenData['refresh_token'], $tokenData['expires_in']
            );
            return $tokenData['access_token'];
        } else {
            log_message("TOKEN_MANAGER: Falha no refresh da API para ml_user_id={$connection['ml_user_id']}. HTTP: {$httpCode}. Resposta: {$response}", "ERROR");
            $this->updateSyncStatusByMlUserId($connection['ml_user_id'], 'FAILED', 'Falha ao renovar token de acesso. Verifique a conexão da conta.');
            return null;
        }
    }
    
    public function updateAsaasCustomerId(int $userId, string $asaasCustomerId): bool
    {
        $sql = "UPDATE mercadolibre_users SET asaas_customer_id = :asaas_customer_id WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':asaas_customer_id' => $asaasCustomerId, ':id' => $userId]);
    }

    /**
     * Verifica se uma conta do Mercado Livre pertence a um usuário SaaS específico.
     *
     * @param int $saasUserId
     * @param int $mlUserId
     * @return bool
     */
    public function doesAccountBelongToUser(int $saasUserId, int $mlUserId): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM mercadolibre_users WHERE saas_user_id = :saas_user_id AND ml_user_id = :ml_user_id");
        $stmt->execute([':saas_user_id' => $saasUserId, ':ml_user_id' => $mlUserId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Busca o nickname de uma conta do Mercado Livre pelo seu ID.
     *
     * @param int $mlUserId
     * @return string|null
     */
    public function getNicknameByMlUserId(int $mlUserId): ?string
    {
        $stmt = $this->db->prepare("SELECT nickname FROM mercadolibre_users WHERE ml_user_id = :ml_user_id");
        $stmt->execute([':ml_user_id' => $mlUserId]);
        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    /**
     * Busca o saas_user_id a partir de um ml_user_id.
     */
    public function findSaasUserIdByMlUserId(int $mlUserId): ?int
    {
        $stmt = $this->db->prepare("SELECT saas_user_id FROM mercadolibre_users WHERE ml_user_id = :ml_user_id");
        $stmt->execute([':ml_user_id' => $mlUserId]);
        $result = $stmt->fetchColumn();
        return $result !== false ? (int)$result : null;
    }

    /**
     * Busca todas as conexões com um determinado status.
     *
     * @param string $status
     * @return array
     */
    public function findConnectionsByStatus(string $status): array
    {
        $stmt = $this->db->prepare("SELECT * FROM mercadolibre_users WHERE sync_status = :status");
        $stmt->execute([':status' => $status]);
        return $stmt->fetchAll();
    }

    /**
     * Busca todas as conexões de usuário que estão ativas no sistema.
     * @return array
     */
    public function findAllActiveConnections(): array
    {
        $sql = "SELECT * FROM mercadolibre_users WHERE is_active = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Busca a próxima conexão de usuário na fila para sincronização.
     */
    public function findNextInQueue(): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM mercadolibre_users WHERE sync_status = 'QUEUED' ORDER BY updated_at ASC LIMIT 1");
        $stmt->execute();
        return $stmt->fetch();
    }
}
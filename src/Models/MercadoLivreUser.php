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

    public function saveOrUpdateTokens(int $saasUserId, int $mlUserId, string $nickname, string $accessToken, string $refreshToken, int $expiresIn): bool
    {
        $encryptedAccessToken = Crypto::encrypt($accessToken, $this->key);
        $encryptedRefreshToken = Crypto::encrypt($refreshToken, $this->key);
        $expiresAt = (new \DateTimeImmutable())->modify("+" . $expiresIn . " seconds")->format('Y-m-d H:i:s');

        $sql = "INSERT INTO mercadolibre_users (saas_user_id, ml_user_id, nickname, access_token, refresh_token, token_expires_at, is_active)
                VALUES (:saas_user_id, :ml_user_id, :nickname, :access_token, :refresh_token, :expires_at, 1)
                ON DUPLICATE KEY UPDATE
                    saas_user_id = VALUES(saas_user_id), nickname = VALUES(nickname), access_token = VALUES(access_token),
                    refresh_token = VALUES(refresh_token), token_expires_at = VALUES(token_expires_at),
                    is_active = 1, updated_at = NOW()";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':saas_user_id' => $saasUserId, ':ml_user_id' => $mlUserId, ':nickname' => $nickname,
            ':access_token' => $encryptedAccessToken, ':refresh_token' => $encryptedRefreshToken, ':expires_at' => $expiresAt
        ]);
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
        log_message("Buscando token para ML User ID: {$mlUserId}", "DEBUG");
        $connection = $this->findByMlUserId($mlUserId);
        if (!$connection || $connection['saas_user_id'] != $saasUserId) {
            log_message("Conexão não encontrada ou não pertence ao usuário SaaS ID {$saasUserId} para ML User ID: {$mlUserId}", "ERROR");
            return null;
        }

        $expiresAt = new \DateTime($connection['token_expires_at']);
        if ($expiresAt < (new \DateTime())->modify('+5 minutes')) {
            log_message("Token para {$mlUserId} expirado ou perto de expirar. Renovando...");
            return $this->refreshAndGetNewToken($connection);
        }

        try {
            log_message("Token para {$mlUserId} válido. Descriptografando...", "DEBUG");
            return Crypto::decrypt($connection['access_token'], $this->key);
        } catch (\Exception $e) {
            log_message("Falha ao descriptografar access token para {$mlUserId}: " . $e->getMessage(), "ERROR");
            return null;
        }
    }

    private function refreshAndGetNewToken(array $connection): ?string
    {
        try {
            $refreshToken = Crypto::decrypt($connection['refresh_token'], $this->key);
        } catch (\Exception $e) {
            log_message("Falha ao descriptografar refresh token para {$connection['ml_user_id']}: " . $e->getMessage(), "ERROR");
            return null;
        }

        $tokenUrl = 'https://api.mercadolibre.com/oauth/token';
        $postData = http_build_query([
            'grant_type'    => 'refresh_token', 'client_id' => $_ENV['ML_APP_ID'],
            'client_secret' => $_ENV['ML_SECRET_KEY'], 'refresh_token' => $refreshToken,
        ]);
        
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL => $tokenUrl, CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $postData, CURLOPT_RETURNTRANSFER => true]);
        $response = curl_exec($ch);
        curl_close($ch);
        $tokenData = json_decode($response, true);
        
        if (isset($tokenData['access_token'])) {
            log_message("Token para {$connection['ml_user_id']} renovado com sucesso.");
            $this->saveOrUpdateTokens(
                $connection['saas_user_id'], $connection['ml_user_id'], $connection['nickname'],
                $tokenData['access_token'], $tokenData['refresh_token'], $tokenData['expires_in']
            );
            return $tokenData['access_token'];
        }
        
        log_message("Falha ao renovar token para {$connection['ml_user_id']}. Resposta da API: " . $response, "ERROR");
        return null;
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
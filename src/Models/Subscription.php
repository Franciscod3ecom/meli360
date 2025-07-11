<?php
namespace App\Models;

use App\Core\Database;
use PDO;

class Subscription
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Cria uma nova assinatura para um usuário.
     *
     * @param int $saasUserId
     * @param int $planId
     * @param string $asaasSubscriptionId
     * @param string $status
     * @param string|null $expiresAt
     * @return int|false O ID da nova assinatura ou false em caso de falha.
     */
    public function create(int $saasUserId, int $planId, ?string $asaasSubscriptionId, string $status, ?string $expiresAt): int|false
    {
        $sql = "INSERT INTO subscriptions (saas_user_id, plan_id, asaas_subscription_id, status, expires_at)
                VALUES (:saas_user_id, :plan_id, :asaas_subscription_id, :status, :expires_at)";
        
        $stmt = $this->db->prepare($sql);
        $success = $stmt->execute([
            ':saas_user_id' => $saasUserId,
            ':plan_id' => $planId,
            ':asaas_subscription_id' => $asaasSubscriptionId,
            ':status' => $status,
            ':expires_at' => $expiresAt
        ]);

        return $success ? (int)$this->db->lastInsertId() : false;
    }

    /**
     * Atualiza o status e a data de expiração de uma assinatura.
     *
     * @param int $subscriptionId
     * @param string $status
     * @param string $expiresAt
     * @return bool
     */
    public function updateStatus(int $subscriptionId, string $status, string $expiresAt): bool
    {
        $sql = "UPDATE subscriptions SET status = :status, expires_at = :expires_at WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':status' => $status,
            ':expires_at' => $expiresAt,
            ':id' => $subscriptionId
        ]);
    }
}

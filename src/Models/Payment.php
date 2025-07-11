<?php
namespace App\Models;

use App\Core\Database;
use PDO;

class Payment
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Cria um novo registro de pagamento.
     *
     * @param array $data Os dados do pagamento vindos do Asaas.
     * @return int|false O ID do novo pagamento ou false em caso de falha.
     */
    public function create(array $data): int|false
    {
        $sql = "INSERT INTO payments (saas_user_id, subscription_id, asaas_payment_id, amount, status, billing_type, invoice_url, due_date)
                VALUES (:saas_user_id, :subscription_id, :asaas_payment_id, :amount, :status, :billing_type, :invoice_url, :due_date)";
        
        $stmt = $this->db->prepare($sql);
        $success = $stmt->execute($data);

        return $success ? (int)$this->db->lastInsertId() : false;
    }

    /**
     * Atualiza o status de um pagamento a partir do seu ID do Asaas.
     *
     * @param string $asaasPaymentId
     * @param string $status
     * @return bool
     */
    public function updateStatusByAsaasId(string $asaasPaymentId, string $status): bool
    {
        $sql = "UPDATE payments SET status = :status, payment_date = IF(:status = 'CONFIRMED' OR :status = 'RECEIVED', CURDATE(), payment_date) WHERE asaas_payment_id = :asaas_payment_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':status' => $status, ':asaas_payment_id' => $asaasPaymentId]);
    }

    /**
     * Busca um pagamento pelo seu ID do Asaas.
     *
     * @param string $asaasPaymentId
     * @return array|false
     */
    public function findByAsaasId(string $asaasPaymentId): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM payments WHERE asaas_payment_id = :asaas_payment_id");
        $stmt->execute([':asaas_payment_id' => $asaasPaymentId]);
        return $stmt->fetch();
    }
}

<?php
namespace App\Models;

use App\Core\Database;
use PDO;

class Plan
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Busca todos os planos ativos no banco de dados.
     *
     * @return array
     */
    public function getActivePlans(): array
    {
        $stmt = $this->db->prepare("SELECT * FROM plans WHERE is_active = 1 ORDER BY price ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Busca um plano específico pelo seu ID.
     *
     * @param int $id
     * @return array|false
     */
    public function findById(int $id): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM plans WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
}

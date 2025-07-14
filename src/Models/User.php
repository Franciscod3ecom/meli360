<?php

/**
 * Modelo User
 *
 * Responsável por todas as interações com a tabela `saas_users` no banco de dados.
 * Segue os princípios de separação de responsabilidades, contendo apenas
 * a lógica de acesso e manipulação dos dados de usuários.
 */

namespace App\Models;

use App\Core\Database;
use PDO;

class User
{
    /**
     * @var PDO A instância da conexão com o banco de dados.
     */
    private PDO $db;

    /**
     * Construtor da classe User.
     * Obtém a instância da conexão com o banco de dados.
     */
    public function __construct()
    {
        // Obtém a conexão PDO usando nossa classe Singleton
        $this->db = Database::getInstance();
    }

    /**
     * Encontra um usuário pelo seu endereço de e-mail.
     *
     * @param string $email O e-mail do usuário a ser encontrado.
     * @return array|false Retorna um array com os dados do usuário se encontrado, ou false caso contrário.
     */
    public function findByEmail(string $email): array|false
    {
        // Prepara a query para buscar o usuário pelo e-mail, que é uma chave única.
        $stmt = $this->db->prepare("SELECT * FROM saas_users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        return $stmt->fetch();
    }

    /**
     * Encontra um usuário pelo seu ID.
     *
     * @param int $id O ID do usuário a ser encontrado.
     * @return array|false Retorna um array com os dados do usuário se encontrado, ou false caso contrário.
     */
    public function findById(int $id): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM saas_users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    /**
     * Cria um novo usuário no banco de dados.
     *
     * @param string $name O nome completo do usuário.
     * @param string $email O endereço de e-mail do usuário.
     * @param string $passwordHash O hash da senha do usuário.
     * @param string $role O papel do usuário ('user', 'consultant', 'admin').
     * @param string|null $whatsappJid O JID do WhatsApp do usuário.
     * @return int|false Retorna o ID do usuário recém-criado ou false em caso de falha.
     */
    public function create(string $name, string $email, string $passwordHash, string $role = 'user', ?string $whatsappJid = null): int|false
    {
        $sql = "INSERT INTO saas_users (name, email, password_hash, role, whatsapp_jid, is_active) 
                VALUES (:name, :email, :password_hash, :role, :whatsapp_jid, 1)";
        
        $stmt = $this->db->prepare($sql);
        
        $success = $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':role' => $role,
            ':whatsapp_jid' => $whatsappJid
        ]);

        if ($success) {
            // Retorna o ID da última linha inserida.
            return (int) $this->db->lastInsertId();
        }

        return false;
    }

    /**
     * Atualiza as configurações de um usuário (nome e WhatsApp).
     *
     * @param int $userId O ID do usuário a ser atualizado.
     * @param string $name O novo nome do usuário.
     * @param string|null $whatsapp O novo número de WhatsApp.
     * @return bool Retorna true em caso de sucesso, false em caso de falha.
     */
    public function updateSettings(int $userId, string $name, ?string $whatsapp): bool
    {
        $sql = "UPDATE saas_users SET name = :name, whatsapp_number = :whatsapp WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute([
            ':id' => $userId,
            ':name' => $name,
            ':whatsapp' => $whatsapp
        ]);
    }

    /**
     * Atualiza o status (ativo/inativo) e o papel de um usuário.
     *
     * @param int $userId O ID do usuário a ser atualizado.
     * @param int $isActive O novo status (1 para ativo, 0 para inativo).
     * @param string $role O novo papel do usuário.
     * @return bool Retorna true em caso de sucesso, false em caso de falha.
     */
    public function updateUserStatusAndRole(int $userId, int $isActive, string $role): bool
    {
        $sql = "UPDATE saas_users SET is_active = :is_active, role = :role WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':id' => $userId,
            ':is_active' => $isActive,
            ':role' => $role
        ]);
    }

    /**
     * Busca todos os usuários e suas conexões com o Mercado Livre.
     *
     * @return array
     */
    public function getAllUsersWithConnections(): array
    {
        $sql = "SELECT 
                    u.id as saas_user_id,
                    u.name,
                    u.email,
                    u.created_at as user_created_at,
                    mlu.ml_user_id,
                    mlu.nickname,
                    mlu.sync_status,
                    mlu.sync_last_message,
                    mlu.updated_at as connection_updated_at
                FROM saas_users u
                LEFT JOIN mercadolibre_users mlu ON u.id = mlu.saas_user_id
                ORDER BY u.created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Busca todos os clientes associados a um consultor.
     *
     * @param int $consultantId O ID do consultor.
     * @return array
     */
    public function findClientsByConsultantId(int $consultantId): array
    {
        $sql = "SELECT 
                    u.id,
                    u.name,
                    u.email,
                    u.created_at
                FROM saas_users u
                JOIN consultant_clients cc ON u.id = cc.client_id
                WHERE cc.consultant_id = :consultant_id
                ORDER BY u.name ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':consultant_id' => $consultantId]);
        return $stmt->fetchAll();
    }

    /**
     * Busca todos os usuários com a role 'consultant'.
     * @return array
     */
    public function getConsultants(): array
    {
        $stmt = $this->db->prepare("SELECT id, name FROM saas_users WHERE role = 'consultant' ORDER BY name ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Busca o consultor atualmente associado a um cliente.
     * @param int $clientId O ID do cliente.
     * @return array|false
     */
    public function getAssignedConsultant(int $clientId): array|false
    {
        $sql = "SELECT u.id, u.name 
                FROM saas_users u
                JOIN consultant_clients cc ON u.id = cc.consultant_id
                WHERE cc.client_id = :client_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':client_id' => $clientId]);
        return $stmt->fetch();
    }

    /**
     * Associa um cliente a um consultor. Remove qualquer associação anterior.
     * @param int $clientId
     * @param int $consultantId
     * @return void
     */
    public function assignConsultant(int $clientId, int $consultantId): void
    {
        // Primeiro, remove qualquer associação existente para evitar duplicatas.
        $this->unassignConsultant($clientId);

        // Insere a nova associação.
        $sql = "INSERT INTO consultant_clients (client_id, consultant_id) VALUES (:client_id, :consultant_id)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':client_id' => $clientId, ':consultant_id' => $consultantId]);
    }

    /**
     * Desassocia um cliente de qualquer consultor.
     * @param int $clientId
     * @return void
     */
    public function unassignConsultant(int $clientId): void
    {
        $sql = "DELETE FROM consultant_clients WHERE client_id = :client_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':client_id' => $clientId]);
    }

    /**
     * Atualiza a role de um usuário.
     * @param int $userId
     * @param string $newRole
     * @return bool
     */
    public function updateUserRole(int $userId, string $newRole): bool
    {
        $sql = "UPDATE saas_users SET role = :role WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':role' => $newRole, ':id' => $userId]);
    }

    /**
     * Atualiza o ID do cliente Asaas para um usuário.
     * @param int $userId
     * @param string $asaasCustomerId
     * @return bool
     */
    public function updateAsaasCustomerId(int $userId, string $asaasCustomerId): bool
    {
        $sql = "UPDATE saas_users SET asaas_customer_id = :asaas_customer_id WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':asaas_customer_id' => $asaasCustomerId, ':id' => $userId]);
    }

    /**
     * Atualiza o nome de um usuário.
     */
    public function updateName(int $userId, string $name): bool
    {
        $stmt = $this->db->prepare("UPDATE saas_users SET name = :name WHERE id = :id");
        return $stmt->execute([':name' => $name, ':id' => $userId]);
    }

    /**
     * Atualiza o JID do WhatsApp de um usuário.
     */
    public function updateWhatsappJid(int $userId, ?string $jid): bool
    {
        $stmt = $this->db->prepare("UPDATE saas_users SET whatsapp_jid = :jid WHERE id = :id");
        return $stmt->execute([':jid' => $jid, ':id' => $userId]);
    }

    /**
     * Atualiza a senha de um usuário.
     */
    public function updatePassword(int $userId, string $passwordHash): bool
    {
        $stmt = $this->db->prepare("UPDATE saas_users SET password_hash = :password_hash WHERE id = :id");
        return $stmt->execute([':password_hash' => $passwordHash, ':id' => $userId]);
    }
}
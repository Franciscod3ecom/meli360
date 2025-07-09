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
     * @param string $passwordHash O hash da senha do usuário, já processado com password_hash().
     * @param string $role O papel do usuário ('user', 'consultant', 'admin').
     * @return int|false Retorna o ID do usuário recém-criado em caso de sucesso, ou false em caso de falha.
     */
    public function create(string $name, string $email, string $passwordHash, string $role = 'user'): int|false
    {
        $sql = "INSERT INTO saas_users (name, email, password_hash, role, is_active) 
                VALUES (:name, :email, :password_hash, :role, 1)";
        
        $stmt = $this->db->prepare($sql);
        
        $success = $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':role' => $role
        ]);

        if ($success) {
            // Retorna o ID da última linha inserida.
            return (int) $this->db->lastInsertId();
        }

        return false;
    }

    // Futuramente, adicionaremos mais métodos aqui, como:
    // public function updatePassword(int $id, string $newPasswordHash) { ... }
    // public function updateProfile(int $id, array $data) { ... }
    // public function getAllUsers() { ... } // Para o painel de admin
}
<?php
/**
 * Modelo Anuncio
 *
 * Responsável pelas interações com a tabela `anuncios` no banco de dados.
 */

namespace App\Models;

use App\Core\Database;
use PDO;

class Anuncio
{
    /**
     * @var PDO A instância da conexão com o banco de dados.
     */
    private PDO $db;

    /**
     * Construtor da classe Anuncio.
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Busca todos os anúncios de um usuário específico, com paginação.
     *
     * @param int $saasUserId O ID do usuário da nossa plataforma.
     * @param int $limit O número de registros por página.
     * @param int $offset O deslocamento para a página.
     * @return array Retorna um array de anúncios.
     */
    public function findAllByUserId(int $saasUserId, int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT * FROM anuncios 
                WHERE saas_user_id = :saas_user_id 
                ORDER BY total_sales DESC, date_created DESC
                LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':saas_user_id', $saasUserId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }

    /**
     * Conta o número total de anúncios de um usuário.
     *
     * @param int $saasUserId O ID do usuário.
     * @return int O total de anúncios.
     */
    public function countByUserId(int $saasUserId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM anuncios WHERE saas_user_id = :saas_user_id");
        $stmt->execute([':saas_user_id' => $saasUserId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Insere uma lista de IDs de anúncios no banco de dados, ignorando duplicados.
     * Usado na fase inicial da sincronização para salvar todos os IDs rapidamente.
     *
     * @param int $saasUserId
     * @param int $mlUserId
     * @param array $itemIds Array de IDs de anúncios (ex: ['MLB123', 'MLB456']).
     * @return int O número de linhas afetadas.
     */
    public function bulkInsertIds(int $saasUserId, int $mlUserId, array $itemIds): int
    {
        if (empty($itemIds)) {
            return 0;
        }

        // Prepara uma query de inserção múltipla
        $sql = "INSERT INTO anuncios (saas_user_id, ml_user_id, ml_item_id, sync_status) VALUES ";
        $placeholders = [];
        $values = [];

        foreach ($itemIds as $itemId) {
            $placeholders[] = '(?, ?, ?, 0)';
            $values[] = $saasUserId;
            $values[] = $mlUserId;
            $values[] = $itemId;
        }

        $sql .= implode(', ', $placeholders);
        // Adiciona a cláusula ON DUPLICATE KEY UPDATE para não fazer nada se o anúncio já existir,
        // evitando erros de chave duplicada.
        $sql .= " ON DUPLICATE KEY UPDATE ml_item_id = VALUES(ml_item_id)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);

        return $stmt->rowCount();
    }

    // Futuramente, adicionaremos um método 'updateDetails' para preencher os outros campos
    // após a sincronização detalhada de cada anúncio.
/**
     * Atualiza os detalhes de um anúncio no banco de dados.
     *
     * @param string $mlItemId
     * @param array $details Array com os detalhes vindos da API.
     * @return bool
     */
    public function updateDetails(string $mlItemId, array $details): bool
    {
        $sql = "UPDATE anuncios SET 
                    title = :title,
                    price = :price,
                    stock = :stock,
                    status = :status,
                    permalink = :permalink,
                    thumbnail = :thumbnail,
                    date_created = :date_created,
                    health = :health,
                    category_id = :category_id,
                    has_variations = :has_variations,
                    shipping_mode = :shipping_mode,
                    is_free_shipping = :is_free_shipping,
                    sku = :sku,
                    sync_status = 1, -- Marca como sincronizado
                    last_sync_at = NOW()
                WHERE ml_item_id = :ml_item_id";
        
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute([
            ':title' => $details['title'],
            ':price' => $details['price'],
            ':stock' => $details['available_quantity'],
            ':status' => $details['status'],
            ':permalink' => $details['permalink'],
            ':thumbnail' => $details['thumbnail'],
            ':date_created' => (new \DateTime($details['date_created']))->format('Y-m-d H:i:s'),
            ':health' => $details['health'],
            ':category_id' => $details['category_id'],
            ':has_variations' => !empty($details['variations']),
            ':shipping_mode' => $details['shipping']['mode'] ?? 'not_specified',
            ':is_free_shipping' => $details['shipping']['free_shipping'] ?? 0,
            ':sku' => $details['seller_custom_field'],
            ':ml_item_id' => $mlItemId,
        ]);
    }
}
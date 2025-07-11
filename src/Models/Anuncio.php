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

    // ===================================================================
    // MÉTODOS DA FASE 1: COLETA DE IDS
    // ===================================================================

    /**
     * Limpa todos os anúncios de uma conta ML antes de uma nova sincronização.
     */
    public function clearByMlUserId(int $mlUserId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM anuncios WHERE ml_user_id = :ml_user_id");
        return $stmt->execute([':ml_user_id' => $mlUserId]);
    }

    /**
     * Insere uma grande quantidade de IDs de anúncios de uma vez, ignorando duplicados.
     */
    public function bulkInsertIds(int $saasUserId, int $mlUserId, array $itemIds): int
    {
        if (empty($itemIds)) {
            return 0;
        }

        $sql = "INSERT IGNORE INTO anuncios (ml_item_id, saas_user_id, ml_user_id, sync_status) VALUES ";
        $placeholders = [];
        $values = [];
        foreach ($itemIds as $itemId) {
            $placeholders[] = '(?, ?, ?, 0)';
            $values[] = $itemId;
            $values[] = $saasUserId;
            $values[] = $mlUserId;
        }
        $sql .= implode(', ', $placeholders);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        return $stmt->rowCount();
    }

    // ===================================================================
    // MÉTODOS DA FASE 2: DETALHAMENTO BÁSICO E ANÁLISE PROFUNDA
    // ===================================================================

    /**
     * Encontra um lote de anúncios para a próxima etapa do pipeline.
     */
    public function findAnunciosToProcess(int $status, int $limit = 20): array
    {
        $sql = "SELECT * FROM anuncios WHERE sync_status = :status AND (last_sync_attempt IS NULL OR last_sync_attempt < NOW() - INTERVAL 1 HOUR) LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':status', $status, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Atualiza os detalhes básicos de múltiplos anúncios de uma vez.
     */
    public function bulkUpdateBasicDetails(array $itemsData): int
    {
        if (empty($itemsData)) return 0;
        $updatedCount = 0;
        $sql = "UPDATE anuncios SET
                    title = :title, sku = :sku, price = :price, stock = :stock,
                    total_visits = :total_visits, total_sales = :total_sales, health = :health,
                    status = :status, permalink = :permalink, thumbnail = :thumbnail, 
                    category_id = :category_id, has_variations = :has_variations,
                    data = :data, sync_status = 1, last_sync_attempt = NOW()
                WHERE ml_item_id = :ml_item_id";
        $stmt = $this->db->prepare($sql);

        foreach ($itemsData as $item) {
            if (isset($item['code']) && $item['code'] === 200 && isset($item['body'])) {
                $body = $item['body'];
                
                // Extrai o SKU do primeiro atributo, se existir
                $sku = null;
                if (!empty($body['attributes'])) {
                    foreach ($body['attributes'] as $attribute) {
                        if ($attribute['id'] === 'SELLER_SKU' && !empty($attribute['value_name'])) {
                            $sku = $attribute['value_name'];
                            break;
                        }
                    }
                }

                $success = $stmt->execute([
                    ':title' => $body['title'],
                    ':sku' => $sku,
                    ':price' => $body['price'],
                    ':stock' => $body['available_quantity'],
                    ':total_visits' => $body['visits'] ?? 0, // Extraído do endpoint /visits/items (não vem no /items) - Placeholder
                    ':total_sales' => $body['sold_quantity'] ?? 0,
                    ':health' => $body['health'] ?? 0.00,
                    ':status' => $body['status'],
                    ':permalink' => $body['permalink'],
                    ':thumbnail' => $body['thumbnail'],
                    ':category_id' => $body['category_id'],
                    ':has_variations' => !empty($body['variations']),
                    ':data' => json_encode($body),
                    ':ml_item_id' => $body['id']
                ]);
                if ($success) $updatedCount++;
            } else {
                $this->markAsFailed($item['body']['id'] ?? null);
            }
        }
        return $updatedCount;
    }

    /**
     * Atualiza um anúncio com os dados da análise profunda (frete, categoria).
     */
    public function updateDeepAnalysis(string $mlItemId, ?string $shippingData, ?string $categoryData): bool
    {
        $shippingJson = json_decode($shippingData, true);
        $shippingMode = $shippingJson['mode'] ?? null;
        $isFreeShipping = false;
        if(isset($shippingJson['options'])){
            foreach($shippingJson['options'] as $option){
                if($option['free_method']){
                    $isFreeShipping = true;
                    break;
                }
            }
        }
        $logisticType = $shippingJson['logistic_type'] ?? null;

        $sql = "UPDATE anuncios SET
                    shipping_data = :shipping_data, category_data = :category_data,
                    shipping_mode = :shipping_mode, is_free_shipping = :is_free_shipping, logistic_type = :logistic_type,
                    sync_status = 2, last_sync_attempt = NOW()
                WHERE ml_item_id = :ml_item_id";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':shipping_data' => $shippingData,
            ':category_data' => $categoryData,
            ':shipping_mode' => $shippingMode,
            ':is_free_shipping' => $isFreeShipping,
            ':logistic_type' => $logisticType,
            ':ml_item_id' => $mlItemId
        ]);
    }

    /**
     * Marca um anúncio como falho para evitar retentativas constantes.
     */
    public function markAsFailed(?string $mlItemId): bool
    {
        if (!$mlItemId) return false;
        $stmt = $this->db->prepare("UPDATE anuncios SET sync_status = 9, last_sync_attempt = NOW() WHERE ml_item_id = :ml_item_id");
        return $stmt->execute([':ml_item_id' => $mlItemId]);
    }

    // ===================================================================
    // MÉTODOS DE CONSULTA PARA APLICAÇÃO
    // ===================================================================

    /**
     * Busca todos os anúncios de uma conta ML específica de um usuário SaaS, com paginação.
     */
    public function findAllByMlUserId(int $saasUserId, int $mlUserId, int $limit, int $offset): array
    {
        $sql = "SELECT * FROM anuncios 
                WHERE saas_user_id = :saas_user_id AND ml_user_id = :ml_user_id
                ORDER BY status, title ASC
                LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':saas_user_id', $saasUserId, PDO::PARAM_INT);
        $stmt->bindValue(':ml_user_id', $mlUserId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Conta o total de anúncios de uma conta ML específica de um usuário SaaS.
     */
    public function countByMlUserId(int $saasUserId, int $mlUserId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM anuncios WHERE saas_user_id = :saas_user_id AND ml_user_id = :ml_user_id");
        $stmt->execute([':saas_user_id' => $saasUserId, ':ml_user_id' => $mlUserId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Conta o total de anúncios de uma conta ML com determinados status.
     *
     * @param int $mlUserId
     * @param array $statuses
     * @return int
     */
    public function countByStatus(int $mlUserId, array $statuses): int
    {
        $inQuery = implode(',', array_fill(0, count($statuses), '?'));
        $sql = "SELECT COUNT(*) FROM anuncios WHERE ml_user_id = ? AND sync_status IN ($inQuery)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$mlUserId], $statuses));
        return (int) $stmt->fetchColumn();
    }
}
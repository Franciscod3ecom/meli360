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
     * Busca todos os anúncios sincronizados de um usuário da plataforma para exibição.
     */
    public function findAllBySaasUserId(int $saasUserId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM anuncios WHERE saas_user_id = :saas_user_id AND sync_status = 1 ORDER BY status, title ASC");
        $stmt->execute([':saas_user_id' => $saasUserId]);
        return $stmt->fetchAll();
    }

    /**
     * Conta o número total de anúncios de um usuário (sincronizados ou não).
     */
    public function countBySaasUserId(int $saasUserId): array
    {
        $stmt = $this->db->prepare("SELECT sync_status, COUNT(*) as count FROM anuncios WHERE saas_user_id = :saas_user_id GROUP BY sync_status");
        $stmt->execute([':saas_user_id' => $saasUserId]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    // --- MÉTODOS PARA FASE 1: COLETA DE IDS ---

    /**
     * Limpa todos os anúncios de um usuário do ML antes de uma nova sincronização.
     */
    public function clearAllByMlUserId(int $mlUserId): bool
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

    // --- MÉTODOS PARA FASE 2: DETALHAMENTO EM LOTES ---

    /**
     * Encontra um lote de anúncios que ainda não foram detalhados.
     */
    public function findAnunciosToDetail(int $limit = 20): array
    {
        $stmt = $this->db->prepare("SELECT * FROM anuncios WHERE sync_status = 0 AND (last_sync_attempt IS NULL OR last_sync_attempt < NOW() - INTERVAL 1 HOUR) LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Encontra um lote de anúncios prontos para a análise profunda (frete, etc.).
     */
    public function findAnunciosToAnalyze(int $limit = 10): array
    {
        // Pega anúncios com status 1 (Detalhes Básicos OK)
        $stmt = $this->db->prepare("SELECT ml_item_id, category_id FROM anuncios WHERE sync_status = 1 AND (last_sync_attempt IS NULL OR last_sync_attempt < NOW() - INTERVAL 1 HOUR) LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Atualiza um anúncio com os dados da análise profunda (frete, categoria).
     */
    public function updateDeepAnalysis(string $mlItemId, ?string $shippingData, ?string $categoryData): bool
    {
        $sql = "UPDATE anuncios SET
                    shipping_data = :shipping_data,
                    category_data = :category_data,
                    sync_status = 2, -- Marca como Análise Profunda OK
                    last_sync_attempt = NOW()
                WHERE ml_item_id = :ml_item_id";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':shipping_data' => $shippingData,
            ':category_data' => $categoryData,
            ':ml_item_id' => $mlItemId
        ]);
    }

    /**
     * Atualiza os detalhes de múltiplos anúncios de uma vez.
     */
    public function bulkUpdateDetails(array $itemsData): int
    {
        if (empty($itemsData)) {
            return 0;
        }

        $updatedCount = 0;
        $sql = "UPDATE anuncios SET
                    title = :title,
                    price = :price,
                    status = :status,
                    permalink = :permalink,
                    thumbnail = :thumbnail,
                    data = :data,
                    sync_status = 1,
                    last_sync_attempt = NOW()
                WHERE ml_item_id = :ml_item_id";
        
        $stmt = $this->db->prepare($sql);

        foreach ($itemsData as $item) {
            // Verifica se a API retornou o corpo do item com sucesso
            if (isset($item['code']) && $item['code'] === 200 && isset($item['body'])) {
                $body = $item['body'];
                $success = $stmt->execute([
                    ':title' => $body['title'],
                    ':price' => $body['price'],
                    ':status' => $body['status'],
                    ':permalink' => $body['permalink'],
                    ':thumbnail' => $body['thumbnail'],
                    ':data' => json_encode($body),
                    ':ml_item_id' => $body['id']
                ]);
                if ($success) {
                    $updatedCount++;
                }
            } else {
                // Se houve erro na API para este item específico, marca como falho
                $itemId = $item['body']['id'] ?? null;
                if ($itemId) {
                    $this->markAsFailed($itemId);
                }
            }
        }
        return $updatedCount;
    }

    /**
     * Marca um anúncio como falho para evitar retentativas constantes.
     */
    public function markAsFailed(string $mlItemId): bool
    {
        $stmt = $this->db->prepare("UPDATE anuncios SET sync_status = 9, last_sync_attempt = NOW() WHERE ml_item_id = :ml_item_id");
        return $stmt->execute([':ml_item_id' => $mlItemId]);
    }

    /**
     * Busca um anúncio específico pelo seu ID do Mercado Livre.
     *
     * @param string $mlItemId
     * @return array|false
     */
    public function findByMlItemId(string $mlItemId): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM anuncios WHERE ml_item_id = :ml_item_id LIMIT 1");
        $stmt->execute([':ml_item_id' => $mlItemId]);
        $anuncio = $stmt->fetch();

        // Se a coluna 'data' contiver um JSON, decodifica para um array.
        if ($anuncio && !empty($anuncio['data'])) {
            $anuncio['data'] = json_decode($anuncio['data'], true);
        }

        return $anuncio;
    }
}
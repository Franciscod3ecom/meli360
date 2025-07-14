<?php
/**
 * Modelo Anuncio
 *
 * Responsável por todas as interações com a tabela `anuncios` e com a API
 * do Mercado Livre no que tange aos dados de anúncios.
 */
namespace App\Models;

use App\Core\Database;
use PDO;
use Exception;

class Anuncio
{
    private PDO $db;

    /**
     * Construtor da classe Anuncio.
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ===================================================================
    // MÉTODOS DE SINCRONIZAÇÃO (LÓGICA DE NEGÓCIO)
    // ===================================================================

    /**
     * Busca todos os IDs de itens de um vendedor na API do Mercado Livre.
     * Usa a estratégia de scan/scroll para grandes volumes e inclui logging detalhado.
     *
     * @param int $mlUserId O ID do vendedor no Mercado Livre.
     * @param string $accessToken O token de acesso para a API.
     * @param callable $progressCallback Uma função para reportar o progresso.
     * @return array Uma lista de todos os IDs de itens encontrados.
     * @throws Exception se a chamada à API falhar.
     */
    public function fetchAllItemIdsFromApi(int $mlUserId, string $accessToken, callable $progressCallback): array
    {
        $allItemIds = [];
        $scrollId = null;
        $page = 1;
        $tokenPreview = substr($accessToken, 0, 8) . '...';

        do {
            $url = "https://api.mercadolibre.com/users/{$mlUserId}/items/search?search_type=scan&limit=100" . ($scrollId ? "&scroll_id=" . urlencode($scrollId) : "");
            log_message("SYNC_API: [Página {$page}] Chamando URL: {$url} com Token: {$tokenPreview}");

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
                CURLOPT_TIMEOUT => 45,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            $data = json_decode($response, true);

            // LOG DETALHADO DA RESPOSTA
            log_message("SYNC_API: [Página {$page}] Resposta recebida. HTTP: {$httpCode}. cURL Error: {$error}. Resposta Bruta: {$response}");

            if ($httpCode !== 200 || !isset($data['results'])) {
                $errorMessage = "Falha ao buscar IDs na API (HTTP: $httpCode). Verifique os logs para a resposta completa.";
                log_message("SYNC_API: {$errorMessage}", 'ERROR');
                $progressCallback($errorMessage, "ERROR");
                break; // Interrompe o loop em caso de falha
            }

            $itemIds = $data['results'] ?? [];
            $allItemIds = array_merge($allItemIds, $itemIds);
            $scrollId = $data['scroll_id'] ?? null;
            $totalApi = $data['paging']['total'] ?? count($allItemIds);

            log_message("SYNC_API: [Página {$page}] Sucesso. Recebidos " . count($itemIds) . " IDs. Total acumulado: " . count($allItemIds) . ".");
            $progressCallback(count($allItemIds) . " de {$totalApi} IDs de anúncios encontrados...");

            $page++;
            sleep(1); // Evita sobrecarregar a API

        } while ($scrollId);

        return $allItemIds;
    }

    // ===================================================================
    // MÉTODOS DE BANCO DE DADOS (CRUD)
    // ===================================================================

    /**
     * Limpa todos os anúncios de uma conta ML antes de uma nova sincronização.
     * @param int $mlUserId O ID do vendedor no Mercado Livre.
     * @return bool
     */
    public function clearByMlUserId(int $mlUserId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM anuncios WHERE ml_user_id = :ml_user_id");
        return $stmt->execute([':ml_user_id' => $mlUserId]);
    }

    /**
     * Insere uma grande quantidade de IDs de anúncios de uma vez.
     * @param int $saasUserId O ID do nosso usuário na plataforma.
     * @param int $mlUserId O ID do vendedor no Mercado Livre.
     * @param array $itemIds A lista de IDs de anúncios.
     * @return int O número de linhas inseridas.
     */
    public function bulkInsertIds(int $saasUserId, int $mlUserId, array $itemIds): int
    {
        if (empty($itemIds)) return 0;
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

    /**
     * Encontra um lote de anúncios para a próxima etapa do pipeline, bloqueando-os para evitar processamento duplicado.
     * IMPORTANTE: Este método deve ser chamado dentro de uma transação de banco de dados.
     * O script que o chama deve iniciar a transação e, após atualizar os registros, fazer o commit.
     * Ex: $db->beginTransaction(); ... $anuncios = findAnunciosToProcess(...); ... update...; $db->commit();
     *
     * @param int $status O sync_status a ser procurado.
     * @param int $limit O número máximo de registros a retornar.
     * @return array
     */
    public function findAnunciosToProcess(int $status, int $limit = 20): array
    {
        // MODIFICADO: Agora utiliza FOR UPDATE SKIP LOCKED para concorrência.
        // A cláusula `FOR UPDATE SKIP LOCKED` é essencial para ambientes onde múltiplos
        // scripts de sincronização (workers) podem rodar em paralelo. Ela garante que
        // cada worker pegue um conjunto único de anúncios para processar, evitando
        // trabalho duplicado e condições de corrida.
        // Um worker que falha fará com que a transação seja revertida, liberando o bloqueio
        // e permitindo que outro worker pegue o trabalho.
        $sql = "SELECT * FROM anuncios WHERE sync_status = :status LIMIT :limit FOR UPDATE SKIP LOCKED";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':status', $status, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Atualiza os detalhes básicos de múltiplos anúncios de uma vez.
     * MODIFICADO: Agora executa todas as atualizações dentro de uma transação para garantir atomicidade.
     *
     * @param array $itemsData Os dados dos itens vindos da API do ML.
     * @return int O número de registros atualizados com sucesso.
     * @throws Exception se a transação falhar.
     */
    public function bulkUpdateBasicDetails(array $itemsData): int
    {
        if (empty($itemsData)) return 0;

        $this->db->beginTransaction();
        try {
            $updatedCount = 0;
            $sql = "UPDATE anuncios SET
                        title = :title, sku = :sku, price = :price, stock = :stock,
                        total_sales = :total_sales, health = :health, status = :status, 
                        permalink = :permalink, thumbnail = :thumbnail, category_id = :category_id, 
                        has_variations = :has_variations, data = :data, 
                        sync_status = 1, last_sync_attempt = NOW()
                    WHERE ml_item_id = :ml_item_id";
            $stmt = $this->db->prepare($sql);
            $failedIds = [];

            foreach ($itemsData as $item) {
                // Checa se a chamada à API foi bem-sucedida e se temos um corpo de resposta
                if (isset($item['code']) && $item['code'] === 200 && isset($item['body']['id'])) {
                    $body = $item['body'];
                    $sku = null;
                    if (!empty($body['attributes'])) {
                        foreach ($body['attributes'] as $attribute) {
                            if ($attribute['id'] === 'SELLER_SKU' && !empty($attribute['value_name'])) {
                                $sku = $attribute['value_name'];
                                break;
                            }
                        }
                    }
                    $stmt->execute([
                        ':title' => $body['title'] ?? 'N/A',
                        ':sku' => $sku,
                        ':price' => $body['price'] ?? 0,
                        ':stock' => $body['available_quantity'] ?? 0,
                        ':total_sales' => $body['sold_quantity'] ?? 0,
                        ':health' => $body['health'] ?? 0.00,
                        ':status' => $body['status'] ?? 'unknown',
                        ':permalink' => $body['permalink'] ?? '#',
                        ':thumbnail' => $body['thumbnail'] ?? '',
                        ':category_id' => $body['category_id'] ?? null,
                        ':has_variations' => !empty($body['variations']),
                        ':data' => json_encode($body),
                        ':ml_item_id' => $body['id']
                    ]);
                    if ($stmt->rowCount() > 0) {
                        $updatedCount++;
                    }
                } else {
                    // Se a API falhou para este item, coleta o ID para marcar como falho
                    $mlItemId = $item['ml_item_id'] ?? null; // O ID deve ser passado junto com a requisição
                    if ($mlItemId) {
                        $failedIds[] = $mlItemId;
                    }
                }
            }

            // Marca todos os que falharam de uma vez
            if (!empty($failedIds)) {
                $this->bulkMarkAsFailed($failedIds);
            }

            $this->db->commit();
            return $updatedCount;
        } catch (Exception $e) {
            $this->db->rollBack();
            log_message("Falha catastrófica no bulkUpdateBasicDetails: " . $e->getMessage(), 'CRITICAL');
            throw $e; // Re-lança a exceção para o worker saber que a transação falhou
        }
    }

    /**
     * Atualiza um anúncio com os dados da análise profunda (frete, categoria).
     * @deprecated Use bulkUpdateDeepAnalysis para melhor performance e atomicidade.
     * @param string $mlItemId O ID do item no Mercado Livre.
     * @param string|null $shippingData JSON com os dados de frete.
     * @param string|null $categoryData JSON com os dados da categoria.
     * @return bool
     */
    public function updateDeepAnalysis(string $mlItemId, ?string $shippingData, ?string $categoryData): bool
    {
        $shippingJson = json_decode($shippingData, true);
        $shippingMode = $shippingJson['mode'] ?? null;
        $isFreeShipping = false;
        if(isset($shippingJson['options'])){
            foreach($shippingJson['options'] as $option){
                if(isset($option['free_method'])){
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
            ':shipping_data' => $shippingData, ':category_data' => $categoryData,
            ':shipping_mode' => $shippingMode, ':is_free_shipping' => $isFreeShipping,
            ':logistic_type' => $logisticType, ':ml_item_id' => $mlItemId
        ]);
    }

    /**
     * NOVO: Atualiza múltiplos anúncios com dados da análise profunda.
     * Executa todas as atualizações dentro de uma única transação.
     *
     * @param array $analysisData Array de dados, onde cada item contém:
     *   'ml_item_id' => string,
     *   'shipping_data' => string (JSON),
     *   'category_data' => string (JSON)
     * @return int O número de linhas atualizadas.
     * @throws Exception se a transação falhar.
     */
    public function bulkUpdateDeepAnalysis(array $analysisData): int
    {
        if (empty($analysisData)) return 0;

        $this->db->beginTransaction();
        try {
            $updatedCount = 0;
            $sql = "UPDATE anuncios SET
                        shipping_data = :shipping_data, category_data = :category_data,
                        shipping_mode = :shipping_mode, is_free_shipping = :is_free_shipping, logistic_type = :logistic_type,
                        sync_status = 2, last_sync_attempt = NOW()
                    WHERE ml_item_id = :ml_item_id";
            $stmt = $this->db->prepare($sql);

            foreach ($analysisData as $data) {
                $shippingJson = json_decode($data['shipping_data'], true);
                $shippingMode = $shippingJson['mode'] ?? null;
                $isFreeShipping = false;
                if (isset($shippingJson['options'])) {
                    foreach ($shippingJson['options'] as $option) {
                        if (isset($option['free_method'])) {
                            $isFreeShipping = true;
                            break;
                        }
                    }
                }
                $logisticType = $shippingJson['logistic_type'] ?? null;

                $stmt->execute([
                    ':shipping_data' => $data['shipping_data'],
                    ':category_data' => $data['category_data'],
                    ':shipping_mode' => $shippingMode,
                    ':is_free_shipping' => $isFreeShipping,
                    ':logistic_type' => $logisticType,
                    ':ml_item_id' => $data['ml_item_id']
                ]);
                if ($stmt->rowCount() > 0) {
                    $updatedCount++;
                }
            }

            $this->db->commit();
            return $updatedCount;
        } catch (Exception $e) {
            $this->db->rollBack();
            log_message("Falha catastrófica no bulkUpdateDeepAnalysis: " . $e->getMessage(), 'CRITICAL');
            throw $e;
        }
    }

    /**
     * Salva os custos de frete para diferentes regiões em sua própria tabela.
     * @param int $anuncioId O ID interno do anúncio na nossa tabela `anuncios`.
     * @param string $mlItemId O ID do anúncio no Mercado Livre.
     * @param array $shippingCosts Um array associativo de [region_name => ['zip_code' => ..., 'cost' => ...]].
     * @return void
     */
    public function saveShippingCosts(int $anuncioId, string $mlItemId, array $shippingCosts): void
    {
        $stmt_delete = $this->db->prepare("DELETE FROM shipping_costs WHERE anuncio_id = :anuncio_id");
        $stmt_delete->execute([':anuncio_id' => $anuncioId]);

        $sql = "INSERT INTO shipping_costs (anuncio_id, ml_item_id, region_name, zip_code, cost) 
                VALUES (:anuncio_id, :ml_item_id, :region_name, :zip_code, :cost)";
        $stmt_insert = $this->db->prepare($sql);

        foreach ($shippingCosts as $region => $data) {
            $stmt_insert->execute([
                ':anuncio_id' => $anuncioId, ':ml_item_id' => $mlItemId,
                ':region_name' => $region, ':zip_code' => $data['zip_code'],
                ':cost' => $data['cost']
            ]);
        }
    }

    /**
     * Marca um anúncio como falho para evitar retentativas constantes.
     * @deprecated Use bulkMarkAsFailed para melhor performance.
     * @param string|null $mlItemId O ID do item no Mercado Livre.
     * @return bool
     */
    public function markAsFailed(?string $mlItemId): bool
    {
        if (!$mlItemId) return false;
        $stmt = $this->db->prepare("UPDATE anuncios SET sync_status = 9, last_sync_attempt = NOW() WHERE ml_item_id = :ml_item_id");
        return $stmt->execute([':ml_item_id' => $mlItemId]);
    }

    /**
     * NOVO: Marca uma lista de anúncios como falhos de uma só vez.
     *
     * @param array $mlItemIds Lista de IDs de itens do Mercado Livre.
     * @return int O número de linhas afetadas.
     */
    public function bulkMarkAsFailed(array $mlItemIds): int
    {
        if (empty($mlItemIds)) return 0;

        $placeholders = implode(',', array_fill(0, count($mlItemIds), '?'));
        $sql = "UPDATE anuncios SET sync_status = 9, last_sync_attempt = NOW() WHERE ml_item_id IN ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($mlItemIds);
        return $stmt->rowCount();
    }

    // ===================================================================
    // MÉTODOS DE CONSULTA PARA APLICAÇÃO
    // ===================================================================

    /**
     * Busca um anúncio específico pelo seu ID do Mercado Livre.
     *
     * @param string $mlItemId O ID do item no Mercado Livre.
     * @return array|false Retorna os dados do anúncio ou false se não encontrado.
     */
    public function findByMlItemId(string $mlItemId)
    {
        $stmt = $this->db->prepare("SELECT * FROM anuncios WHERE ml_item_id = :ml_item_id");
        $stmt->execute([':ml_item_id' => $mlItemId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Busca todos os anúncios de uma conta ML específica com paginação.
     * Usado para exibir os anúncios na interface do usuário.
     * @param int $saasUserId
     * @param int $mlUserId
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function findAllByMlUserId(int $saasUserId, int $mlUserId, int $limit, int $offset): array
    {
        $sql = "SELECT * FROM anuncios WHERE saas_user_id = :saas_user_id AND ml_user_id = :ml_user_id ORDER BY status, title ASC LIMIT :limit OFFSET :offset";
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
     * @param int $saasUserId
     * @param int $mlUserId
     * @return int
     */
    public function countByMlUserId(int $saasUserId, int $mlUserId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM anuncios WHERE saas_user_id = :saas_user_id AND ml_user_id = :ml_user_id");
        $stmt->execute([':saas_user_id' => $saasUserId, ':ml_user_id' => $mlUserId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * NOVO: Conta o total de anúncios de uma conta ML, independente do status.
     * Usado para calcular o progresso da sincronização.
     * @param int $mlUserId
     * @return int
     */
    public function countTotalByMlUserId(int $mlUserId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM anuncios WHERE ml_user_id = :ml_user_id");
        $stmt->execute([':ml_user_id' => $mlUserId]);
        return (int) $stmt->fetchColumn();
    }
    /**
     * Conta o total de anúncios de uma conta ML com determinados status.
     * @param int $mlUserId
     * @param array $statuses
     * @return int
     */
    public function countByStatus(int $mlUserId, array $statuses): int
    {
        if (empty($statuses)) return 0;
        $inQuery = implode(',', array_fill(0, count($statuses), '?'));
        $sql = "SELECT COUNT(*) FROM anuncios WHERE ml_user_id = ? AND sync_status IN ($inQuery)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$mlUserId], $statuses));
        return (int) $stmt->fetchColumn();
    }

    /**
     * Busca um anúncio pelo seu ID interno.
     * @param int $id
     * @return array|false
     */
    public function findById(int $id): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM anuncios WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Busca os custos de frete de um anúncio.
     * @param int $anuncioId O ID interno do anúncio.
     * @return array
     */
    public function findShippingCostsByAnuncioId(int $anuncioId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM shipping_costs WHERE anuncio_id = :anuncio_id ORDER BY region_name ASC");
        $stmt->execute([':anuncio_id' => $anuncioId]);
        return $stmt->fetchAll();
    }

    /**
     * Conta o número de anúncios agrupados por sync_status para uma conta ML.
     * @param int $mlUserId
     * @return array
     */
    public function getStatusCountsByMlUserId(int $mlUserId): array
    {
        $stmt = $this->db->prepare("SELECT sync_status, COUNT(*) as count FROM anuncios WHERE ml_user_id = :ml_user_id GROUP BY sync_status");
        $stmt->execute([':ml_user_id' => $mlUserId]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
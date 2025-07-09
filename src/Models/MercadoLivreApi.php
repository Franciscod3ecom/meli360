<?php
namespace App\Models;

class MercadoLivreApi
{
    private const API_BASE_URL = 'https://api.mercadolibre.com';

    /**
     * Faz uma requisição cURL para um endpoint da API do ML.
     *
     * @param string $url
     * @param string $method
     * @param array $headers
     * @param mixed $postData
     * @return array
     */
    private function makeRequest(string $url, string $method = 'GET', array $headers = [], $postData = null): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($postData) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['httpCode' => $httpCode, 'response' => json_decode($response, true)];
    }
    
    /**
     * Busca os IDs de todos os anúncios de um usuário usando a paginação por scroll.
     *
     * @param int $mlUserId
     * @param string $accessToken
     * @return array Retorna um array com 'item_ids' e 'error'.
     */
    public function getAllItemIds(int $mlUserId, string $accessToken): array
    {
        $allItemIds = [];
        $scrollId = null;
        $headers = ['Authorization: Bearer ' . $accessToken];

        do {
            $url = self::API_BASE_URL . "/users/{$mlUserId}/items/search?search_type=scan&limit=50";
            if ($scrollId) {
                $url .= "&scroll_id={$scrollId}";
            }

            $result = $this->makeRequest($url, 'GET', $headers);
            
            if ($result['httpCode'] !== 200) {
                return ['item_ids' => null, 'error' => 'Falha ao buscar anúncios. HTTP: ' . $result['httpCode']];
            }

            $data = $result['response'];
            if (isset($data['results']) && !empty($data['results'])) {
                $allItemIds = array_merge($allItemIds, $data['results']);
                $scrollId = $data['scroll_id'] ?? null;
            } else {
                $scrollId = null;
            }

        } while ($scrollId);

        return ['item_ids' => $allItemIds, 'error' => null];
    }
/**
     * Busca os detalhes de um lote de anúncios usando a API /items.
     *
     * @param array $itemIds Array de IDs de anúncios (ex: ['MLB123', 'MLB456']).
     * @param string $accessToken
     * @return array Retorna um array com 'data' e 'error'.
     */
    public function getItemsDetails(array $itemIds, string $accessToken): array
    {
        if (empty($itemIds)) {
            return ['data' => [], 'error' => null];
        }

        // A API permite buscar até 20 itens por vez.
        $idsString = implode(',', array_slice($itemIds, 0, 20));
        $attributes = 'id,title,price,available_quantity,status,permalink,thumbnail,date_created,health,category_id,shipping,variations,seller_custom_field';
        $url = self::API_BASE_URL . "/items?ids={$idsString}&attributes={$attributes}";
        $headers = ['Authorization: Bearer ' . $accessToken];

        $result = $this->makeRequest($url, 'GET', $headers);

        if ($result['httpCode'] !== 200) {
            return ['data' => null, 'error' => 'Falha ao buscar detalhes dos itens. HTTP: ' . $result['httpCode']];
        }

        return ['data' => $result['response'], 'error' => null];
    }
}
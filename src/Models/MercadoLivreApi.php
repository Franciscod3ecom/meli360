<?php
namespace App\Models;

/**
 * Classe centralizadora para todas as comunicações com a API do Mercado Livre.
 * Encapsula a lógica de cURL, tratamento de erros e rate limiting.
 */
class MercadoLivreApi
{
    private string $accessToken;
    private int $retries = 0;
    private const MAX_RETRIES = 3;
    private const API_BASE_URL = 'https://api.mercadolibre.com';

    public function __construct(string $accessToken)
    {
        $this->accessToken = $accessToken;
    }

    /**
     * Faz uma chamada genérica para a API do ML, com tratamento de Rate Limit.
     *
     * @param string $url A URL completa do endpoint.
     * @param array $options Opções adicionais do cURL.
     * @return array|null O resultado da API decodificado ou null em caso de erro.
     */
    private function makeRequest(string $url, array $options = []): ?array
    {
        $ch = curl_init($url);
        
        $defaultOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->accessToken, 'Accept: application/json'],
            CURLOPT_TIMEOUT => 30,
        ];

        curl_setopt_array($ch, $options + $defaultOptions);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 429 && $this->retries < self::MAX_RETRIES) { // Too Many Requests
            $this->retries++;
            $sleepTime = 5 * $this->retries; // Pausa incremental
            log_message('API_RATE_LIMIT', "Limite da API atingido na URL {$url}. Pausando por {$sleepTime} segundos. Tentativa {$this->retries}/" . self::MAX_RETRIES);
            sleep($sleepTime);
            return $this->makeRequest($url, $options); // Tenta novamente
        }

        if ($httpCode >= 400) {
            log_message('API_ERROR', "HTTP {$httpCode} na URL {$url}. Erro cURL: {$error}. Resposta: {$response}");
            return null;
        }
        
        $this->retries = 0; // Reseta o contador em caso de sucesso
        return json_decode($response, true);
    }

    /**
     * Busca todos os IDs de anúncios de um usuário usando o sistema de scroll.
     *
     * @param int $mlUserId
     * @return array|null
     */
    public function fetchAllItemIds(int $mlUserId): ?array
    {
        $allItemIds = [];
        $url = self::API_BASE_URL . "/users/{$mlUserId}/items/search?limit=50";
        
        do {
            $data = $this->makeRequest($url);
            if (!isset($data['results']) || !is_array($data['results'])) {
                log_message('API_ERROR', "Erro ao buscar IDs de anúncios para o usuário {$mlUserId}. Resposta inválida.");
                return null; // Interrompe se a resposta for inválida
            }

            $allItemIds = array_merge($allItemIds, $data['results']);
            
            $scrollId = $data['scroll_id'] ?? null;
            if ($scrollId) {
                $url = self::API_BASE_URL . "/users/{$mlUserId}/items/search?scroll_id={$scrollId}";
            }

        } while ($scrollId && !empty($data['results']));

        return $allItemIds;
    }

    /**
     * Busca detalhes de múltiplos itens, respeitando o limite de 20 IDs por chamada.
     *
     * @param array $itemIds
     * @return array|null
     */
    public function fetchItemsDetails(array $itemIds): ?array
    {
        if (empty($itemIds) || count($itemIds) > 20) {
            log_message('API_LOGIC_ERROR', 'Tentativa de buscar detalhes de mais de 20 itens em uma chamada.');
            return null;
        }
        
        $idsString = implode(',', $itemIds);
        $attributes = 'id,title,price,available_quantity,status,permalink,thumbnail,date_created,last_updated,health,shipping,category_id,pictures';
        $url = self::API_BASE_URL . "/items?ids={$idsString}&attributes={$attributes}";
        
        return $this->makeRequest($url);
    }

    /**
     * Busca visitas de múltiplos itens, respeitando o limite de 50 IDs por chamada.
     *
     * @param array $itemIds
     * @return array|null
     */
    public function fetchItemsVisits(array $itemIds): ?array
    {
        if (empty($itemIds) || count($itemIds) > 50) {
            log_message('API_LOGIC_ERROR', 'Tentativa de buscar visitas de mais de 50 itens em uma chamada.');
            return null;
        }

        $idsString = implode(',', $itemIds);
        $url = self::API_BASE_URL . "/visits/items?ids={$idsString}";

        return $this->makeRequest($url);
    }

    /**
     * Busca opções de frete para um único item.
     *
     * @param string $itemId
     * @return array|null
     */
    public function fetchShippingOptions(string $itemId, ?string $zipCode = null): ?array
    {
        $url = self::API_BASE_URL . "/items/{$itemId}/shipping_options";
        if ($zipCode) {
            $url .= "?zip_code={$zipCode}";
        }
        return $this->makeRequest($url);
    }

    /**
     * Busca detalhes de uma categoria para entender suas regras.
     *
     * @param string $categoryId
     * @return array|null
     */
    public function fetchCategoryDetails(string $categoryId): ?array
    {
        $url = self::API_BASE_URL . "/categories/{$categoryId}?attributes=settings";
        return $this->makeRequest($url);
    }
}
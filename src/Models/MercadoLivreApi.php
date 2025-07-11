<?php
namespace App\Models;

/**
 * Classe para interagir com a API do Mercado Livre.
 * Simplifica a execução de requisições HTTP para os endpoints da API.
 */
class MercadoLivreApi
{
    private const API_BASE_URL = 'https://api.mercadolibre.com';
    private string $accessToken;

    /**
     * Construtor da classe.
     * @param string $accessToken O token de acesso para autenticar as requisições.
     */
    public function __construct(string $accessToken)
    {
        $this->accessToken = $accessToken;
    }

    /**
     * Faz uma requisição cURL para um endpoint da API do ML.
     *
     * @param string $url A URL completa da requisição.
     * @param string $method O método HTTP (GET, POST, etc.).
     * @param array $extraHeaders Cabeçalhos adicionais.
     * @param mixed $postData Dados para requisições POST/PUT.
     * @return array Retorna o código HTTP e a resposta decodificada.
     */
    private function makeRequest(string $url, string $method = 'GET', array $extraHeaders = [], $postData = null): array
    {
        $ch = curl_init();
        $headers = array_merge(['Authorization: Bearer ' . $this->accessToken], $extraHeaders);

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10, // Timeout de conexão
            CURLOPT_TIMEOUT => 30, // Timeout total da requisição
        ]);

        if ($postData) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            log_message("cURL Error for URL {$url}: {$error}", 'ERROR');
            return ['httpCode' => 500, 'response' => null, 'error' => $error];
        }

        return ['httpCode' => $httpCode, 'response' => json_decode($response, true)];
    }

    /**
     * Busca os detalhes de múltiplos itens de uma vez.
     *
     * @param array $itemIds Array com os IDs dos itens.
     * @param array $attributes Array com os atributos desejados.
     * @return array Retorna um array de respostas, uma para cada item.
     */
    public function getMultipleItemDetails(array $itemIds, array $attributes): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $idsString = implode(',', $itemIds);
        $attributesString = implode(',', $attributes);
        $url = self::API_BASE_URL . "/items?ids={$idsString}&attributes={$attributesString}";

        $result = $this->makeRequest($url);

        // A API retorna um array de resultados. Cada resultado tem um 'code' e um 'body'.
        // Se a chamada geral falhar (ex: token inválido), retorna um array vazio.
        return $result['response'] ?? [];
    }

    /**
     * Executa múltiplas requisições GET em paralelo.
     *
     * @param array $urls Um array associativo de 'key' => 'url'.
     * @return array Um array associativo de 'key' => 'response_body'.
     */
    public function getParallel(array $urls): array
    {
        if (empty($urls)) {
            return [];
        }

        $multiHandle = curl_multi_init();
        $curlHandles = [];
        $results = [];
        $headers = ['Authorization: Bearer ' . $this->accessToken];

        // Prepara cada handle cURL
        foreach ($urls as $key => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
            ]);
            $curlHandles[$key] = $ch;
            curl_multi_add_handle($multiHandle, $ch);
        }

        // Executa as requisições
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle); // Aguarda por atividade
        } while ($running > 0);

        // Coleta os resultados
        foreach ($curlHandles as $key => $ch) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode === 200) {
                $results[$key] = json_decode(curl_multi_getcontent($ch), true);
            } else {
                $results[$key] = null; // Falha na requisição específica
                log_message("Parallel GET failed for URL {$urls[$key]} with HTTP code {$httpCode}", 'WARNING');
            }
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);

        return $results;
    }
}
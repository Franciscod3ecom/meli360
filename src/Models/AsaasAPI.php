<?php
namespace App\Models;

class AsaasAPI
{
    private string $apiKey;
    private string $apiUrl = 'https://www.asaas.com/api/v3'; // URL de produção

    public function __construct()
    {
        if (empty($_ENV['ASAAS_API_KEY'])) {
            throw new \Exception("ASAAS_API_KEY não está definida no arquivo .env");
        }
        $this->apiKey = $_ENV['ASAAS_API_KEY'];
    }

    /**
     * Cria um novo cliente no Asaas.
     *
     * @param User $user O objeto do usuário do nosso sistema.
     * @return string|null O ID do cliente no Asaas ou null em caso de falha.
     */
    public function createCustomer(User $user): ?string
    {
        $postData = json_encode([
            'name' => $user['name'],
            'email' => $user['email'],
            // 'cpfCnpj' => $user['cpf'], // Adicionar CPF/CNPJ se tiver
        ]);

        $response = $this->request('POST', '/customers', $postData);

        return $response['id'] ?? null;
    }

    /**
     * Cria uma nova cobrança (pagamento único) no Asaas.
     *
     * @param string $customerId ID do cliente no Asaas.
     * @param float $value O valor da cobrança.
     * @param string $description A descrição da cobrança.
     * @param string $dueDate A data de vencimento (Y-m-d).
     * @return array|null Os dados da cobrança criada.
     */
    public function createPayment(string $customerId, float $value, string $description, string $dueDate): ?array
    {
        $postData = json_encode([
            'customer' => $customerId,
            'billingType' => 'UNDEFINED', // Permite que o cliente escolha (Boleto, Pix, Cartão)
            'value' => $value,
            'dueDate' => $dueDate,
            'description' => $description,
        ]);

        return $this->request('POST', '/payments', $postData);
    }

    /**
     * Função auxiliar para fazer requisições para a API Asaas.
     */
    private function request(string $method, string $endpoint, ?string $data = null): ?array
    {
        $ch = curl_init($this->apiUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'access_token: ' . $this->apiKey
            ],
            CURLOPT_POSTFIELDS => $data
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $responseData = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return $responseData;
        } else {
            log_message("Erro na API Asaas ({$httpCode}): " . $response, "ERROR");
            return null;
        }
    }
}

<?php
namespace App\Controllers;

use App\Models\Plan;
use App\Models\User;
use App\Models\AsaasAPI;
use App\Models\Subscription;
use App\Models\Payment;
use Exception;

class BillingController
{
    /**
     * Exibe a página de planos de assinatura.
     */
    public function plans(): void
    {
        $planModel = new Plan();
        $plans = $planModel->getActivePlans();

        view('billing.plans', ['plans' => $plans]);
    }

    /**
     * Processa a escolha de um plano e redireciona para o pagamento.
     */
    public function subscribe(int $planId): void
    {
        // 1. Segurança: Validar token CSRF
        if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
            log_message('Falha na validação do token CSRF ao tentar assinar plano.', 'WARNING');
            http_response_code(403);
            view('errors.403', ['message' => 'A requisição foi bloqueada por motivos de segurança.']);
            exit;
        }

        try {
            $saasUserId = $_SESSION['user_id'];

            $userModel = new User();
            $planModel = new Plan();
            $asaasApi = new AsaasAPI();

            $user = $userModel->findById($saasUserId);
            $plan = $planModel->findById($planId);

            if (!$user || !$plan) {
                throw new Exception('Plano ou usuário inválido.');
            }

            // 2. Garante que o usuário exista como cliente no Asaas
            $asaasCustomerId = $user['asaas_customer_id'];
            if (!$asaasCustomerId) {
                log_message("Criando cliente Asaas para o usuário SaaS ID: {$saasUserId}", 'INFO');
                $asaasCustomerId = $asaasApi->createCustomer($user);
                if ($asaasCustomerId) {
                    $userModel->updateAsaasCustomerId($saasUserId, $asaasCustomerId);
                } else {
                    throw new Exception('Não foi possível criar seu cadastro de pagamento no Asaas.');
                }
            }

            // 3. Cria a cobrança no Asaas
            log_message("Criando cobrança no Asaas para o plano ID: {$planId}, Cliente Asaas ID: {$asaasCustomerId}", 'INFO');
            $dueDate = (new \DateTime())->modify('+5 days')->format('Y-m-d');
            $description = "Assinatura do plano {$plan['name']}";
            
            $paymentData = $asaasApi->createPayment($asaasCustomerId, $plan['price'], $description, $dueDate);

            if (!$paymentData || !isset($paymentData['invoiceUrl'])) {
                throw new Exception('Não foi possível gerar sua cobrança no Asaas.');
            }

            // 4. Salva a assinatura e o pagamento no nosso banco
            $subscriptionModel = new Subscription();
            $subscriptionId = $subscriptionModel->create($saasUserId, $planId, null, 'pending', $dueDate);

            if (!$subscriptionId) {
                throw new Exception('Falha ao registrar a assinatura no banco de dados local.');
            }

            $paymentModel = new Payment();
            $paymentModel->create([
                'saas_user_id' => $saasUserId,
                'subscription_id' => $subscriptionId,
                'asaas_payment_id' => $paymentData['id'],
                'amount' => $paymentData['value'],
                'status' => $paymentData['status'],
                'billing_type' => $paymentData['billingType'],
                'invoice_url' => $paymentData['invoiceUrl'],
                'due_date' => $paymentData['dueDate']
            ]);

            // 5. Redireciona o usuário para a página de pagamento
            log_message("Redirecionando usuário SaaS ID: {$saasUserId} para a URL de pagamento Asaas.", 'INFO');
            header('Location: ' . $paymentData['invoiceUrl']);
            exit;

        } catch (Exception $e) {
            log_message("Erro no processo de assinatura: " . $e->getMessage(), 'ERROR');
            set_flash_message('billing_error', 'Ocorreu um erro ao processar sua assinatura. Por favor, tente novamente ou contate o suporte.');
            header('Location: /billing/plans');
            exit;
        }
    }
}

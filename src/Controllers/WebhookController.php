<?php

namespace App\Controllers;

use App\Models\Anuncio;
use App\Models\MercadoLivreUser;
use App\Models\Question;

class WebhookController
{
    public function handleNotification(array $notificationData): void
    {
        // Valida se a notificação contém os dados necessários
        if (empty($notificationData['resource']) || empty($notificationData['user_id'])) {
            log_message("Webhook: Dados da notificação inválidos: " . json_encode($notificationData), 'WARNING');
            return;
        }

        // Processa a notificação da pergunta
        $this->processQuestionNotification($notificationData);
    }

    private function processQuestionNotification(array $notificationData): void
    {
        // Extrai o ID da pergunta do resource (ex: /questions/12345)
        $resourceParts = explode('/', $notificationData['resource']);
        $mlQuestionId = end($resourceParts);

        if (!is_numeric($mlQuestionId)) {
            log_message("Webhook: ID de pergunta inválido no resource: " . $notificationData['resource'], 'WARNING');
            return;
        }

        $mlUserId = $notificationData['user_id'];

        // Busca o saas_user_id correspondente
        $mlUserModel = new MercadoLivreUser();
        $saasUserId = $mlUserModel->findSaasUserIdByMlUserId($mlUserId);

        if (!$saasUserId) {
            log_message("Webhook: saas_user_id não encontrado para ml_user_id {$mlUserId}. Descartando.", 'WARNING');
            return;
        }

        // 1. Busca os detalhes completos da pergunta na API do ML
        $questionDetails = $mlUserModel->getQuestionDetails($mlQuestionId, $saasUserId, $mlUserId);

        if (!$questionDetails || isset($questionDetails['error']) || $questionDetails['status'] === 'ANSWERED') {
            log_message("Webhook: Falha ao obter detalhes da pergunta {$mlQuestionId} ou ela já foi respondida. Resposta: " . json_encode($questionDetails), 'INFO');
            return;
        }

        // 2. Salva a pergunta no nosso banco de dados
        $questionModel = new Question();
        $questionModel->createOrUpdate([
            'ml_question_id' => $questionDetails['id'],
            'ml_item_id' => $questionDetails['item_id'],
            'saas_user_id' => $saasUserId,
            'ml_user_id' => $mlUserId,
            'question_text' => $questionDetails['text'],
            'question_date' => (new \DateTime($questionDetails['date_created']))->format('Y-m-d H:i:s'),
        ]);

        log_message("Pergunta {$mlQuestionId} salva/atualizada no banco para o usuário SaaS ID {$saasUserId}.", 'INFO');

        // --- INÍCIO DA LÓGICA DE IA ---

        // 3. Busca o contexto do anúncio
        $anuncioModel = new Anuncio();
        $anuncioData = $anuncioModel->findByMlItemId($questionDetails['item_id']);
        if (!$anuncioData || empty($anuncioData['data'])) {
            log_message("Webhook: Anúncio {$questionDetails['item_id']} ou seus dados não foram encontrados no banco. Não é possível responder.", 'WARNING');
            $questionModel->updateAnswer($mlQuestionId, 'FAILED', 'Contexto do anúncio não encontrado no banco.', null);
            return;
        }

        // 4. Gera a resposta com a IA
        $questionModel->updateAnswer($mlQuestionId, 'ANSWERING', null, null);
        $geminiApi = new \App\Models\GeminiAPI();
        $generatedAnswer = $geminiApi->generateAnswer($questionDetails['text'], $anuncioData['data']);

        // 5. Envia a resposta para o Mercado Livre
        $success = $mlUserModel->postAnswer($mlQuestionId, $generatedAnswer, $saasUserId, $mlUserId);

        // 6. Atualiza o status final no nosso banco
        if ($success) {
            $questionModel->updateAnswer($mlQuestionId, 'ANSWERED', $generatedAnswer, $generatedAnswer);
        } else {
            $questionModel->updateAnswer($mlQuestionId, 'FAILED', $generatedAnswer, null);
        }
    }

    /**
     * Lida com as notificações de webhook enviadas pelo Asaas.
     */
    public function handleAsaas(): void
    {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        log_message("Webhook Asaas Recebido: " . $json, 'INFO');

        // Validação básica
        if (!$data || !isset($data['event'])) {
            http_response_code(400);
            return;
        }

        // Responde imediatamente ao Asaas com 200 OK para evitar timeouts.
        http_response_code(200);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Processa apenas os eventos de pagamento confirmado ou recebido
        if ($data['event'] === 'PAYMENT_CONFIRMED' || $data['event'] === 'PAYMENT_RECEIVED') {
            try {
                $this->processPaymentConfirmation($data['payment']);
            } catch (\Exception $e) {
                log_message("Erro ao processar webhook do Asaas: " . $e->getMessage(), 'ERROR');
            }
        }
    }

    /**
     * Processa a confirmação de um pagamento recebido pelo webhook.
     */
    private function processPaymentConfirmation(array $paymentData): void
    {
        $asaasPaymentId = $paymentData['id'];

        $paymentModel = new \App\Models\Payment();
        $subscriptionModel = new \App\Models\Subscription();

        // 1. Atualiza o status do pagamento no nosso banco
        $paymentModel->updateStatusByAsaasId($asaasPaymentId, $paymentData['status']);
        log_message("Pagamento {$asaasPaymentId} atualizado para o status {$paymentData['status']}.", 'INFO');

        // 2. Encontra a assinatura relacionada e a ativa
        $payment = $paymentModel->findByAsaasId($asaasPaymentId);
        if ($payment && $payment['subscription_id']) {
            // Define a data de expiração para 30 dias a partir de hoje
            $newExpiryDate = (new \DateTime())->modify('+30 days')->format('Y-m-d');
            $subscriptionModel->updateStatus($payment['subscription_id'], 'active', $newExpiryDate);
            log_message("Assinatura ID {$payment['subscription_id']} ativada até {$newExpiryDate}.", 'INFO');
        }
    }
}
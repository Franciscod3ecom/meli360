<?php
/**
 * Arquivo: asaas_webhook_receiver.php
 * Versão: v1.2 - Garante limpeza de expire_date se não ATIVO
 * Descrição: Endpoint para receber e processar notificações (Webhooks) do Asaas.
 *            Valida assinatura e atualiza status/expiração no DB local.
 * !! SEGURANÇA CRÍTICA: Defina ASAAS_WEBHOOK_SECRET em config.php !!
 *    Verifique também o firewall/WAF do servidor se ocorrer erro 403.
 */

// Includes Essenciais
require_once __DIR__ . '/config.php'; // Para ASAAS_WEBHOOK_SECRET, constantes DB
require_once __DIR__ . '/db.php';     // Para getDbConnection()
require_once __DIR__ . '/includes/log_helper.php'; // Para logMessage()

logMessage("==== [Asaas Webhook Receiver v1.2] Notificação Recebida ====");

// --- Validação da Requisição ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logMessage("[Asaas Webhook v1.2] ERRO: Método HTTP inválido ({$_SERVER['REQUEST_METHOD']}).");
    http_response_code(405); // Method Not Allowed
    exit;
}

// --- Validação da Assinatura (ESSENCIAL PARA SEGURANÇA) ---
$payload = file_get_contents('php://input'); // Lê o corpo da requisição ANTES de decodificar
$receivedSignature = $_SERVER['HTTP_ASAAS_SIGNATURE'] ?? null; // Header esperado do Asaas
$data = null; // Inicializa $data

// A validação só ocorre se a constante estiver definida e não vazia
if (defined('ASAAS_WEBHOOK_SECRET') && !empty(ASAAS_WEBHOOK_SECRET)) {
    $webhookSecret = ASAAS_WEBHOOK_SECRET;

    // Verifica se o segredo placeholder ainda está sendo usado (ajuste conforme seu placeholder)
    $placeholders = [
        'SUBSTITUA_PELO_SEU_TOKEN_SECRETO_CONFIGURADO_NO_ASAAS',
        'SEU_TOKEN_SECRETO_WEBHOOK_ASAAS',
        'zL9qR+sTvXuYwZ1eFgHjKlMnO/pQrStUvWxY/Z012=' // Exemplo que usamos
    ];
    if (in_array($webhookSecret, $placeholders)) {
         logMessage("[Asaas Webhook v1.2] ALERTA DE SEGURANÇA GRAVE: ASAAS_WEBHOOK_SECRET está com valor placeholder ('".substr($webhookSecret,0,10)."...')! Validação efetivamente DESATIVADA. Configure um segredo real URGENTEMENTE!");
         // Permite continuar para não parar o fluxo durante a configuração, mas é INSEGURO.
         $data = $payload ? json_decode($payload, true) : null;
    }
    // Se tem um segredo (aparentemente) real, valida
    elseif (!$receivedSignature) {
        logMessage("[Asaas Webhook v1.2] ERRO: Header de assinatura 'Asaas-Signature' ausente na requisição. Retornando 403.");
        http_response_code(403); // Forbidden - Assinatura esperada, mas não veio
        exit;
    } else {
        // Calcula a assinatura esperada usando HMAC-SHA256
        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        // Compara as assinaturas de forma segura contra timing attacks
        if (!hash_equals($expectedSignature, $receivedSignature)) {
            logMessage("[Asaas Webhook v1.2] ERRO: Assinatura inválida. Esperada: $expectedSignature Recebida: $receivedSignature Payload (inicio): ".substr($payload, 0, 100) . ". Retornando 403.");
            http_response_code(403); // Forbidden - Assinatura inválida
            exit;
        }
        logMessage("[Asaas Webhook v1.2] Assinatura HMAC-SHA256 validada com sucesso.");
        // Decodifica o JSON *APÓS* validar a assinatura
        $data = json_decode($payload, true);
    }
} else {
    // Se a constante não está definida ou está vazia, loga um aviso e processa sem validar
    logMessage("[Asaas Webhook v1.2] AVISO: Validação de assinatura DESABILITADA (ASAAS_WEBHOOK_SECRET não definida ou vazia). Processando sem validar origem (INSEGURO)!");
    $data = $payload ? json_decode($payload, true) : null;
}
// --- Fim Validação de Assinatura ---

// --- Validação Payload JSON ---
if (!$data || json_last_error() !== JSON_ERROR_NONE || !isset($data['event'])) {
    logMessage("[Asaas Webhook v1.2] ERRO: Payload JSON inválido ou campo 'event' ausente. JSON Error: " . json_last_error_msg() . ". Payload (inicio): ".substr($payload, 0, 100));
    http_response_code(400); // Bad Request
    exit;
}

$eventName = $data['event'] ?? 'UNKNOWN';
logMessage("[Asaas Webhook v1.2] Evento: $eventName");

// --- Processamento ---
$pdo = null;
try {
    $pdo = getDbConnection();
    $pdo->beginTransaction(); // Usa transação para garantir atomicidade

    $subscriptionId = null; $paymentData = null; $subscriptionData = null;
    $newLocalStatus = null; $newExpireDate = null; // Resetados a cada webhook

    // Extrai ID da assinatura Asaas do payload
    if (isset($data['payment']['subscription'])) {
        $subscriptionId = $data['payment']['subscription'];
        $paymentData = $data['payment'];
    } elseif (isset($data['subscription']['id'])) {
        $subscriptionId = $data['subscription']['id'];
        $subscriptionData = $data['subscription'];
    }

    // Se não conseguiu extrair ID, ignora o evento
    if (!$subscriptionId) {
        logMessage("  [WH v1.2] Ignorando evento $eventName sem ID de assinatura Asaas reconhecido.");
        $pdo->rollBack(); // Cancela transação
        http_response_code(200); // OK para Asaas, não é erro nosso
        exit;
    }
    logMessage("  [WH v1.2] Processando para Asaas Sub ID: $subscriptionId");

    // Mapeia evento do Asaas para status local e data de expiração
    switch ($eventName) {
        // Pagamento Recebido/Confirmado -> Status ATIVO
        case 'PAYMENT_RECEIVED':
        case 'PAYMENT_CONFIRMED':
            $newLocalStatus = 'ACTIVE';
            $newExpireDate = $paymentData['nextDueDate'] ?? null; // Data de vencimento da *próxima* fatura
            break;

        // Pagamento Atualizado
        case 'PAYMENT_UPDATED':
            $paymentStatus = $paymentData['status'] ?? 'UNKNOWN';
            if (in_array($paymentStatus, ['RECEIVED', 'CONFIRMED'])) {
                $newLocalStatus = 'ACTIVE';
                $newExpireDate = $paymentData['nextDueDate'] ?? null;
            } elseif (in_array($paymentStatus, ['OVERDUE', 'FAILED'])) {
                $newLocalStatus = 'OVERDUE'; // Ou 'FAILED' se quiser diferenciar
            }
            // Ignora outros status como PENDING, AWAITING_RISK_ANALYSIS, etc.
            break;

        // Pagamento Vencido/Falhado -> Status OVERDUE (ou FAILED)
        case 'PAYMENT_OVERDUE':
        case 'PAYMENT_FAILED':
            $newLocalStatus = 'OVERDUE';
            break;

        // Assinatura Atualizada (Cancelada, Expirada, Reativada)
        case 'SUBSCRIPTION_UPDATED':
             $newAsaasStatus = $subscriptionData['status'] ?? 'UNKNOWN';
             if ($newAsaasStatus === 'ACTIVE') {
                 $newLocalStatus = 'ACTIVE';
                 $newExpireDate = $subscriptionData['nextDueDate'] ?? null; // Data da própria assinatura
             } elseif (in_array($newAsaasStatus, ['EXPIRED', 'CANCELLED'])) {
                 $newLocalStatus = 'CANCELED'; // Mapeia ambos para CANCELED localmente
             }
             // Ignora outros status da assinatura
             break;

        // Assinatura Deletada (se Asaas enviar e for relevante tratar)
        // case 'SUBSCRIPTION_DELETED':
        //     $newLocalStatus = 'CANCELED'; // Ou um status 'DELETED' se preferir
        //     break;

        // Evento de Criação (geralmente não muda status local)
        case 'PAYMENT_CREATED':
             logMessage("  [WH v1.2] Evento PAYMENT_CREATED para Sub: $subscriptionId (informativo).");
             break;

        default:
            // Eventos não mapeados são ignorados para atualização de status
            logMessage("  [WH v1.2] Evento $eventName não mapeado para mudança de status local.");
            break;
    }

    // Se um novo status local foi determinado pelo switch, prossegue com a atualização no DB
    if ($newLocalStatus !== null) {
        logMessage("  [WH v1.2 Update DB] Novo Status Local Determinado: '$newLocalStatus'. Próx Venc: " . ($newExpireDate ?: 'N/A'));

        // Busca o usuário local associado a esta assinatura Asaas
        $stmtFindUser = $pdo->prepare("SELECT id, subscription_status, subscription_expires_at FROM saas_users WHERE asaas_subscription_id = :sub_id");
        $stmtFindUser->execute([':sub_id' => $subscriptionId]);
        $foundUser = $stmtFindUser->fetch();

        if ($foundUser) {
            $saasUserId = $foundUser['id'];
            $currentLocalStatus = $foundUser['subscription_status'];
            $currentExpireDate = $foundUser['subscription_expires_at'];
            logMessage("    -> Usuário SaaS encontrado (ID: $saasUserId). Status Atual DB: '$currentLocalStatus'. Expira Atual DB: " . ($currentExpireDate ?: 'N/A'));

            // Determina se a atualização é realmente necessária
            $needsUpdate = false;
            if ($currentLocalStatus !== $newLocalStatus) {
                $needsUpdate = true;
                logMessage("    -> Mudança de Status: '$currentLocalStatus' -> '$newLocalStatus'. Update necessário.");
            }
            // Se o status for ACTIVE, verifica se a data de expiração precisa ser atualizada
            if ($newLocalStatus === 'ACTIVE' && $newExpireDate !== null && $currentExpireDate !== $newExpireDate) {
                 $needsUpdate = true;
                 logMessage("    -> Atualização da Data de Expiração: " . ($currentExpireDate ?: 'NULA') . " -> '$newExpireDate'. Update necessário.");
            }
            // Se o status NÃO for ACTIVE, verifica se a data de expiração precisa ser limpa (definida como NULL)
            elseif ($newLocalStatus !== 'ACTIVE' && $currentExpireDate !== null) {
                $needsUpdate = true;
                logMessage("    -> Limpeza da Data de Expiração necessária (status '$newLocalStatus'). Update necessário.");
            }

            // Executa o UPDATE apenas se necessário
            if ($needsUpdate) {
                $sqlUpdate = "UPDATE saas_users SET
                                subscription_status = :local_status,
                                is_saas_active = :is_active,
                                subscription_expires_at = :expires, -- Será definido como data ou NULL
                                updated_at = NOW()
                              WHERE asaas_subscription_id = :sub_id";

                $params = [
                    ':local_status' => $newLocalStatus,
                    ':sub_id' => $subscriptionId,
                    ':is_active' => ($newLocalStatus === 'ACTIVE'), // Define flag de atividade SaaS
                    // Define o valor para :expires
                    ':expires' => ($newLocalStatus === 'ACTIVE' && $newExpireDate !== null) ? $newExpireDate : null
                 ];

                $stmtUpdate = $pdo->prepare($sqlUpdate);
                $success = $stmtUpdate->execute($params);

                if ($success) {
                    logMessage("    -> SUCESSO: Update DB para Status '$newLocalStatus' e Expiração '" . ($params[':expires'] ?: 'NULL') . "'.");
                    // Opcional: Limpar cache de sessão do usuário aqui, se houver
                } else {
                    $errorInfo = $stmtUpdate->errorInfo();
                    logMessage("    -> ERRO SQL ao executar Update: " . ($errorInfo[2] ?? 'N/A'));
                    // Considerar lançar exceção para rollback? Por ora, só loga.
                }
            } else {
                 logMessage("    -> Nenhuma atualização necessária no DB (status e data já corretos).");
            }
        } else {
            // Recebeu webhook para uma assinatura que não está em nenhum usuário local
            logMessage("  [WH v1.2] AVISO: Nenhum usuário local encontrado para Asaas Subscription ID $subscriptionId. Verificar vínculo no DB.");
        }
    } else {
        // O evento recebido não resultou em nenhuma mudança de status local planejada
        logMessage("  [WH v1.2] Evento $eventName não resultou em mudança de status local. Nenhuma ação no DB.");
    }

    $pdo->commit(); // Confirma a transação se tudo correu bem
    http_response_code(200); // Responde OK para o Asaas
    logMessage("==== [Asaas Webhook Receiver v1.2] Processamento concluído para evento: $eventName ====");
    exit;

} catch (\PDOException $e) { // Captura erros de Banco de Dados
    if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
    logMessage("[Asaas Webhook v1.2] **** ERRO FATAL PDOException ****");
    logMessage("  Mensagem: {$e->getMessage()} | Evento: $eventName | Payload: " . substr($payload, 0, 500) . "...");
    http_response_code(500); // Erro interno do servidor
    exit;
} catch (\Throwable $e) { // Captura outros erros inesperados
    if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
    logMessage("[Asaas Webhook v1.2] **** ERRO FATAL INESPERADO (Throwable) ****");
    logMessage("  Tipo: " . get_class($e) . " | Mensagem: {$e->getMessage()} | Arquivo: {$e->getFile()} | Linha: {$e->getLine()} | Evento: $eventName");
    http_response_code(500); // Erro interno do servidor
    exit;
}
?>



<?php
/**
 * Arquivo: billing.php
 * Versão: v1.2 - Verifica DB se status sessão não ACTIVE, redireciona se DB ACTIVE.
 * Descrição: Exibe o status da assinatura (PENDING, OVERDUE, CANCELED) ou redireciona
 *            para o dashboard se a verificação no banco de dados confirmar que está ativa.
 *            Fornece botões para iniciar pagamento (novo) ou tentar pagar pendência.
 */

// Includes Essenciais
require_once __DIR__ . '/config.php'; // Para constantes ASAAS e de sessão (inicia sessão)
require_once __DIR__ . '/db.php';     // Para getDbConnection()
require_once __DIR__ . '/includes/log_helper.php'; // Para logMessage()
require_once __DIR__ . '/includes/helpers.php'; // Para getSubscriptionStatusClass()

// --- Proteção: Exige Login ---
if (!isset($_SESSION['saas_user_id'])) {
    header('Location: login.php?error=unauthorized');
    exit;
}
$saasUserId = $_SESSION['saas_user_id'];
$saasUserEmail = $_SESSION['saas_user_email'] ?? 'Usuário';

// --- Verifica Status Assinatura (Sessão e DB) ---
$subscriptionStatus = $_SESSION['subscription_status'] ?? null; // Pega status da sessão
$asaasCustomerId = $_SESSION['asaas_customer_id'] ?? null;     // Pega ID cliente da sessão
$userName = 'Usuário'; // Valor padrão
$billingMessage = null; // Para mensagens de erro/status nesta página
$billingMessageClass = '';

// Busca nome do usuário para personalização (melhoria visual)
try {
    $pdo = getDbConnection();
    $stmtName = $pdo->prepare("SELECT name FROM saas_users WHERE id = :id");
    $stmtName->execute([':id' => $saasUserId]);
    $nameData = $stmtName->fetch();
    if ($nameData && !empty($nameData['name'])) {
        $userName = $nameData['name'];
    }
} catch (\Exception $e) {
    logMessage("Erro buscar nome billing v1.2 (SaaS ID: $saasUserId): " . $e->getMessage());
    // Continua mesmo sem o nome
}

// ** VERIFICAÇÃO PRINCIPAL DE STATUS E REDIRECIONAMENTO **
// Se o status na SESSÃO não for ATIVO, verifica no BANCO DE DADOS
if ($subscriptionStatus !== 'ACTIVE') {
    $logMsg = "Billing v1.2: Sessão não ativa ($subscriptionStatus) para SaaS ID $saasUserId. Verificando DB...";
    function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
    try {
        // Reconsulta o DB para o status mais recente e ID do cliente Asaas
        $pdoCheck = getDbConnection(); // Reusa ou cria conexão
        $stmtCheck = $pdoCheck->prepare("SELECT subscription_status, asaas_customer_id FROM saas_users WHERE id = :id");
        $stmtCheck->execute([':id' => $saasUserId]);
        $dbData = $stmtCheck->fetch();
        $dbStatus = $dbData['subscription_status'] ?? 'INACTIVE'; // Assume INACTIVE se não encontrar usuário/status
        $dbAsaasCustomerId = $dbData['asaas_customer_id'] ?? null; // Pega ID Asaas do DB tbm

        // Atualiza as variáveis locais com os dados mais recentes do DB
        $subscriptionStatus = $dbStatus;
        if (!empty($dbAsaasCustomerId)) {
            $asaasCustomerId = $dbAsaasCustomerId;
        }
        // Atualiza a SESSÃO com os dados do DB para consistência futura
        $_SESSION['subscription_status'] = $dbStatus;
        if (!empty($dbAsaasCustomerId)) {
            $_SESSION['asaas_customer_id'] = $dbAsaasCustomerId;
        }

        // Se o status NO BANCO DE DADOS for ATIVO, redireciona imediatamente
        if ($dbStatus === 'ACTIVE') {
            $logMsg = "Billing v1.2: DB está ATIVO para SaaS ID $saasUserId. Sessão atualizada. Redirecionando para dashboard...";
            function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
            header('Location: dashboard.php'); // Redireciona para o painel principal
            exit;
        } else {
            // DB também não está ativo. Permite que a página de billing seja exibida com o status correto.
            $logMsg = "Billing v1.2: DB também NÃO está ATIVO ($dbStatus) para SaaS ID $saasUserId. Exibindo página de billing.";
            function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
        }
    } catch (\Exception $e) {
         // Erro ao consultar DB, não consegue verificar status real.
         // Exibe a página com uma mensagem de erro para o usuário.
         $logMsg = "Billing v1.2: Erro ao verificar DB status para $saasUserId: " . $e->getMessage();
         function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
         $billingMessage = ['type' => 'error', 'text' => 'Erro ao verificar o status atual da sua assinatura. Tente atualizar a página ou contate o suporte.'];
         // Permite que a página carregue para mostrar a mensagem de erro.
    }
} else {
     // Status da sessão já era ACTIVE, redireciona por segurança (não deveria chegar aqui normalmente)
     logMessage("Billing v1.2: Sessão já estava ATIVA para SaaS ID $saasUserId. Redirecionando para dashboard.");
     header('Location: dashboard.php');
     exit;
}
// --- Fim Verificação Status ---

// --- Processamento de Mensagens da URL ---
// Mapeamento de tipos de mensagem para classes Tailwind
$message_classes = [
    'error' => 'bg-red-100 border border-red-400 text-red-700 dark:bg-red-900 dark:border-red-700 dark:text-red-300',
    'warning' => 'bg-yellow-100 border border-yellow-400 text-yellow-700 dark:bg-yellow-900 dark:border-yellow-700 dark:text-yellow-300',
    'success' => 'bg-green-100 border border-green-400 text-green-700 dark:bg-green-900 dark:border-green-700 dark:text-green-300',
    'info' => 'bg-blue-100 border border-blue-400 text-blue-700 dark:bg-blue-900 dark:border-blue-700 dark:text-blue-300',
];

// Se não houve erro na verificação do DB, processa mensagens da URL
if (!$billingMessage) {
    if (isset($_GET['billing_status'])) {
        $status = $_GET['billing_status'];
        $reason = $_GET['reason'] ?? null;

        if ($status === 'link_error') {
             $msg = 'Não foi possível gerar ou obter o link de pagamento.';
             if ($reason === 'existing_not_found') $msg .= ' A fatura pendente/vencida não foi encontrada no Asaas.';
             elseif ($reason === 'new_sub_no_link') $msg .= ' A assinatura foi criada, mas o link inicial não foi obtido.';
              $billingMessage = ['type' => 'error', 'text' => $msg . ' Tente novamente ou contate o suporte.'];
        } elseif ($status === 'asaas_error') {
            $msg = 'Ocorreu um erro na comunicação com o sistema de pagamento.';
            if ($_GET['code'] === 'no_customer_id') $msg = 'Erro interno: ID de cliente Asaas não encontrado.';
            elseif ($_GET['code'] === 'sub_create_failed') $msg = 'Falha ao criar a assinatura no sistema de pagamento.';
             $billingMessage = ['type' => 'error', 'text' => $msg . ' Tente novamente mais tarde ou contate o suporte.'];
        } elseif ($status === 'db_error') {
             $billingMessage = ['type' => 'error', 'text' => 'Ocorreu um erro interno ao buscar seus dados.'];
        } elseif ($status === 'internal_error') {
             $billingMessage = ['type' => 'error', 'text' => 'Ocorreu um erro inesperado no servidor.'];
        } elseif ($status === 'inactive') { // Mensagem vinda de outras páginas como oauth_start
            $billingMessage = ['type' => 'warning', 'text' => 'Sua assinatura precisa estar ativa para realizar esta ação.'];
        }
    } elseif (isset($_GET['status']) && $_GET['status'] === 'registered') { // Mensagem vinda do register.php
        $billingMessage = ['type' => 'success', 'text' => '✅ Cadastro realizado! Faça o pagamento abaixo para ativar sua conta.'];
    }
}

// Define a classe CSS para a mensagem, se houver
if ($billingMessage && isset($message_classes[$billingMessage['type']])) {
    $billingMessageClass = $message_classes[$billingMessage['type']];
}

// Limpa os parâmetros GET da URL após lê-los
if (isset($_GET['billing_status']) || isset($_GET['status']) || isset($_GET['error'])) {
    echo "<script> if (history.replaceState) { setTimeout(function() { history.replaceState(null, null, window.location.pathname); }, 1); } </script>";
}

?>
<!DOCTYPE html>
<html lang="pt-br" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assinatura - Meli AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <style> /* Estilos específicos, se houver */ </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100 min-h-screen flex flex-col transition-colors duration-300">

    <section class="main-content container mx-auto px-4 py-8 max-w-2xl">
        <!-- Cabeçalho -->
        <header class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 mb-6">
             <div class="flex justify-between items-center">
                <h1 class="text-xl font-semibold flex items-center gap-2">
                     <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-blue-500"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 21.75Z" /></svg>
                     <span>Assinatura Meli AI</span>
                </h1>
                <a href="logout.php" class="text-sm text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300">Sair</a>
            </div>
        </header>

        <!-- Mensagem de Erro/Status da Página -->
        <?php if ($billingMessage && $billingMessageClass): ?>
            <div class="<?php echo htmlspecialchars($billingMessageClass); ?> px-4 py-3 rounded-md text-sm mb-6 flex justify-between items-center" role="alert">
                <span><?php echo htmlspecialchars($billingMessage['text']); ?></span>
                <button onclick="this.parentElement.style.display='none';" class="ml-4 -mr-1 p-1 rounded-md focus:outline-none focus:ring-2 focus:ring-current hover:bg-opacity-20 hover:bg-current" aria-label="Fechar">
                   <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
        <?php endif; ?>

        <!-- Conteúdo Principal da Página -->
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 space-y-6">
            <h2 class="text-lg font-semibold">Olá, <?php echo htmlspecialchars($userName); ?>!</h2>

            <?php // --- Cenário 1: Status PENDENTE ---
                  // O usuário acabou de se cadastrar ou a assinatura foi criada mas não paga.
            ?>
            <?php if ($subscriptionStatus === 'PENDING' && $asaasCustomerId): ?>
                <p class="text-gray-700 dark:text-gray-300">
                    Seu cadastro está quase completo! Para ativar todas as funcionalidades do Meli AI,
                    por favor, finalize o pagamento da sua assinatura trimestral.
                </p>
                <div class="border dark:border-gray-700 rounded p-4 space-y-2 bg-gray-50 dark:bg-gray-700">
                    <p><strong>Plano Selecionado:</strong> Trimestral</p>
                    <p><strong>Valor:</strong> R$ <?php echo number_format(ASAAS_PLAN_VALUE, 2, ',', '.'); ?> (a cada 3 meses)</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Acesso a todas as funcionalidades, incluindo respostas ilimitadas via IA e notificações WhatsApp.</p>
                </div>

                 <div class="text-center mt-6">
                    <p class="mb-4 text-gray-700 dark:text-gray-300">Clique abaixo para ir ao ambiente seguro de pagamento e ativar sua assinatura:</p>
                    <!-- Este link vai para go_to_asaas_payment.php -->
                    <!-- Se for a primeira vez (sem sub_id), ele cria a assinatura e redireciona. -->
                    <!-- Se a sub_id já existe, ele tenta buscar o link da fatura PENDENTE. -->
                    <a href="go_to_asaas_payment.php" target="_blank"
                       class="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-lg shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 dark:focus:ring-offset-gray-800">
                       Pagar Assinatura (R$ <?php echo number_format(ASAAS_PLAN_VALUE, 2, ',', '.'); ?>)
                    </a>
                     <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Você será redirecionado para o Asaas para concluir o pagamento com segurança.</p>
                     <p class="text-sm text-gray-600 dark:text-gray-400 mt-4">Após o pagamento ser confirmado, seu acesso será liberado automaticamente (pode levar alguns minutos). Você pode precisar atualizar esta página ou fazer login novamente.</p>
                 </div>

            <?php // --- Cenário 2: Status OVERDUE ---
                  // A fatura da assinatura está vencida.
            ?>
            <?php elseif ($subscriptionStatus === 'OVERDUE'): ?>
                <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 dark:bg-yellow-900/30 dark:border-yellow-600 dark:text-yellow-300 p-4 rounded-r-md" role="alert">
                    <p class="font-bold">Pagamento Pendente!</p>
                    <p>Identificamos um pagamento vencido para sua assinatura.</p>
                </div>
                <p class="text-gray-700 dark:text-gray-300 mt-4">
                    Para continuar utilizando o Meli AI sem interrupções, por favor, regularize sua situação.
                </p>
                 <div class="text-center mt-6">
                     <!-- Este link vai para go_to_asaas_payment.php, que tentará buscar o link da fatura OVERDUE -->
                     <a href="go_to_asaas_payment.php?action=retry" target="_blank"
                        class="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-lg shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                         Regularizar Pagamento
                    </a>
                     <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Você será redirecionado para o Asaas para visualizar e pagar a pendência.</p>
                 </div>

             <?php // --- Cenário 3: Status CANCELED ou INACTIVE ---
                   // A assinatura foi cancelada pelo usuário, pelo admin, ou expirou.
             ?>
             <?php elseif ($subscriptionStatus === 'CANCELED' || $subscriptionStatus === 'INACTIVE'): ?>
                 <div class="bg-red-100 border-l-4 border-red-500 text-red-700 dark:bg-red-900/30 dark:border-red-600 dark:text-red-300 p-4 rounded-r-md" role="alert">
                    <p class="font-bold">Assinatura Inativa</p>
                    <p>Sua assinatura do Meli AI não está ativa no momento (Status: <?php echo htmlspecialchars($subscriptionStatus);?>).</p>
                </div>
                 <p class="text-gray-700 dark:text-gray-300 mt-4">
                     Para reativar seu acesso e voltar a usar todas as funcionalidades, por favor, inicie uma nova assinatura.
                 </p>
                 <div class="text-center mt-6">
                     <!-- Este link vai para go_to_asaas_payment.php, que criará uma NOVA assinatura -->
                     <a href="go_to_asaas_payment.php" target="_blank"
                        class="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-lg shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 dark:focus:ring-offset-gray-800">
                         Iniciar Nova Assinatura Trimestral
                    </a>
                     <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Você será redirecionado para o Asaas.</p>
                 </div>

            <?php // --- Cenário 4: Erro ou status inesperado / Falta ID Cliente ---
                  // Se o status não for nenhum dos acima, ou se faltar o ID do cliente Asaas.
            ?>
            <?php else: ?>
                 <div class="bg-orange-100 border-l-4 border-orange-500 text-orange-700 dark:bg-orange-900/30 dark:border-orange-600 dark:text-orange-300 p-4 rounded-r-md" role="alert">
                     <p class="font-bold">Status Inesperado ou Incompleto</p>
                     <p>Não foi possível determinar o estado correto da sua assinatura ou o vínculo com nosso sistema de pagamentos.</p>
                      <p class="mt-2 text-sm">Status atual registrado: <?php echo htmlspecialchars($subscriptionStatus ?: 'N/D'); ?></p>
                       <?php if(!$asaasCustomerId): ?>
                        <p class="text-sm font-semibold mt-1">Importante: ID de cliente do sistema de pagamento não encontrado.</p>
                       <?php endif; ?>
                 </div>
                 <p class="text-gray-600 dark:text-gray-300 mt-4">
                     Se você acredita que isso é um erro, ou se acabou de se cadastrar e o erro persiste,
                     por favor, <a href="logout.php" class="text-blue-600 hover:underline dark:text-blue-400">saia da sua conta</a>
                     e tente fazer login novamente em alguns minutos. Se o problema continuar, entre em contato com o suporte.
                 </p>
            <?php endif; ?>

            <!-- Link para tentar acessar o Dashboard (sempre visível) -->
             <div class="text-center mt-8 border-t border-gray-200 dark:border-gray-700 pt-4">
                <a href="dashboard.php" class="text-sm text-blue-600 hover:underline dark:text-blue-400">Tentar Acessar o Dashboard</a>
             </div>

        </div> <!-- Fim do card principal -->

         <footer class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
             <p>© <?php echo date('Y'); ?> Meli AI</p>
         </footer>
    </section>

</body>
</html>


{
    "name": "seu-usuario/meliai",
    "description": "SaaS Responder ML Project",
    "type": "project",
    "require": {
        "php": ">=8.0",
        "defuse/php-encryption": "^2.4",
        "vlucas/phpdotenv": "^5.5"
    },
    "config": {
        "optimize-autoloader": true,
        "preferred-install": "dist",
        "sort-packages": true
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}



{
    "_readme": [
        "This file locks the dependencies of your project to a known state",
        "Read more about it at https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies",
        "This file is @generated automatically"
    ],
    "content-hash": "4d90e8d3de05bc96d87f8e086508cc68",
    "packages": [
        {
            "name": "defuse/php-encryption",
            "version": "v2.4.0",
            "source": {
                "type": "git",
                "url": "https://github.com/defuse/php-encryption.git",
                "reference": "f53396c2d34225064647a05ca76c1da9d99e5828"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/defuse/php-encryption/zipball/f53396c2d34225064647a05ca76c1da9d99e5828",
                "reference": "f53396c2d34225064647a05ca76c1da9d99e5828",
                "shasum": ""
            },
            "require": {
                "ext-openssl": "*",
                "paragonie/random_compat": ">= 2",
                "php": ">=5.6.0"
            },
            "require-dev": {
                "phpunit/phpunit": "^5|^6|^7|^8|^9|^10",
                "yoast/phpunit-polyfills": "^2.0.0"
            },
            "bin": [
                "bin/generate-defuse-key"
            ],
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Defuse\\Crypto\\": "src"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Taylor Hornby",
                    "email": "taylor@defuse.ca",
                    "homepage": "https://defuse.ca/"
                },
                {
                    "name": "Scott Arciszewski",
                    "email": "info@paragonie.com",
                    "homepage": "https://paragonie.com"
                }
            ],
            "description": "Secure PHP Encryption Library",
            "keywords": [
                "aes",
                "authenticated encryption",
                "cipher",
                "crypto",
                "cryptography",
                "encrypt",
                "encryption",
                "openssl",
                "security",
                "symmetric key cryptography"
            ],
            "support": {
                "issues": "https://github.com/defuse/php-encryption/issues",
                "source": "https://github.com/defuse/php-encryption/tree/v2.4.0"
            },
            "time": "2023-06-19T06:10:36+00:00"
        },
        {
            "name": "graham-campbell/result-type",
            "version": "v1.1.3",
            "source": {
                "type": "git",
                "url": "https://github.com/GrahamCampbell/Result-Type.git",
                "reference": "3ba905c11371512af9d9bdd27d99b782216b6945"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/GrahamCampbell/Result-Type/zipball/3ba905c11371512af9d9bdd27d99b782216b6945",
                "reference": "3ba905c11371512af9d9bdd27d99b782216b6945",
                "shasum": ""
            },
            "require": {
                "php": "^7.2.5 || ^8.0",
                "phpoption/phpoption": "^1.9.3"
            },
            "require-dev": {
                "phpunit/phpunit": "^8.5.39 || ^9.6.20 || ^10.5.28"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "GrahamCampbell\\ResultType\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Graham Campbell",
                    "email": "hello@gjcampbell.co.uk",
                    "homepage": "https://github.com/GrahamCampbell"
                }
            ],
            "description": "An Implementation Of The Result Type",
            "keywords": [
                "Graham Campbell",
                "GrahamCampbell",
                "Result Type",
                "Result-Type",
                "result"
            ],
            "support": {
                "issues": "https://github.com/GrahamCampbell/Result-Type/issues",
                "source": "https://github.com/GrahamCampbell/Result-Type/tree/v1.1.3"
            },
            "funding": [
                {
                    "url": "https://github.com/GrahamCampbell",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/graham-campbell/result-type",
                    "type": "tidelift"
                }
            ],
            "time": "2024-07-20T21:45:45+00:00"
        },
        {
            "name": "paragonie/random_compat",
            "version": "v9.99.100",
            "source": {
                "type": "git",
                "url": "https://github.com/paragonie/random_compat.git",
                "reference": "996434e5492cb4c3edcb9168db6fbb1359ef965a"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/paragonie/random_compat/zipball/996434e5492cb4c3edcb9168db6fbb1359ef965a",
                "reference": "996434e5492cb4c3edcb9168db6fbb1359ef965a",
                "shasum": ""
            },
            "require": {
                "php": ">= 7"
            },
            "require-dev": {
                "phpunit/phpunit": "4.*|5.*",
                "vimeo/psalm": "^1"
            },
            "suggest": {
                "ext-libsodium": "Provides a modern crypto API that can be used to generate random bytes."
            },
            "type": "library",
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Paragon Initiative Enterprises",
                    "email": "security@paragonie.com",
                    "homepage": "https://paragonie.com"
                }
            ],
            "description": "PHP 5.x polyfill for random_bytes() and random_int() from PHP 7",
            "keywords": [
                "csprng",
                "polyfill",
                "pseudorandom",
                "random"
            ],
            "support": {
                "email": "info@paragonie.com",
                "issues": "https://github.com/paragonie/random_compat/issues",
                "source": "https://github.com/paragonie/random_compat"
            },
            "time": "2020-10-15T08:29:30+00:00"
        },
        {
            "name": "phpoption/phpoption",
            "version": "1.9.3",
            "source": {
                "type": "git",
                "url": "https://github.com/schmittjoh/php-option.git",
                "reference": "e3fac8b24f56113f7cb96af14958c0dd16330f54"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/schmittjoh/php-option/zipball/e3fac8b24f56113f7cb96af14958c0dd16330f54",
                "reference": "e3fac8b24f56113f7cb96af14958c0dd16330f54",
                "shasum": ""
            },
            "require": {
                "php": "^7.2.5 || ^8.0"
            },
            "require-dev": {
                "bamarni/composer-bin-plugin": "^1.8.2",
                "phpunit/phpunit": "^8.5.39 || ^9.6.20 || ^10.5.28"
            },
            "type": "library",
            "extra": {
                "bamarni-bin": {
                    "bin-links": true,
                    "forward-command": false
                },
                "branch-alias": {
                    "dev-master": "1.9-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "PhpOption\\": "src/PhpOption/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "Apache-2.0"
            ],
            "authors": [
                {
                    "name": "Johannes M. Schmitt",
                    "email": "schmittjoh@gmail.com",
                    "homepage": "https://github.com/schmittjoh"
                },
                {
                    "name": "Graham Campbell",
                    "email": "hello@gjcampbell.co.uk",
                    "homepage": "https://github.com/GrahamCampbell"
                }
            ],
            "description": "Option Type for PHP",
            "keywords": [
                "language",
                "option",
                "php",
                "type"
            ],
            "support": {
                "issues": "https://github.com/schmittjoh/php-option/issues",
                "source": "https://github.com/schmittjoh/php-option/tree/1.9.3"
            },
            "funding": [
                {
                    "url": "https://github.com/GrahamCampbell",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/phpoption/phpoption",
                    "type": "tidelift"
                }
            ],
            "time": "2024-07-20T21:41:07+00:00"
        },
        {
            "name": "symfony/polyfill-ctype",
            "version": "v1.32.0",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/polyfill-ctype.git",
                "reference": "a3cc8b044a6ea513310cbd48ef7333b384945638"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/polyfill-ctype/zipball/a3cc8b044a6ea513310cbd48ef7333b384945638",
                "reference": "a3cc8b044a6ea513310cbd48ef7333b384945638",
                "shasum": ""
            },
            "require": {
                "php": ">=7.2"
            },
            "provide": {
                "ext-ctype": "*"
            },
            "suggest": {
                "ext-ctype": "For best performance"
            },
            "type": "library",
            "extra": {
                "thanks": {
                    "url": "https://github.com/symfony/polyfill",
                    "name": "symfony/polyfill"
                }
            },
            "autoload": {
                "files": [
                    "bootstrap.php"
                ],
                "psr-4": {
                    "Symfony\\Polyfill\\Ctype\\": ""
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Gert de Pagter",
                    "email": "BackEndTea@gmail.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Symfony polyfill for ctype functions",
            "homepage": "https://symfony.com",
            "keywords": [
                "compatibility",
                "ctype",
                "polyfill",
                "portable"
            ],
            "support": {
                "source": "https://github.com/symfony/polyfill-ctype/tree/v1.32.0"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2024-09-09T11:45:10+00:00"
        },
        {
            "name": "symfony/polyfill-mbstring",
            "version": "v1.32.0",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/polyfill-mbstring.git",
                "reference": "6d857f4d76bd4b343eac26d6b539585d2bc56493"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/polyfill-mbstring/zipball/6d857f4d76bd4b343eac26d6b539585d2bc56493",
                "reference": "6d857f4d76bd4b343eac26d6b539585d2bc56493",
                "shasum": ""
            },
            "require": {
                "ext-iconv": "*",
                "php": ">=7.2"
            },
            "provide": {
                "ext-mbstring": "*"
            },
            "suggest": {
                "ext-mbstring": "For best performance"
            },
            "type": "library",
            "extra": {
                "thanks": {
                    "url": "https://github.com/symfony/polyfill",
                    "name": "symfony/polyfill"
                }
            },
            "autoload": {
                "files": [
                    "bootstrap.php"
                ],
                "psr-4": {
                    "Symfony\\Polyfill\\Mbstring\\": ""
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Nicolas Grekas",
                    "email": "p@tchwork.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Symfony polyfill for the Mbstring extension",
            "homepage": "https://symfony.com",
            "keywords": [
                "compatibility",
                "mbstring",
                "polyfill",
                "portable",
                "shim"
            ],
            "support": {
                "source": "https://github.com/symfony/polyfill-mbstring/tree/v1.32.0"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2024-12-23T08:48:59+00:00"
        },
        {
            "name": "symfony/polyfill-php80",
            "version": "v1.32.0",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/polyfill-php80.git",
                "reference": "0cc9dd0f17f61d8131e7df6b84bd344899fe2608"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/polyfill-php80/zipball/0cc9dd0f17f61d8131e7df6b84bd344899fe2608",
                "reference": "0cc9dd0f17f61d8131e7df6b84bd344899fe2608",
                "shasum": ""
            },
            "require": {
                "php": ">=7.2"
            },
            "type": "library",
            "extra": {
                "thanks": {
                    "url": "https://github.com/symfony/polyfill",
                    "name": "symfony/polyfill"
                }
            },
            "autoload": {
                "files": [
                    "bootstrap.php"
                ],
                "psr-4": {
                    "Symfony\\Polyfill\\Php80\\": ""
                },
                "classmap": [
                    "Resources/stubs"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Ion Bazan",
                    "email": "ion.bazan@gmail.com"
                },
                {
                    "name": "Nicolas Grekas",
                    "email": "p@tchwork.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Symfony polyfill backporting some PHP 8.0+ features to lower PHP versions",
            "homepage": "https://symfony.com",
            "keywords": [
                "compatibility",
                "polyfill",
                "portable",
                "shim"
            ],
            "support": {
                "source": "https://github.com/symfony/polyfill-php80/tree/v1.32.0"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2025-01-02T08:10:11+00:00"
        },
        {
            "name": "vlucas/phpdotenv",
            "version": "v5.6.2",
            "source": {
                "type": "git",
                "url": "https://github.com/vlucas/phpdotenv.git",
                "reference": "24ac4c74f91ee2c193fa1aaa5c249cb0822809af"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/vlucas/phpdotenv/zipball/24ac4c74f91ee2c193fa1aaa5c249cb0822809af",
                "reference": "24ac4c74f91ee2c193fa1aaa5c249cb0822809af",
                "shasum": ""
            },
            "require": {
                "ext-pcre": "*",
                "graham-campbell/result-type": "^1.1.3",
                "php": "^7.2.5 || ^8.0",
                "phpoption/phpoption": "^1.9.3",
                "symfony/polyfill-ctype": "^1.24",
                "symfony/polyfill-mbstring": "^1.24",
                "symfony/polyfill-php80": "^1.24"
            },
            "require-dev": {
                "bamarni/composer-bin-plugin": "^1.8.2",
                "ext-filter": "*",
                "phpunit/phpunit": "^8.5.34 || ^9.6.13 || ^10.4.2"
            },
            "suggest": {
                "ext-filter": "Required to use the boolean validator."
            },
            "type": "library",
            "extra": {
                "bamarni-bin": {
                    "bin-links": true,
                    "forward-command": false
                },
                "branch-alias": {
                    "dev-master": "5.6-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Dotenv\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Graham Campbell",
                    "email": "hello@gjcampbell.co.uk",
                    "homepage": "https://github.com/GrahamCampbell"
                },
                {
                    "name": "Vance Lucas",
                    "email": "vance@vancelucas.com",
                    "homepage": "https://github.com/vlucas"
                }
            ],
            "description": "Loads environment variables from `.env` to `getenv()`, `$_ENV` and `$_SERVER` automagically.",
            "keywords": [
                "dotenv",
                "env",
                "environment"
            ],
            "support": {
                "issues": "https://github.com/vlucas/phpdotenv/issues",
                "source": "https://github.com/vlucas/phpdotenv/tree/v5.6.2"
            },
            "funding": [
                {
                    "url": "https://github.com/GrahamCampbell",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/vlucas/phpdotenv",
                    "type": "tidelift"
                }
            ],
            "time": "2025-04-30T23:37:27+00:00"
        }
    ],
    "packages-dev": [],
    "aliases": [],
    "minimum-stability": "stable",
    "stability-flags": {},
    "prefer-stable": true,
    "prefer-lowest": false,
    "platform": {
        "php": ">=8.0"
    },
    "platform-dev": {},
    "plugin-api-version": "2.6.0"
}



<?php
/**
 * Arquivo: config.php
 * Versão: v2.1 - Robusto: Carrega segredos de arquivo externo, verifica erros, inclui autoloader.
 * Descrição: Ponto central de configuração e inicialização para Meli AI.
 */

// --- Definição do Caminho Base da Aplicação ---
// __DIR__ é o diretório onde este arquivo (config.php) está localizado.
// Ex: /home/u267339178/domains/d3ecom.com.br/public_html/meliai
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

// --- Configuração de Erros para PRODUÇÃO ---
// Essencial para segurança: Não exibir erros, apenas logá-los.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
// Reportar todos os erros exceto E_DEPRECATED e E_NOTICE (ajuste se precisar deles no log)
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('log_errors', '1');

// Define o caminho do arquivo de log de erros do PHP.
// Tenta criar uma pasta 'php_logs' UM NÍVEL ACIMA da pasta da aplicação (melhor para segurança).
// Ex: /home/u267339178/domains/d3ecom.com.br/php_logs/
$phpLogDir = dirname(BASE_PATH) . '/php_logs'; // Nome da pasta de logs um nível acima
if (!is_dir($phpLogDir)) {
    // Tenta criar o diretório recursivamente com permissões adequadas.
    // O @ suprime erros caso o diretório já exista ou não tenha permissão para criar.
    @mkdir($phpLogDir, 0775, true);
}
// Verifica se o diretório foi criado ou já existia e é gravável.
if (is_dir($phpLogDir) && is_writable($phpLogDir)) {
    ini_set('error_log', $phpLogDir . '/php_errors.log');
} else {
    // Fallback: Logar dentro da pasta da aplicação (menos seguro).
    // Garanta que esta pasta/arquivo seja protegido por .htaccess!
    $fallbackLogPath = BASE_PATH . '/php_errors.log';
    ini_set('error_log', $fallbackLogPath);
    // Loga um aviso sobre o fallback apenas uma vez para não poluir o log principal
    static $fallbackLoggedCfg = false;
    if (!$fallbackLoggedCfg) {
        error_log("AVISO CRÍTICO (config.php): Diretório de log preferencial ('$phpLogDir') não é gravável ou não existe. Usando fallback: '$fallbackLogPath'. PROTEJA ESTE ARQUIVO COM .HTACCESS!");
        $fallbackLoggedCfg = true;
    }
}
/* Exemplo .htaccess na pasta 'meliai' para proteger logs:
<FilesMatch "\.(log)$">
  Require all denied
</FilesMatch>
*/


// --- Carregar Segredos do Arquivo Externo ---
// Define o caminho para o arquivo de segredos.
// Sobe DOIS níveis a partir de BASE_PATH (meliai) para chegar em /home/u.../domains/d3ecom.com.br/
// e então entra na pasta 'meliai_secure'. **Confirme se 'meliai_secure' é o nome correto.**
$secretsFilePath = dirname(dirname(BASE_PATH)) . '/meliai_secure/secrets.php';

// Verifica se o arquivo de segredos existe e é legível.
if (!file_exists($secretsFilePath)) {
    $errorMessage = "ERRO CRÍTICO (config.php): Arquivo de segredos NÃO ENCONTRADO em '$secretsFilePath'. Verifique o caminho e o nome da pasta/arquivo.";
    error_log($errorMessage);
    http_response_code(500);
    die("Erro crítico de configuração do servidor (Code: SEC01). Por favor, contate o suporte.");
}
if (!is_readable($secretsFilePath)) {
    $errorMessage = "ERRO CRÍTICO (config.php): Arquivo de segredos encontrado em '$secretsFilePath' mas NÃO PODE SER LIDO. Verifique as permissões do arquivo (ex: 644 ou 640) e da pasta pai ('meliai_secure', ex: 755 ou 750).";
    error_log($errorMessage);
    http_response_code(500);
    die("Erro crítico de configuração do servidor (Code: SEC02). Por favor, contate o suporte.");
}

// Tenta carregar o array de segredos. Erros de sintaxe no secrets.php causarão erro fatal aqui.
try {
    $secrets = require $secretsFilePath;
} catch (\Throwable $e) { // Captura ParseError ou outros erros ao incluir
    $errorMessage = "ERRO CRÍTICO (config.php): Falha ao incluir/parsear o arquivo de segredos '$secretsFilePath'. Verifique a sintaxe PHP dentro dele. Erro: " . $e->getMessage();
    error_log($errorMessage);
    http_response_code(500);
    die("Erro crítico de configuração do servidor (Code: SEC03). Por favor, contate o suporte.");
}

// Verifica se o arquivo retornou um array válido
if (!is_array($secrets)) {
    $errorMessage = "ERRO CRÍTICO (config.php): Arquivo de segredos ('$secretsFilePath') não retornou um array PHP válido.";
    error_log($errorMessage);
    http_response_code(500);
    die("Erro crítico de configuração do servidor (Code: SEC04). Por favor, contate o suporte.");
}


// --- Composer Autoloader ---
// Caminho para o autoloader gerado pelo Composer.
$autoloaderPath = BASE_PATH . '/vendor/autoload.php';

// Verifica se o autoloader existe.
if (!file_exists($autoloaderPath)) {
    $errorMessage = "ERRO CRÍTICO (config.php): Autoloader do Composer não encontrado em '$autoloaderPath'. Verifique se a pasta 'vendor' foi enviada corretamente para '" . BASE_PATH . "'.";
    error_log($errorMessage);
    http_response_code(500);
    die("Erro crítico de inicialização do sistema (Code: AUT01). Dependências não encontradas.");
}
if (!is_readable($autoloaderPath)) {
     $errorMessage = "ERRO CRÍTICO (config.php): Autoloader do Composer encontrado em '$autoloaderPath' mas NÃO PODE SER LIDO. Verifique as permissões do arquivo (644) e da pasta 'vendor' (755).";
     error_log($errorMessage);
     http_response_code(500);
     die("Erro crítico de inicialização do sistema (Code: AUT03). Falha de permissão nas dependências.");
}

// Inclui o autoloader. Se houver erro aqui, é provável que o arquivo esteja corrompido.
try {
    require_once $autoloaderPath;
} catch (\Throwable $e) {
     $errorMessage = "ERRO CRÍTICO (config.php): Falha ao executar o autoloader '$autoloaderPath'. Pode estar corrompido. Erro: " . $e->getMessage();
     error_log($errorMessage);
     http_response_code(500);
     die("Erro crítico de inicialização do sistema (Code: AUT02). Falha ao carregar dependências.");
}


// --- Sessão ---
date_default_timezone_set('America/Sao_Paulo');
if (session_status() == PHP_SESSION_NONE) {
    // TODO: Configurar segurança da sessão (via php.ini, user.ini ou ini_set aqui)
    // Exemplo:
    // ini_set('session.cookie_httponly', 1); // Impede acesso via JS
    // ini_set('session.use_strict_mode', 1); // Usa apenas IDs de sessão válidos
    // if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    //     ini_set('session.cookie_secure', 1); // Envia cookie apenas sobre HTTPS
    // }
    session_start();
}


// --- Definição de Constantes Globais ---
// Usa o array $secrets carregado para definir os valores.
// O operador ?? garante um valor padrão caso a chave não exista no $secrets.

// Sistema
define('LOG_FILE', BASE_PATH . '/poll.log'); // Log da aplicação

// Banco de Dados
define('DB_HOST', $secrets['DB_HOST'] ?? 'localhost');
define('DB_NAME', $secrets['DB_NAME'] ?? '');
define('DB_USER', $secrets['DB_USER'] ?? '');
define('DB_PASS', $secrets['DB_PASS'] ?? ''); // Essencial que não seja vazio

// IA (Google Gemini)
define('GOOGLE_API_KEY', $secrets['GOOGLE_API_KEY'] ?? '');
define('GEMINI_API_ENDPOINT', $secrets['GEMINI_API_ENDPOINT'] ?? 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-pro-latest:generateContent');
define('AI_FALLBACK_TIMEOUT_MINUTES', 10); // Não secreto

// Notificação (Evolution API)
define('EVOLUTION_API_URL', $secrets['EVOLUTION_API_URL'] ?? '');
define('EVOLUTION_INSTANCE_NAME', $secrets['EVOLUTION_INSTANCE_NAME'] ?? '');
define('EVOLUTION_API_KEY', $secrets['EVOLUTION_API_KEY'] ?? '');
define('EVOLUTION_WEBHOOK_TOKEN', $secrets['EVOLUTION_WEBHOOK_TOKEN'] ?? ''); // Se usar

// Aplicação Mercado Livre
define('ML_APP_ID', $secrets['ML_APP_ID'] ?? '');
define('ML_SECRET_KEY', $secrets['ML_SECRET_KEY'] ?? '');
define('ML_REDIRECT_URI', $secrets['ML_REDIRECT_URI'] ?? '');
define('ML_WEBHOOK_SECRET', $secrets['ML_WEBHOOK_SECRET'] ?? ''); // Se usar

// APIs Mercado Livre (Públicas)
define('ML_AUTH_URL', 'https://auth.mercadolivre.com.br/authorization');
define('ML_TOKEN_URL', 'https://api.mercadolibre.com/oauth/token');
define('ML_API_BASE_URL', 'https://api.mercadolibre.com');

// Pagamentos (Asaas)
define('ASAAS_API_URL', $secrets['ASAAS_API_URL'] ?? 'https://api.asaas.com/v3/');
define('ASAAS_API_KEY', $secrets['ASAAS_API_KEY'] ?? '');
define('ASAAS_PLAN_VALUE', 149.90); // Não secreto
define('ASAAS_PLAN_CYCLE', 'QUARTERLY'); // Não secreto
define('ASAAS_PLAN_DESCRIPTION', 'Meli AI - Assinatura Trimestral'); // Não secreto
define('ASAAS_WEBHOOK_URL', $secrets['ASAAS_WEBHOOK_URL'] ?? ''); // Não secreto
define('ASAAS_WEBHOOK_SECRET', $secrets['ASAAS_WEBHOOK_SECRET'] ?? ''); // Essencial

// --- Segurança (CRIPTOGRAFIA) ---
// A chave 'APP_ENCRYPTION_KEY' está no array $secrets e será usada diretamente
// pela função loadEncryptionKey() em db.php. Nenhuma constante definida aqui.


// --- Verificação Final de Configurações Críticas ---
// Garante que as constantes/segredos mais importantes não estão vazios.
$criticalConfigs = [
    'DB_PASS', 'GOOGLE_API_KEY', 'ML_SECRET_KEY', 'ASAAS_API_KEY',
    'ASAAS_WEBHOOK_SECRET', 'EVOLUTION_API_KEY', 'APP_ENCRYPTION_KEY'
];
$missingConfig = [];
foreach ($criticalConfigs as $key) {
    // Verifica se a chave existe no array $secrets e não é vazia
    if (empty($secrets[$key])) {
        $missingConfig[] = $key . ' (em secrets.php)';
    }
}

if (!empty($missingConfig)) {
    $errorMessage = "ERRO CRÍTICO Config: Segredos essenciais não definidos ou vazios no arquivo '$secretsFilePath': " . implode(', ', $missingConfig);
    error_log($errorMessage);
    http_response_code(500);
    die("Erro crítico de configuração do servidor (Code: CFG05). Chaves essenciais ausentes. Contate o administrador.");
}

// --- Inclusão de Helpers Essenciais ---
// Inclui helpers DEPOIS que toda a configuração e o autoloader estão prontos.
// Garante que as funções de log e curl estejam disponíveis globalmente se necessário.
require_once BASE_PATH . '/includes/log_helper.php';
require_once BASE_PATH . '/includes/curl_helper.php';
// Inclua outros helpers globais aqui, se houver (ex: helpers.php)
require_once BASE_PATH . '/includes/helpers.php';


// --- Log de Sucesso (Opcional para Debug) ---
// static $configLoaded = false;
// if (!$configLoaded) {
//    if (function_exists('logMessage')) { logMessage("Configuração Meli AI (v2.1) carregada com sucesso."); }
//    else { error_log("Configuração Meli AI (v2.1) carregada com sucesso."); }
//    $configLoaded = true;
// }



<?php
/**
 * Arquivo: dashboard.php
 * Versão: v7.5 - Verifica DB status se sessão não ativa, Exibe Status Assinatura.
 * Descrição: Painel de controle do usuário SaaS. Garante que o acesso só é permitido
 *            se a assinatura estiver ativa (verificando sessão e, se necessário, DB).
 *            Exibe o status da assinatura no cabeçalho e o link para Billing.
 *            Confirma ID da div#tab-historico.
 */

// --- Includes Essenciais ---
require_once __DIR__ . '/config.php'; // Inicia sessão implicitamente
require_once __DIR__ . '/db.php';     // Para getDbConnection()
require_once __DIR__ . '/includes/log_helper.php'; // Para logMessage() (se existir no include path)
require_once __DIR__ . '/includes/helpers.php'; // Inclui getSubscriptionStatusClass() e getStatusTagClasses()

// --- Proteção: Exige Login ---
if (!isset($_SESSION['saas_user_id'])) {
    header('Location: login.php?error=unauthorized');
    exit;
}
$saasUserId = $_SESSION['saas_user_id'];
$saasUserEmail = $_SESSION['saas_user_email'] ?? 'Usuário'; // Pega email da sessão (definido no login)

// *** Proteção de Assinatura Ativa (com verificação DB como fallback) ***
$subscriptionStatus = $_SESSION['subscription_status'] ?? null; // Pega status da sessão

// Se o status na SESSÃO não for explicitamente 'ACTIVE', verifica no DB
if ($subscriptionStatus !== 'ACTIVE') {
    $logMsg = "Dashboard v7.5: Sessão não ativa ($subscriptionStatus) para SaaS ID $saasUserId. Verificando DB...";
    function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);

    try {
        $pdoCheck = getDbConnection();
        // Consulta apenas o status da assinatura no DB
        $stmtCheck = $pdoCheck->prepare("SELECT subscription_status FROM saas_users WHERE id = :id");
        $stmtCheck->execute([':id' => $saasUserId]);
        $dbStatusData = $stmtCheck->fetch();
        // Assume INACTIVE se usuário não for encontrado ou status for NULL/vazio no DB
        $dbStatus = $dbStatusData['subscription_status'] ?? 'INACTIVE';

        // Se o status no DB for ATIVO, atualiza a sessão e permite o acesso
        if ($dbStatus === 'ACTIVE') {
            $_SESSION['subscription_status'] = 'ACTIVE'; // Corrige a sessão
            $subscriptionStatus = 'ACTIVE'; // Atualiza a variável local para o resto do script
            $logMsg = "Dashboard v7.5: DB está ATIVO para SaaS ID $saasUserId. Sessão atualizada. Acesso permitido.";
            function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
            // Permite que o script continue para carregar o dashboard
        } else {
            // Se o DB também confirma que não está ativo, redireciona para billing
            $logMsg = "Dashboard v7.5: DB também NÃO está ATIVO ($dbStatus) para SaaS ID $saasUserId. Redirecionando para billing.";
            function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
            header('Location: billing.php?error=subscription_required'); // Informa o motivo
            exit;
        }
    } catch (\Exception $e) {
         // Em caso de erro ao verificar o DB, redireciona para billing por segurança
         $logMsg = "Dashboard v7.5: Erro CRÍTICO ao verificar DB status para $saasUserId: " . $e->getMessage() . ". Redirecionando para billing.";
         function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
         // Limpa status da sessão para evitar loops se o erro DB persistir
         unset($_SESSION['subscription_status']);
         header('Location: billing.php?error=db_check_failed'); // Informa erro na checagem
         exit;
    }
}
// *** FIM PROTEÇÃO ASSINATURA ***

// --- Se chegou aqui, a assinatura está ATIVA (confirmado via sessão ou DB) ---
logMessage("Dashboard v7.5: Acesso permitido para SaaS User ID $saasUserId (Status: $subscriptionStatus)");

// --- Inicialização de Variáveis do Dashboard ---
$mlConnection = null;          // Dados da conexão ML
$logsParaHistorico = [];     // Array para todos os logs (histórico completo)
$saasUserProfile = null;       // Dados do perfil do usuário SaaS
$currentDDDNumber = '';        // DDD + Número do WhatsApp (para preencher campo)
$dashboardMessage = null;      // Mensagens de feedback (ex: conexão ML, perfil salvo)
$dashboardMessageClass = ''; // Classe CSS para a mensagem de feedback
$isCurrentUserSuperAdmin = false; // Flag se o usuário é Super Admin

// --- Conexão DB e Busca de Dados ---
try {
    $pdo = getDbConnection();

    // 1. Buscar Dados do Perfil SaaS (Email, JID, Flag Super Admin)
    $stmtProfile = $pdo->prepare("SELECT email, whatsapp_jid, is_super_admin FROM saas_users WHERE id = :saas_user_id LIMIT 1");
    $stmtProfile->execute([':saas_user_id' => $saasUserId]);
    $saasUserProfile = $stmtProfile->fetch();

    // Define flag Super Admin e atualiza email na sessão se necessário
    if ($saasUserProfile && isset($saasUserProfile['is_super_admin']) && $saasUserProfile['is_super_admin']) {
        $isCurrentUserSuperAdmin = true;
    }
    if ($saasUserProfile && empty($saasUserEmail) && !empty($saasUserProfile['email'])) {
        $saasUserEmail = $saasUserProfile['email'];
        $_SESSION['saas_user_email'] = $saasUserEmail; // Atualiza sessão para consistência
    }

    // 2. Buscar Dados da Conexão Mercado Livre
    $stmtML = $pdo->prepare("SELECT id, ml_user_id, is_active, updated_at FROM mercadolibre_users WHERE saas_user_id = :saas_user_id LIMIT 1");
    $stmtML->execute([':saas_user_id' => $saasUserId]);
    $mlConnection = $stmtML->fetch();

    // 3. Buscar Histórico de Logs de Processamento de Perguntas
    $logLimit = 150; // Define quantos logs buscar para o histórico
    $logStmtHist = $pdo->prepare(
        "SELECT ml_question_id, item_id, question_text, status, ia_response_text, error_message, sent_to_whatsapp_at, ai_answered_at, human_answered_at, last_processed_at
         FROM question_processing_log
         WHERE saas_user_id = :saas_user_id
         ORDER BY last_processed_at DESC
         LIMIT :limit"
    );
    $logStmtHist->bindParam(':saas_user_id', $saasUserId, PDO::PARAM_INT);
    $logStmtHist->bindParam(':limit', $logLimit, PDO::PARAM_INT);
    $logStmtHist->execute();
    $logsParaHistorico = $logStmtHist->fetchAll();

    // 4. Extrair DDD + Número do JID (para preencher campo no perfil)
    if ($saasUserProfile && !empty($saasUserProfile['whatsapp_jid'])) {
        if (preg_match('/^55(\d{10,11})@s\.whatsapp\.net$/', $saasUserProfile['whatsapp_jid'], $matches)) {
            $currentDDDNumber = $matches[1]; // Captura DDD+Número
        }
    }

} catch (\PDOException | \Exception $e) {
    $logMsg = "Erro DB/Geral Dashboard v7.5 (SaaS User ID $saasUserId): " . $e->getMessage();
    function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
    // Define uma mensagem de erro para exibir no dashboard
    $dashboardMessage = ['type' => 'is-danger is-light', 'text' => '⚠️ Erro ao carregar dados do dashboard. Algumas informações podem não estar disponíveis.'];
}

// --- Trata mensagens de status vindas da URL (igual anterior) ---
$message_classes = [
    'is-success' => 'bg-green-100 dark:bg-green-900 border border-green-300 dark:border-green-700 text-green-700 dark:text-green-300',
    'is-info is-light' => 'bg-blue-100 dark:bg-blue-900 border border-blue-300 dark:border-blue-700 text-blue-700 dark:text-blue-300',
    'is-danger is-light' => 'bg-red-100 dark:bg-red-900 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300',
    'is-warning is-light' => 'bg-yellow-100 dark:bg-yellow-900 border border-yellow-400 dark:border-yellow-700 text-yellow-800 dark:text-yellow-300',
    // Adicione outros mapeamentos se necessário
];

if (isset($_GET['status'])) {
    $status = $_GET['status'];
    if ($status === 'ml_connected') { $dashboardMessage = ['type' => 'is-success', 'text' => '✅ Conta Mercado Livre conectada/atualizada com sucesso!']; }
    elseif ($status === 'ml_error') { $code = $_GET['code'] ?? 'unknown'; $dashboardMessage = ['type' => 'is-danger is-light', 'text' => "❌ Erro ao conectar com Mercado Livre (Código: $code). Tente novamente."]; }
} elseif (isset($_GET['profile_status'])) {
    $p_status = $_GET['profile_status'];
    if ($p_status === 'updated') { $dashboardMessage = ['type' => 'is-success', 'text' => '✅ Perfil atualizado com sucesso!']; }
    elseif ($p_status === 'error') { $code = $_GET['code'] ?? 'generic'; $dashboardMessage = ['type' => 'is-danger is-light', 'text' => "❌ Erro ao atualizar perfil (Código: $code). Verifique os dados e tente novamente."]; }
}

// Define a classe CSS da mensagem se houver uma
if ($dashboardMessage && isset($message_classes[$dashboardMessage['type']])) {
    $dashboardMessageClass = $message_classes[$dashboardMessage['type']];
}
// Limpa os parâmetros da URL para não persistirem
if (isset($_GET['status']) || isset($_GET['profile_status'])){
    echo "<script> if (history.replaceState) { setTimeout(function() { history.replaceState(null, null, window.location.pathname + window.location.hash); }, 1); } </script>";
}

// (A função getStatusTagClasses agora está em helpers.php e é incluída no início)

?>
<!DOCTYPE html>
<html lang="pt-br" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Meli AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <!-- Adicione outros links CSS ou JS aqui se necessário -->
</head>
<body class="bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100 min-h-screen flex flex-col transition-colors duration-300">
    <section class="main-content container mx-auto px-4 py-8">
        <!-- Cabeçalho -->
        <header class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 mb-6">
            <div class="flex justify-between items-center flex-wrap gap-y-2">
                <h1 class="text-xl font-semibold">🤖 Meli AI</h1>
                <div class="flex items-center space-x-3 sm:space-x-4">
                    <span class="text-sm text-gray-600 dark:text-gray-400 hidden sm:inline" title="Usuário Logado">Olá, <?php echo htmlspecialchars($saasUserEmail); ?></span>
                    <!-- Exibição do Status da Assinatura -->
                    <span class="<?php echo getSubscriptionStatusClass($subscriptionStatus); // Usa helper ?> text-xs !px-2 !py-0.5" title="Status da Assinatura">
                        <?php echo htmlspecialchars(ucfirst(strtolower($subscriptionStatus ?? 'N/A'))); ?>
                    </span>
                    <!-- Link para Gerenciar Assinatura -->
                    <a href="billing.php" class="text-sm font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 flex items-center gap-1" title="Gerenciar Assinatura">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 21.75Z" /></svg>
                        <span class="hidden sm:inline">Assinatura</span>
                    </a>
                    <!-- Link Admin (Condicional) -->
                    <?php if ($isCurrentUserSuperAdmin): ?>
                        <a href="super_admin.php" class="text-sm font-medium text-purple-600 hover:text-purple-800 dark:text-purple-400 dark:hover:text-purple-300 flex items-center gap-1" title="Painel Super Admin">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                            <span class="hidden sm:inline">Admin</span>
                        </a>
                    <?php endif; ?>
                    <!-- Botão Sair -->
                    <a href="logout.php" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 dark:focus:ring-offset-gray-800">
                       <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 mr-1 hidden sm:inline"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" /></svg>
                        Sair
                    </a>
                </div>
            </div>
        </header>

        <!-- Mensagem de Status Global -->
        <?php if ($dashboardMessage && $dashboardMessageClass): ?>
            <div id="dashboard-message" class="<?php echo htmlspecialchars($dashboardMessageClass); ?> px-4 py-3 rounded-md text-sm mb-6 flex justify-between items-center" role="alert">
                <span><?php echo htmlspecialchars($dashboardMessage['text']); ?></span>
                <button onclick="document.getElementById('dashboard-message').style.display='none';" class="ml-4 -mr-1 p-1 rounded-md focus:outline-none focus:ring-2 focus:ring-current hover:bg-opacity-20 hover:bg-current" aria-label="Fechar">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
        <?php endif; ?>

        <!-- Abas de Navegação -->
        <div class="mb-6">
            <div class="border-b border-gray-200 dark:border-gray-700">
                <nav id="dashboard-tabs" class="-mb-px flex space-x-6 overflow-x-auto" aria-label="Tabs">
                    <a href="#conexao" data-tab="conexao" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center space-x-1.5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 border-transparent">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" /></svg>
                        <span>Conexão</span>
                    </a>
                    <a href="#atividade" data-tab="atividade" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center space-x-1.5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 border-transparent">
                         <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        <span>Atividade</span>
                    </a>
                    <a href="#historico" data-tab="historico" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center space-x-1.5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 border-transparent">
                         <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" /></svg>
                        <span>Histórico</span>
                    </a>
                    <a href="#perfil" data-tab="perfil" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center space-x-1.5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 border-transparent">
                         <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527c.47-.336 1.06-.336 1.53 0l.772.55c.47.336.699.93.55 1.452l-.298 1.043c-.16.562-.16.948 0 1.51l.298 1.043c.149.521-.08.1.116-.55 1.452l-.772.55c-.47.336-1.06.336-1.53 0l-.737-.527c-.35-.25-.807-.272-1.205-.108-.396.165-.71.506-.78.93l-.149.894c-.09.542-.56.94-1.11.94h-1.093c-.55 0-1.02-.398-1.11-.94l-.149-.894c-.07-.424-.384-.764-.78-.93-.398-.164-.855-.142-1.205.108l-.737.527c-.47.336-1.06.336-1.53 0l-.772-.55c-.47-.336-.699-.93-.55-1.452l.298-1.043c.16-.562.16-.948 0-1.51l-.298-1.043c-.149-.521.08-1.116.55-1.452l.772-.55c.47-.336 1.06-.336 1.53 0l.737.527c.35.25.807.272 1.205.108.396-.165.71-.506.78-.93l.149-.894Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        <span>Perfil</span>
                    </a>
                </nav>
            </div>
        </div>

        <!-- Container Conteúdo das Abas -->
        <div class="space-y-6">
             <!-- Aba Conexão -->
             <div id="tab-conexao" class="tab-content hidden bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                 <h2 class="text-lg font-semibold mb-4">🔗 Conexão Mercado Livre</h2>
                 <?php if ($mlConnection): ?>
                     <div class="space-y-3 mb-4">
                         <div><span class="text-sm font-medium text-gray-600 dark:text-gray-400">Status:</span> <span class="ml-2 inline-flex items-center px-3 py-0.5 rounded-full text-sm font-medium bg-green-100 text-green-800 dark:bg-green-700 dark:text-green-100">✅ Conectada</span></div>
                         <div><span class="text-sm font-medium text-gray-600 dark:text-gray-400">ID Vendedor ML:</span> <span class="ml-2 text-sm font-semibold text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($mlConnection['ml_user_id']); ?></span></div>
                         <div><span class="text-sm font-medium text-gray-600 dark:text-gray-400">Automação:</span> <span class="ml-2 inline-flex items-center px-3 py-0.5 rounded-full text-sm font-medium <?php echo $mlConnection['is_active'] ? 'bg-green-100 text-green-800 dark:bg-green-700 dark:text-green-100' : 'bg-red-100 text-red-800 dark:bg-red-700 dark:text-red-100'; ?>"><?php echo $mlConnection['is_active'] ? 'Ativa' : 'Inativa'; ?></span></div>
                     </div>
                     <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Última atualização da conexão: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($mlConnection['updated_at']))); ?></p>
                     <div class="flex space-x-3">
                         <a href="oauth_start.php" class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 shadow-sm text-sm font-medium rounded-md text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                            🔄 Reconectar / Atualizar Permissões
                         </a>
                         <!-- Poderia adicionar botão para DESCONECTAR (desativar is_active e talvez limpar tokens) -->
                     </div>
                 <?php else: ?>
                     <div class="flex items-center space-x-2 mb-3">
                         <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Status:</span>
                         <span class="inline-flex items-center px-3 py-0.5 rounded-full text-sm font-medium bg-red-100 text-red-800 dark:bg-red-700 dark:text-red-100">❌ Não Conectada</span>
                     </div>
                     <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">
                         Para começar a usar o Meli AI, conecte sua conta do Mercado Livre.
                     </p>
                     <a href="oauth_start.php" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                        🔗 Conectar Conta Mercado Livre
                     </a>
                 <?php endif; ?>
             </div>

             <!-- Aba Atividade Recente -->
             <div id="tab-atividade" class="tab-content hidden bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                 <h2 class="text-lg font-semibold mb-4">⏱️ Atividade Recente (Últimos 30 Logs)</h2>
                 <?php $recentLogs = array_slice($logsParaHistorico, 0, 30); ?>
                 <?php if (empty($recentLogs)): ?>
                     <p class="text-center text-gray-500 dark:text-gray-400 py-10 text-sm">Nenhuma atividade recente registrada.</p>
                 <?php else: ?>
                     <div class="log-container custom-scrollbar border border-gray-200 dark:border-gray-700 rounded-lg max-h-[60vh] overflow-y-auto divide-y divide-gray-200 dark:divide-gray-700">
                         <?php foreach ($recentLogs as $log): ?>
                             <div class="log-entry px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors duration-150">
                                 <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mb-1"> <span class="text-sm font-medium text-gray-800 dark:text-gray-200">P: <?php echo htmlspecialchars($log['ml_question_id']); ?></span> <span class="text-sm text-gray-600 dark:text-gray-400">Item: <?php echo htmlspecialchars($log['item_id']); ?></span> <span class="<?php echo getStatusTagClasses($log['status']); ?>" title="Status"><?php echo htmlspecialchars(str_replace('_', ' ', $log['status'])); ?></span> </div>
                                 <div class="text-xs text-gray-500 dark:text-gray-400 flex flex-wrap gap-x-3 gap-y-1"> <?php if (!empty($log['sent_to_whatsapp_at'])): ?> <span title="Notif Wpp: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['sent_to_whatsapp_at']))); ?>">🔔 <?php echo htmlspecialchars(date('d/m H:i', strtotime($log['sent_to_whatsapp_at']))); ?></span> <?php endif; ?> <?php if (!empty($log['human_answered_at'])): ?> <span title="Resp Wpp: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['human_answered_at']))); ?>">✍️ <?php echo htmlspecialchars(date('d/m H:i', strtotime($log['human_answered_at']))); ?></span> <?php endif; ?> <?php if (!empty($log['ai_answered_at'])): ?> <span title="Resp IA: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['ai_answered_at']))); ?>">🤖 <?php echo htmlspecialchars(date('d/m H:i', strtotime($log['ai_answered_at']))); ?></span> <?php endif; ?> </div>
                                 <?php if (!empty($log['question_text'])): ?> <details class="mt-2"><summary class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline cursor-pointer inline-flex items-center group"> Ver Pergunta <svg class="arrow-down h-4 w-4 ml-1 transition-transform duration-200 group-focus:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg> </summary><pre class="mt-1 p-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded text-xs text-gray-700 dark:text-gray-300 max-h-40 overflow-y-auto whitespace-pre-wrap break-words"><code><?php echo htmlspecialchars($log['question_text']); ?></code></pre></details><?php endif; ?>
                                 <?php if (!empty($log['ia_response_text']) && in_array(strtoupper($log['status']), ['AI_ANSWERED', 'AI_FAILED', 'AI_PROCESSING', 'AI_TRIGGERED_BY_TEXT'])): ?> <details class="mt-2"><summary class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline cursor-pointer inline-flex items-center group"> Ver Resposta IA <?php if (strtoupper($log['status']) == 'AI_ANSWERED') echo '(Enviada)'; elseif (strtoupper($log['status']) == 'AI_FAILED') echo '(Inválida/Falhou)'; else echo '(Gerada/Tentada)'; ?> <svg class="arrow-down h-4 w-4 ml-1 transition-transform duration-200 group-focus:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg> </summary><pre class="mt-1 p-2 border rounded text-xs max-h-40 overflow-y-auto whitespace-pre-wrap break-words <?php echo strtoupper($log['status']) == 'AI_ANSWERED' ? 'bg-green-50 dark:bg-green-900/50 border-green-200 dark:border-green-700 text-green-800 dark:text-green-200' : 'bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-300'; ?>"><code><?php echo htmlspecialchars($log['ia_response_text']); ?></code></pre></details><?php endif; ?>
                                 <?php if (!empty($log['error_message'])): ?><p class="text-red-600 dark:text-red-400 text-xs mt-1"><strong>Erro:</strong> <?php echo htmlspecialchars($log['error_message']); ?></p><?php endif; ?>
                                 <p class="text-xs text-gray-400 dark:text-gray-500 mt-2 text-right">Última Atualização: <?php echo htmlspecialchars(date('d/m/Y H:i:s', strtotime($log['last_processed_at']))); ?></p>
                             </div>
                         <?php endforeach; ?>
                     </div>
                 <?php endif; ?>
             </div>

             <!-- Aba Histórico -->
             <div id="tab-historico" class="tab-content hidden bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                 <h2 class="text-lg font-semibold mb-4">📜 Histórico Completo (Últimos <?php echo $logLimit; ?> Logs)</h2>
                 <?php if (empty($logsParaHistorico)): ?>
                     <p class="text-center text-gray-500 dark:text-gray-400 py-10 text-sm">Nenhum histórico encontrado para este usuário.</p>
                 <?php else: ?>
                      <div class="log-container custom-scrollbar border border-gray-200 dark:border-gray-700 rounded-lg max-h-[70vh] overflow-y-auto divide-y divide-gray-200 dark:divide-gray-700">
                         <?php foreach ($logsParaHistorico as $log): ?>
                             <div class="log-entry px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors duration-150">
                                 <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mb-1"> <span class="text-sm font-medium text-gray-800 dark:text-gray-200">P: <?php echo htmlspecialchars($log['ml_question_id']); ?></span> <span class="text-sm text-gray-600 dark:text-gray-400">Item: <?php echo htmlspecialchars($log['item_id']); ?></span> <span class="<?php echo getStatusTagClasses($log['status']); ?>" title="Status"><?php echo htmlspecialchars(str_replace('_', ' ', $log['status'])); ?></span> </div>
                                 <div class="text-xs text-gray-500 dark:text-gray-400 flex flex-wrap gap-x-3 gap-y-1"> <?php if (!empty($log['sent_to_whatsapp_at'])): ?> <span title="Notif Wpp: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['sent_to_whatsapp_at']))); ?>">🔔 <?php echo htmlspecialchars(date('d/m H:i', strtotime($log['sent_to_whatsapp_at']))); ?></span> <?php endif; ?> <?php if (!empty($log['human_answered_at'])): ?> <span title="Resp Wpp: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['human_answered_at']))); ?>">✍️ <?php echo htmlspecialchars(date('d/m H:i', strtotime($log['human_answered_at']))); ?></span> <?php endif; ?> <?php if (!empty($log['ai_answered_at'])): ?> <span title="Resp IA: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['ai_answered_at']))); ?>">🤖 <?php echo htmlspecialchars(date('d/m H:i', strtotime($log['ai_answered_at']))); ?></span> <?php endif; ?> </div>
                                 <?php if (!empty($log['question_text'])): ?> <details class="mt-2"><summary class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline cursor-pointer inline-flex items-center group"> Ver Pergunta <svg class="arrow-down h-4 w-4 ml-1 transition-transform duration-200 group-focus:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg> </summary><pre class="mt-1 p-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded text-xs text-gray-700 dark:text-gray-300 max-h-40 overflow-y-auto whitespace-pre-wrap break-words"><code><?php echo htmlspecialchars($log['question_text']); ?></code></pre></details><?php endif; ?>
                                 <?php if (!empty($log['ia_response_text']) && in_array(strtoupper($log['status']), ['AI_ANSWERED', 'AI_FAILED', 'AI_PROCESSING', 'AI_TRIGGERED_BY_TEXT'])): ?> <details class="mt-2"><summary class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline cursor-pointer inline-flex items-center group"> Ver Resposta IA <?php if (strtoupper($log['status']) == 'AI_ANSWERED') echo '(Enviada)'; elseif (strtoupper($log['status']) == 'AI_FAILED') echo '(Inválida/Falhou)'; else echo '(Gerada/Tentada)'; ?> <svg class="arrow-down h-4 w-4 ml-1 transition-transform duration-200 group-focus:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg> </summary><pre class="mt-1 p-2 border rounded text-xs max-h-40 overflow-y-auto whitespace-pre-wrap break-words <?php echo strtoupper($log['status']) == 'AI_ANSWERED' ? 'bg-green-50 dark:bg-green-900/50 border-green-200 dark:border-green-700 text-green-800 dark:text-green-200' : 'bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-300'; ?>"><code><?php echo htmlspecialchars($log['ia_response_text']); ?></code></pre></details><?php endif; ?>
                                 <?php if (!empty($log['error_message'])): ?><p class="text-red-600 dark:text-red-400 text-xs mt-1"><strong>Erro:</strong> <?php echo htmlspecialchars($log['error_message']); ?></p><?php endif; ?>
                                 <p class="text-xs text-gray-400 dark:text-gray-500 mt-2 text-right">Última Atualização: <?php echo htmlspecialchars(date('d/m/Y H:i:s', strtotime($log['last_processed_at']))); ?></p>
                             </div>
                         <?php endforeach; ?>
                     </div>
                 <?php endif; ?>
             </div>

             <!-- Aba Perfil -->
            <div id="tab-perfil" class="tab-content hidden bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                 <h2 class="text-lg font-semibold mb-6">⚙️ Meu Perfil</h2>
                 <?php if ($saasUserProfile): ?>
                     <form action="update_profile.php" method="POST" class="space-y-6">
                         <div>
                             <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">📧 E-mail</label>
                             <input class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 cursor-not-allowed"
                                    type="email" id="email" value="<?php echo htmlspecialchars($saasUserProfile['email']); ?>" readonly disabled>
                             <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Seu e-mail de login (não pode ser alterado).</p>
                         </div>
                         <div>
                             <label for="whatsapp_number" class="block text-sm font-medium text-gray-700 dark:text-gray-300">📱 WhatsApp (Notificações)</label>
                             <input class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                    type="tel" id="whatsapp_number" name="whatsapp_number"
                                    value="<?php echo htmlspecialchars($currentDDDNumber); ?>" placeholder="Ex: 11987654321" pattern="\d{10,11}" title="DDD + Número (10 ou 11 dígitos)">
                             <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">DDD + Número (sem espaços/traços). Usado para receber notificações.</p>
                         </div>
                         <div class="pt-6 border-t border-gray-200 dark:border-gray-700">
                             <h3 class="text-base font-medium text-gray-900 dark:text-white">🔑 Alterar Senha (Opcional)</h3>
                             <div class="mt-4 space-y-4">
                                 <div><label for="current_password" class="sr-only">Senha Atual</label><input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white" type="password" id="current_password" name="current_password" placeholder="Senha Atual" autocomplete="current-password"></div>
                                 <div><label for="new_password" class="sr-only">Nova Senha</label><input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white" type="password" id="new_password" name="new_password" placeholder="Nova Senha (mín. 8 caracteres)" minlength="8" autocomplete="new-password"></div>
                                 <div><label for="confirm_password" class="sr-only">Confirmar Nova Senha</label><input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white" type="password" id="confirm_password" name="confirm_password" placeholder="Confirmar Nova Senha" autocomplete="new-password"></div>
                             </div>
                             <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Preencha os três campos apenas se desejar alterar sua senha.</p>
                         </div>
                         <div class="flex justify-end pt-4">
                             <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                                 💾 Salvar Alterações
                             </button>
                         </div>
                     </form>
                 <?php else: ?>
                     <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4" role="alert"><p class="font-bold">Erro</p><p>Não foi possível carregar dados do perfil.</p></div>
                 <?php endif; ?>
             </div> <!-- Fim #tab-perfil -->
        </div> <!-- Fim container conteúdo abas -->
    </section>

     <footer class="py-6 text-center">
         <p class="text-sm text-gray-500 dark:text-gray-400">
             <strong>Meli AI</strong> © <?php echo date('Y'); ?>
         </p>
     </footer>

    <!-- Script Javascript para controle das Abas -->
    <script>
         document.addEventListener('DOMContentLoaded', () => {
             const tabs = document.querySelectorAll('#dashboard-tabs a[data-tab]');
             const tabContents = document.querySelectorAll('.tab-content'); // Seleciona todas as divs de conteúdo
             const activeTabClasses = ['text-blue-600', 'dark:text-blue-400', 'border-blue-500'];
             const inactiveTabClasses = ['text-gray-500', 'dark:text-gray-400', 'hover:text-gray-700', 'dark:hover:text-gray-200', 'hover:border-gray-300', 'dark:hover:border-gray-500', 'border-transparent'];

             function switchTab(targetTabId) {
                 // Atualiza estilos das abas e aria-selected
                 tabs.forEach(tab => {
                     const isTarget = tab.getAttribute('data-tab') === targetTabId;
                     tab.classList.toggle(...activeTabClasses, isTarget);
                     tab.classList.toggle(...inactiveTabClasses, !isTarget);
                     tab.setAttribute('aria-selected', isTarget ? 'true' : 'false');
                 });

                 // Mostra/Esconde conteúdo das abas
                 tabContents.forEach(content => {
                     // Verifica se o ID do conteúdo corresponde ao ID da aba clicada
                     if(content.id === `tab-${targetTabId}`) {
                         content.classList.remove('hidden');
                     } else {
                         content.classList.add('hidden');
                     }
                 });

                 // Atualiza URL hash (opcional, mas bom para navegação)
                 // Usando setTimeout 0 para garantir que a atualização da UI ocorra antes do pushState
                 if (history.pushState) {
                     setTimeout(() => history.pushState(null, null, '#' + targetTabId), 0);
                 } else {
                     // Fallback para navegadores mais antigos
                     window.location.hash = '#' + targetTabId;
                 }
             }

             // Adiciona event listener para cada aba
             tabs.forEach(tab => {
                 tab.addEventListener('click', (event) => {
                     event.preventDefault(); // Previne o comportamento padrão do link
                     const tabId = tab.getAttribute('data-tab');
                     if (tabId) {
                         switchTab(tabId);
                     }
                 });
             });

             // Define a aba ativa inicial (baseado no hash da URL ou padrão 'conexao')
             let activeTabId = 'conexao'; // Aba padrão
             if (window.location.hash) {
                 const hash = window.location.hash.substring(1); // Remove o '#'
                 // Verifica se existe uma aba correspondente ao hash
                 const requestedTab = document.querySelector(`#dashboard-tabs a[data-tab="${hash}"]`);
                 if (requestedTab) {
                     activeTabId = hash;
                 }
             }
             // Ativa a aba inicial
             switchTab(activeTabId);
         });
    </script>
</body>
</html>


<?php
/**
 * Arquivo: db.php (Localizado na raiz /meliai/)
 * Versão: v1.4 - Usa Defuse e carrega chave de $secrets (via global).
 * Descrição: Funções para conexão com o banco de dados e criptografia segura.
 */

// Inclui o config.php que está NO MESMO DIRETÓRIO que este arquivo (db.php).
// Isso garante que $secrets e as constantes DB_* estejam disponíveis.
require_once __DIR__ . '/config.php';

// Usa as classes da biblioteca Defuse (o autoload já foi feito pelo config.php)
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Defuse\Crypto\Exception as DefuseException; // Alias para exceções Defuse

/**
 * Obtém uma conexão PDO com o banco de dados MySQL/MariaDB.
 * Reutiliza a conexão existente na mesma requisição para eficiência.
 * Usa as constantes DB_* definidas em config.php.
 *
 * @return PDO Objeto da conexão PDO configurado.
 * @throws PDOException Se a conexão com o banco de dados falhar.
 */
function getDbConnection(): PDO
{
    // Variável estática para manter a conexão PDO durante a execução do script
    static $pdo = null;

    // Se a conexão ainda não foi estabelecida nesta requisição
    if ($pdo === null) {
        // Verifica se as constantes do DB foram definidas e se a senha não está vazia
        // (Já verificado em config.php, mas uma checagem extra aqui é segura)
        if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS') || DB_PASS === '') {
            $errorMessage = "FATAL (db.php): Constantes DB_* não definidas ou DB_PASS está vazia. Verifique config.php e secrets.php.";
            error_log($errorMessage);
            // Lança exceção para interromper o fluxo se a configuração estiver incorreta
            throw new \PDOException("Configuração de banco de dados crítica incompleta ou inválida (DB01).");
        }

        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lança exceções em erros SQL
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Retorna arrays associativos por padrão
            PDO::ATTR_EMULATE_PREPARES   => false,                  // Usa prepared statements nativos
        ];

        try {
            // Tenta criar a instância da conexão PDO
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (\PDOException $e) {
            // Loga o erro sem expor detalhes sensíveis como a senha (se houver no DSN)
            $logMessage = "FATAL DB Connection Error: " . $e->getCode() . " - Falha ao conectar como usuário '" . DB_USER . "' ao host '" . DB_HOST . "'. Verifique credenciais, host, nome do banco e permissões.";
            error_log($logMessage . " | PDO Message: " . $e->getMessage()); // Loga a msg PDO tbm
            // Tenta usar logMessage se disponível
            if (function_exists('logMessage')) logMessage($logMessage);
            // Lança uma exceção genérica para o código chamador, escondendo detalhes internos
            throw new \PDOException("Falha crítica na conexão com o banco de dados (DB02).", (int)$e->getCode());
        }
    }
    // Retorna a conexão PDO (nova ou existente)
    return $pdo;
}


/**
 * Carrega a chave de criptografia de forma segura a partir do array global $secrets.
 * Este array deve ter sido carregado pelo config.php antes desta função ser chamada.
 * A chave carregada é armazenada estaticamente para eficiência.
 *
 * @global array $secrets Array associativo contendo os segredos, incluindo 'APP_ENCRYPTION_KEY'.
 * @return Key Objeto Key da biblioteca Defuse.
 * @throws Exception Se a chave não estiver definida, for inválida ou ocorrer erro ao carregar.
 */
function loadEncryptionKey(): Key
{
    // Acessa a variável global $secrets carregada pelo config.php
    // Cuidado: Usar 'global' não é a prática mais limpa, mas é a mais direta aqui.
    // Uma alternativa seria passar $secrets['APP_ENCRYPTION_KEY'] como argumento.
    global $secrets;

    // Cacheia a chave carregada para evitar recarregá-la múltiplas vezes na mesma requisição
    static $loadedKey = null;

    if ($loadedKey === null) {
        // Lê a chave ASCII do array $secrets
        $keyAscii = $secrets['APP_ENCRYPTION_KEY'] ?? null;

        if (empty($keyAscii)) {
            $errorMessage = "ERRO CRÍTICO DE SEGURANÇA (db.php): Chave 'APP_ENCRYPTION_KEY' não definida ou vazia no arquivo de segredos!";
            error_log($errorMessage); // Loga sempre
            if (function_exists('logMessage')) logMessage($errorMessage);
            throw new Exception('Chave de criptografia essencial não configurada (SEC10).');
        }

        try {
            // Tenta carregar a chave a partir da string ASCII segura
            $loadedKey = Key::loadFromAsciiSafeString($keyAscii);
        } catch (DefuseException\BadFormatException $e) {
            $errorMessage = "ERRO CRÍTICO DE SEGURANÇA (db.php): Formato inválido da APP_ENCRYPTION_KEY: " . $e->getMessage();
            error_log($errorMessage);
            if (function_exists('logMessage')) logMessage($errorMessage);
            throw new Exception('Chave de criptografia com formato inválido (SEC11).');
        } catch (\Throwable $e) { // Captura outros erros Defuse ou gerais
            $errorMessage = "ERRO CRÍTICO DE SEGURANÇA (db.php): Erro ao carregar APP_ENCRYPTION_KEY: " . $e->getMessage();
            error_log($errorMessage);
            if (function_exists('logMessage')) logMessage($errorMessage);
            throw new Exception('Erro geral ao carregar chave de criptografia (SEC12).');
        }
    }
    return $loadedKey;
}

/**
 * Criptografa dados de forma segura usando defuse/php-encryption.
 *
 * @param string $data O dado em texto plano a ser criptografado.
 * @return string A string criptografada (segura para armazenamento).
 * @throws Exception Se a criptografia falhar (ambiente inseguro, erro ao carregar chave).
 */
function encryptData(string $data): string
{
    try {
        $key = loadEncryptionKey(); // Carrega a chave segura
        return Crypto::encrypt($data, $key);
    } catch (DefuseException\EnvironmentIsBrokenException $e) {
        $errorMessage = "ERRO CRÍTICO Criptografia (db.php): Ambiente PHP inseguro detectado. " . $e->getMessage();
        error_log($errorMessage);
        if (function_exists('logMessage')) logMessage($errorMessage);
        throw new Exception("Encryption failed due to insecure environment (SEC20).");
    } catch (\Throwable $e) { // Captura erros ao carregar chave também
        $errorMessage = "ERRO Criptografia (db.php): " . $e->getMessage();
        error_log($errorMessage);
        if (function_exists('logMessage')) logMessage($errorMessage);
        // Lança exceção para indicar a falha
        throw new Exception("Encryption failed (SEC21): " . $e->getMessage());
    }
}

/**
 * Descriptografa dados usando defuse/php-encryption.
 *
 * @param string $encryptedData A string criptografada a ser descriptografada.
 * @return string O dado original em texto plano.
 * @throws Exception Se a descriptografia falhar (chave errada, dado corrompido, ambiente inseguro, erro ao carregar chave).
 */
function decryptData(string $encryptedData): string
{
    try {
        $key = loadEncryptionKey(); // Carrega a chave segura
        return Crypto::decrypt($encryptedData, $key);
    } catch (DefuseException\WrongKeyOrModifiedCiphertextException $e) {
        // Erro comum: Chave errada ou dado corrompido/modificado. Não logue $encryptedData completo.
        $errorMessage = "ERRO Descriptografia (db.php): Chave incorreta ou dado modificado. Input prefix: " . substr($encryptedData, 0, 20) . "...";
        error_log($errorMessage);
        if (function_exists('logMessage')) logMessage($errorMessage);
        throw new Exception("Decryption failed: Invalid key or data integrity compromised (SEC30).");
    } catch (DefuseException\EnvironmentIsBrokenException $e) {
        $errorMessage = "ERRO CRÍTICO Descriptografia (db.php): Ambiente PHP inseguro detectado. " . $e->getMessage();
        error_log($errorMessage);
        if (function_exists('logMessage')) logMessage($errorMessage);
        throw new Exception("Decryption failed due to insecure environment (SEC31).");
    } catch (\Throwable $e) { // Captura erros ao carregar chave e outros erros Defuse
        $errorMessage = "ERRO Descriptografia (db.php): " . $e->getMessage();
        error_log($errorMessage);
        if (function_exists('logMessage')) logMessage($errorMessage);
        throw new Exception("Decryption failed (SEC32): " . $e->getMessage());
    }
}



<?php
/**
 * Arquivo: evolution_webhook_receiver.php
 * Versão: v15.4 - Chama triggerAiForQuestion diretamente para TRIGGER_AI (Confirmado)
 *
 * Descrição:
 * Endpoint de webhook para a API Evolution V2. Processa respostas do usuário via WhatsApp.
 * - Identifica a pergunta original pela mensagem citada (reply).
 * - Usa `interpretUserIntent` (Gemini) para classificar a intenção da resposta.
 * - Se a intenção for responder manualmente, envia a resposta para o Mercado Livre.
 * - Se a intenção for usar IA (`TRIGGER_AI`), chama `triggerAiForQuestion` imediatamente.
 * - Se o formato for inválido, envia feedback ao usuário.
 * - Envia notificações de sucesso/erro para o JID CADASTRADO do usuário SaaS.
 *
 * !! ALERTA DE SEGURANÇA: Validar a origem do webhook (ex: por IP ou token,
 *    se a Evolution API permitir) é altamente recomendado em produção para
 *    evitar processamento de requisições maliciosas. !!
 */

// Includes Essenciais Refatorados
require_once __DIR__ . '/config.php';             // Constantes e Configurações (DB, APIs, etc.)
require_once __DIR__ . '/db.php';                 // getDbConnection() e Funções de Criptografia (Placeholders)
require_once __DIR__ . '/includes/log_helper.php';   // logMessage()
require_once __DIR__ . '/includes/db_interaction.php'; // getQuestionLogStatus(), upsertQuestionLog()
require_once __DIR__ . '/includes/gemini_api.php';   // interpretUserIntent()
require_once __DIR__ . '/includes/ml_api.php';       // postMercadoLibreAnswer(), refreshMercadoLibreToken()
require_once __DIR__ . '/includes/evolution_api.php'; // sendWhatsAppNotification()
require_once __DIR__ . '/includes/core_logic.php';   // triggerAiForQuestion()

logMessage("[Webhook Receiver EVOLUTION v15.4 ENTRY POINT] Script acessado.");

// --- Obter e Validar Payload JSON da Requisição ---
$payload = file_get_contents('php://input');
$data = $payload ? json_decode($payload, true) : null;

// Log do Payload Bruto para Depuração (opcional, cuidado com dados sensíveis)
// logMessage("[Webhook Receiver v15.4 DEBUG] Raw Payload: " . $payload);

// Verifica se o JSON é válido
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    logMessage("[Webhook Receiver v15.4] ERRO JSON Decode: " . json_last_error_msg());
    http_response_code(400); // Bad Request
    exit;
}
if (!$data) {
    logMessage("[Webhook Receiver v15.4] ERRO: Payload inválido ou vazio recebido.");
    http_response_code(400); // Bad Request
    exit;
}

// --- Extração de Dados Principais do Webhook ---
// Tenta extrair dados comuns de diferentes estruturas de webhook da Evolution API
$eventType = $data['event'] ?? ($data['type'] ?? null); // Tipo de evento (ex: 'messages.upsert')
$messageData = $data['data'] ?? ($data['message'] ?? null); // Dados da mensagem
$sender = $data['sender'] ?? ($messageData['key']['remoteJid'] ?? null); // Quem enviou
// Verifica se a mensagem foi enviada pela própria instância da API
$isFromMe = isset($messageData['key']['fromMe']) ? $messageData['key']['fromMe'] : false;

// --- Filtros Iniciais ---
// Ignora mensagens enviadas pela própria API (evita loops)
if ($isFromMe === true) {
    logMessage("[Webhook Receiver v1.5.4] Ignorado (isFromMe=true). Sender: $sender");
    http_response_code(200); // OK, apenas ignoramos
    exit;
}

// Processa apenas eventos de mensagem relevantes
$allowedEvents = ['messages.upsert', 'message']; // Adapte se a Evolution usar outros nomes
if (!in_array($eventType, $allowedEvents)) {
    logMessage("[Webhook Receiver v15.4] Ignorado (evento não é de mensagem): '$eventType'.");
    http_response_code(200); // OK, apenas ignoramos
    exit;
}

// Verifica se a estrutura básica da mensagem está presente
if (!$messageData || !isset($messageData['key']) || empty($sender)) {
    logMessage("[Webhook Receiver v15.4] ERRO: Estrutura da mensagem inválida ou remetente ausente.");
    http_response_code(400); // Bad Request
    exit;
}

// --- Processar Apenas Mensagens de Texto que são Respostas (Replies) ---
$messageContent = $messageData['message'] ?? null;
$userReplyText = null; // Texto da resposta do usuário

// Tenta extrair o texto da mensagem (pode estar em 'conversation' ou 'extendedTextMessage.text')
if (isset($messageContent['conversation'])) {
    $userReplyText = trim($messageContent['conversation']);
} elseif (isset($messageContent['extendedTextMessage']['text'])) {
    $userReplyText = trim($messageContent['extendedTextMessage']['text']);
}

// Tenta extrair informações da mensagem citada (contexto)
// A estrutura pode variar um pouco dependendo da versão da API ou do tipo de mensagem citada
$contextInfo = $messageData['contextInfo'] ?? ($messageContent['extendedTextMessage']['contextInfo'] ?? null);
// O ID da mensagem original (stanzaId) é crucial para encontrar a pergunta no nosso log
$quotedMessageId = $contextInfo['stanzaId'] ?? null;

logMessage("[Webhook Receiver v15.4 DEBUG] Sender: '$sender', UserReplyText: '" . ($userReplyText ?: 'VAZIO/NÃO_TEXTO') . "', QuotedMsgID: " . ($quotedMessageId ?: 'NULL'));

// --- Lógica Principal: Processar Resposta se for um Reply de Texto Válido ---
if (!empty($userReplyText) && $quotedMessageId) {

    logMessage("[Webhook Receiver v15.4] Processando REPLY de '$sender' para Msg Citada ID: '$quotedMessageId'");
    $pdo = null; // Inicializa conexão PDO
    $feedbackTargetJid = null; // JID para enviar feedback (deve ser o JID cadastrado)

    try {
        $pdo = getDbConnection();
        $now = new DateTimeImmutable(); // Timestamp atual

        // 1. Buscar Log da Pergunta pelo ID da MENSAGEM CITADA (whatsapp_notification_message_id)
        logMessage("  [DB Lookup] Buscando log para WA_MSG_ID: '$quotedMessageId'");
        $stmtLog = $pdo->prepare(
            "SELECT ml_question_id, ml_user_id, saas_user_id, item_id, status, question_text
             FROM question_processing_log
             WHERE whatsapp_notification_message_id = :wa_msg_id
             LIMIT 1"
        );
        $stmtLog->execute([':wa_msg_id' => $quotedMessageId]);
        $logEntry = $stmtLog->fetch();

        // Se não encontrou o log correspondente à mensagem citada
        if (!$logEntry) {
            logMessage("  [DB Lookup] Reply - Log NÃO encontrado para WA_MSG_ID: '$quotedMessageId'. Mensagem de '$sender' ignorada.");
            // Considerar enviar uma mensagem de erro para o remetente? ("Não sei a qual pergunta você se refere.")
            // Por ora, apenas ignora.
            http_response_code(200); // OK, pois processamos a lógica de ignorar
            exit;
        }

        // Extrai dados do log encontrado
        $currentStatus = $logEntry['status'];
        $mlQuestionId = (int)$logEntry['ml_question_id'];
        $mlUserId = (int)$logEntry['ml_user_id'];
        $itemId = $logEntry['item_id'];
        $saasUserId = (int)$logEntry['saas_user_id']; // ID do usuário SaaS dono da pergunta
        $originalQuestionText = $logEntry['question_text'];
        logMessage("  [DB Lookup] Reply para QID $mlQuestionId (SaaS $saasUserId) encontrada. Status log: '$currentStatus'");

        // 2. Buscar JID CADASTRADO do usuário SaaS para enviar feedback
        // Importante: O feedback NÃO deve ser enviado para o $sender (que pode ser qualquer número),
        // mas sim para o número que o usuário cadastrou no perfil dele.
        if ($saasUserId > 0) {
            $stmtSaasJid = $pdo->prepare("SELECT whatsapp_jid FROM saas_users WHERE id = :id LIMIT 1");
            $stmtSaasJid->execute([':id' => $saasUserId]);
            $saasUserData = $stmtSaasJid->fetch();
            if ($saasUserData && !empty($saasUserData['whatsapp_jid'])) {
                $feedbackTargetJid = $saasUserData['whatsapp_jid'];
                logMessage("  [DB Lookup] JID CADASTRADO para feedback encontrado: $feedbackTargetJid");
            } else {
                logMessage("  [DB Lookup] AVISO: Usuário SaaS ID $saasUserId não possui whatsapp_jid cadastrado no banco. Feedback não será enviado.");
            }
        } else {
            logMessage("  [DB Lookup] AVISO: SaaS User ID inválido ($saasUserId) encontrado no log da pergunta $mlQuestionId.");
        }

        // 3. Verifica Status Atual do Log
        // Só processa se a pergunta estiver aguardando resposta humana
        if ($currentStatus !== 'AWAITING_TEXT_REPLY') {
            logMessage("  [QID $mlQuestionId] Reply recebido, mas status do log é '$currentStatus' (não é AWAITING_TEXT_REPLY). Ignorando.");
            // Envia feedback informando que a pergunta já foi tratada (ou está em outro estado)
            if ($feedbackTargetJid) { sendWhatsAppNotification($feedbackTargetJid, "ℹ️ A pergunta ($mlQuestionId) não está mais aguardando sua resposta (Status atual: $currentStatus). Sua mensagem foi ignorada."); }
            http_response_code(200); // OK, processamos a lógica de ignorar
            exit;
        }
        // Validação extra: verifica se temos o texto da pergunta original (necessário para a IA)
        if (empty(trim($originalQuestionText))) {
            logMessage("  [QID $mlQuestionId] Reply - ERRO CRÍTICO: Texto da pergunta original está vazio no log do banco de dados.");
            if ($feedbackTargetJid) { sendWhatsAppNotification($feedbackTargetJid, "⚠️ Erro interno ao processar sua resposta para a pergunta $mlQuestionId (dados da pergunta ausentes). Por favor, tente responder diretamente no Mercado Livre ou contate o suporte."); }
            upsertQuestionLog($mlQuestionId, $mlUserId, $itemId, 'ERROR', null, null, null, 'Texto pergunta original vazio no log (Webhook Evolution)', $saasUserId);
            http_response_code(500); // Erro interno
            exit;
        }

        // 4. Interpreta Intenção do Usuário com IA (Gemini)
        logMessage("  [QID $mlQuestionId] Chamando interpretador de intenção para texto: '$userReplyText'");
        $intentResult = interpretUserIntent($userReplyText, $originalQuestionText); // Chama a função em gemini_api.php
        $replyAction = $intentResult['intent'];          // MANUAL_ANSWER, TRIGGER_AI, INVALID_FORMAT
        $manualAnswerText = $intentResult['cleaned_text']; // Texto limpo se for MANUAL_ANSWER, null caso contrário
        logMessage("  [QID $mlQuestionId] Intenção Interpretada: $replyAction");

        // --- Bloco de Ação Baseado na Intenção ---
        try {
            // 5. Obter/Refrescar Token ML (Necessário apenas para MANUAL_ANSWER)
            $currentAccessToken = null;
            if ($replyAction === 'MANUAL_ANSWER') {
                logMessage("    [Action MANUAL QID $mlQuestionId] Validando e preparando token ML...");
                $stmtMLUser = $pdo->prepare("SELECT id, access_token, refresh_token, token_expires_at FROM mercadolibre_users WHERE ml_user_id = :ml_uid AND saas_user_id = :saas_uid AND is_active = TRUE LIMIT 1");
                $stmtMLUser->execute([':ml_uid' => $mlUserId, ':saas_uid' => $saasUserId]);
                $mlUserConn = $stmtMLUser->fetch();

                if (!$mlUserConn) {
                    logMessage("    [Action MANUAL QID $mlQuestionId] ERRO FATAL: Conexão ML para $mlUserId (SaaS $saasUserId) não encontrada ou inativa no DB.");
                    upsertQuestionLog($mlQuestionId, $mlUserId, $itemId, 'ERROR', null, null, null, 'Conn ML Inativa (Webhook Evolution)', $saasUserId);
                    if ($feedbackTargetJid) { sendWhatsAppNotification($feedbackTargetJid, "⚠️ Erro ao tentar responder pergunta $mlQuestionId: Sua conexão com o Mercado Livre está inativa. Reconecte no painel."); }
                    http_response_code(500); exit;
                }

                try {
                    // !! ALERTA SEGURANÇA !! Usando decryptData placeholder
                    $currentAccessToken = decryptData($mlUserConn['access_token']);
                    $refreshToken = decryptData($mlUserConn['refresh_token']);
                } catch (Exception $e){
                    logMessage("    [Action MANUAL QID $mlQuestionId] ERRO CRÍTICO decrypt tokens: ".$e->getMessage());
                    upsertQuestionLog($mlQuestionId, $mlUserId, $itemId, 'ERROR', null, null, null, 'Falha Decrypt Token (Webhook Evolution)', $saasUserId);
                    if ($feedbackTargetJid) { sendWhatsAppNotification($feedbackTargetJid, "⚠️ Erro interno de segurança ao processar sua resposta para $mlQuestionId."); }
                    http_response_code(500); exit;
                }

                // Verifica se token precisa ser renovado
                $tokenExpiresAt = new DateTimeImmutable($mlUserConn['token_expires_at']);
                 if ($now >= $tokenExpiresAt->modify("-10 minutes")) { // 10 min de margem
                     logMessage("    [Action MANUAL QID $mlQuestionId] Token ML precisa ser renovado...");
                     $refreshResult = refreshMercadoLibreToken($refreshToken); // Chama a função de refresh
                     if($refreshResult['httpCode'] == 200 && isset($refreshResult['response']['access_token'])){
                         $newData = $refreshResult['response'];
                         $currentAccessToken = $newData['access_token']; // Novo access token
                         $newRefreshToken = $newData['refresh_token'] ?? $refreshToken; // Usa novo refresh token se vier, senão mantém o antigo
                         $newExpAt = $now->modify("+" . ($newData['expires_in'] ?? 21600) . " seconds")->format('Y-m-d H:i:s'); // Calcula nova expiração

                         try {
                             // !! ALERTA SEGURANÇA !! Usando encryptData placeholder
                             $encAT = encryptData($currentAccessToken);
                             $encRT = encryptData($newRefreshToken);
                         } catch(Exception $e) {
                              logMessage("    [Action MANUAL QID $mlQuestionId] ERRO CRÍTICO encrypt pós-refresh: ".$e->getMessage());
                              // Considerar continuar com o token antigo ou falhar? Por segurança, falha.
                              http_response_code(500); exit;
                         }
                         // Atualiza tokens e expiração no banco de dados
                         $upSql = "UPDATE mercadolibre_users SET access_token = :at, refresh_token = :rt, token_expires_at = :exp, updated_at = NOW() WHERE id = :id";
                         $upStmt = $pdo->prepare($upSql);
                         $upStmt->execute([':at'=>$encAT, ':rt'=>$encRT, ':exp'=>$newExpAt, ':id'=>$mlUserConn['id']]);
                         logMessage("    [Action MANUAL QID $mlQuestionId] Refresh do token ML realizado com sucesso.");
                     } else {
                         // Falha ao renovar o token
                         logMessage("    [Action MANUAL QID $mlQuestionId] ERRO FATAL ao renovar token ML. HTTP: {$refreshResult['httpCode']}. Response: " . json_encode($refreshResult['response']));
                         upsertQuestionLog($mlQuestionId, $mlUserId, $itemId, 'ERROR', null, null, null, 'Falha Refresh Token ML (Webhook Evolution)', $saasUserId);
                         if ($feedbackTargetJid) { sendWhatsAppNotification($feedbackTargetJid, "⚠️ Erro ao conectar com Mercado Livre para responder $mlQuestionId. Tente reconectar no painel."); }
                         http_response_code(500); exit;
                     }
                 } else {
                     logMessage("    [Action MANUAL QID $mlQuestionId] Token ML ainda válido.");
                 }
                 logMessage("    [Action MANUAL QID $mlQuestionId] Token ML pronto para uso.");
            } // Fim if ($replyAction === 'MANUAL_ANSWER')

            // 6. Executar Ação Baseada na Intenção
            if ($replyAction === 'MANUAL_ANSWER') {
                // Verifica se o texto extraído não é vazio
                if (empty($manualAnswerText)) {
                    logMessage("    [Action MANUAL QID $mlQuestionId] ERRO: Intenção manual, mas texto extraído vazio após limpeza. Resposta original: '$userReplyText'");
                    if ($feedbackTargetJid) { sendWhatsAppNotification($feedbackTargetJid, "⚠️ Não identifiquei um texto válido na sua resposta para a pergunta $mlQuestionId. Tente novamente."); }
                    // Mantém status como AWAITING_TEXT_REPLY? Ou marca como erro? Por ora, mantém.
                    http_response_code(400); // Bad request (resposta vazia)
                    exit;
                }

                // Tenta postar a resposta no Mercado Livre
                logMessage("    [Action MANUAL QID $mlQuestionId] Postando resposta manual no ML: '$manualAnswerText'");
                $answerResult = postMercadoLibreAnswer($mlQuestionId, $manualAnswerText, $currentAccessToken);

                // Processa resultado da postagem no ML
                if ($answerResult['httpCode'] == 200 || $answerResult['httpCode'] == 201) {
                    // Sucesso!
                    logMessage("    [Action MANUAL QID $mlQuestionId] Resposta manual postada no ML com sucesso.");
                    $humanAnsweredTimestamp = $now->format('Y-m-d H:i:s');
                    // Atualiza log local para HUMAN_ANSWERED_VIA_WHATSAPP
                    upsertQuestionLog($mlQuestionId, $mlUserId, $itemId, 'HUMAN_ANSWERED_VIA_WHATSAPP', null, null, null, null, $saasUserId, null, $quotedMessageId, $humanAnsweredTimestamp);
                    // Envia feedback de sucesso para o JID cadastrado
                    if ($feedbackTargetJid) {
                        logMessage("    [Action MANUAL QID $mlQuestionId] Enviando feedback de sucesso para JID CADASTRADO: $feedbackTargetJid");
                        sendWhatsAppNotification($feedbackTargetJid, "✅ Respondido no Mercado Livre!\n\nSua resposta para a pergunta ($mlQuestionId) foi enviada com sucesso.");
                    }
                    http_response_code(200); // OK
                    exit;
                } else {
                    // Falha ao postar no ML
                    logMessage("    [Action MANUAL QID $mlQuestionId] ERRO ao postar resposta manual no ML. HTTP Code: {$answerResult['httpCode']}. Response: " . json_encode($answerResult['response']));
                    // Atualiza log local para ERROR
                    upsertQuestionLog($mlQuestionId, $mlUserId, $itemId, 'ERROR', null, null, null, "Falha Post ML (Webhook Evolution): HTTP {$answerResult['httpCode']}", $saasUserId, null, $quotedMessageId);
                    // Envia feedback de erro para o JID cadastrado
                    if ($feedbackTargetJid) { sendWhatsAppNotification($feedbackTargetJid, "⚠️ Falha ao enviar sua resposta para a pergunta $mlQuestionId no Mercado Livre (Erro: {$answerResult['httpCode']}). Tente responder diretamente no ML."); }
                    http_response_code(500); // Erro interno do servidor (falha na comunicação com ML)
                    exit;
                }
            }
            elseif ($replyAction === 'TRIGGER_AI') {
                // Usuário pediu para a IA responder
                logMessage("    [Action TRIGGER_AI QID $mlQuestionId] Acionando IA (intenção '2' detectada)...");
                // **** Chama a função core_logic imediatamente ****
                $aiSuccess = triggerAiForQuestion($mlQuestionId); // Esta função já lida com logs e notificações

                if ($aiSuccess) {
                    logMessage("    [Action TRIGGER_AI QID $mlQuestionId] Função triggerAiForQuestion retornou SUCESSO.");
                    // A notificação de sucesso/falha já foi enviada de dentro de triggerAiForQuestion
                    http_response_code(200); // OK
                    exit;
                } else {
                    logMessage("    [Action TRIGGER_AI QID $mlQuestionId] Função triggerAiForQuestion retornou FALHA (ver logs anteriores).");
                    // A notificação de falha também já foi enviada de dentro de triggerAiForQuestion (ou deveria)
                    http_response_code(500); // Indica que houve uma falha no processamento da IA
                    exit;
                 }
                 // **** FIM DA LÓGICA TRIGGER_AI ****
            }
            elseif ($replyAction === 'INVALID_FORMAT') {
                 // IA classificou a resposta como inválida/não processável
                 logMessage("    [Action INVALID QID $mlQuestionId] Intenção classificada como inválida pela IA: '$userReplyText'");
                 // Envia feedback de formato inválido para o JID cadastrado
                 if ($feedbackTargetJid) {
                     logMessage("    [Action INVALID QID $mlQuestionId] Enviando feedback de formato inválido para JID CADASTRADO: $feedbackTargetJid");
                     sendWhatsAppNotification($feedbackTargetJid, "⚠️ Não entendi sua resposta para a pergunta $mlQuestionId.\n\n➡️ Para responder manualmente, apenas digite o texto da sua resposta.\n➡️ Para usar a IA, responda apenas com o número `2`.");
                 }
                 // Não altera o status no DB, mantém como AWAITING_TEXT_REPLY
                 http_response_code(200); // OK, processamos a lógica de formato inválido
                 exit;
            } else {
                // Situação inesperada (não deveria acontecer se interpretUserIntent funciona)
                logMessage("  [QID $mlQuestionId] ERRO INTERNO: Intenção desconhecida retornada por interpretUserIntent: '$replyAction'");
                http_response_code(500); // Erro interno
                exit;
            }

        } catch (\Exception $e) {
            // Captura erros durante a obtenção/refresh do token ou execução da ação
            logMessage("  [Action QID $mlQuestionId] ERRO CRÍTICO durante ação '$replyAction': " . $e->getMessage());
            // Tenta atualizar o log como erro, mesmo dentro do catch
            @upsertQuestionLog($mlQuestionId, $mlUserId, $itemId, 'ERROR', null, null, null, 'Exceção Ação Webhook: '.substr($e->getMessage(),0,150), $saasUserId);
            // Tenta enviar feedback de erro genérico
            if ($feedbackTargetJid) { @sendWhatsAppNotification($feedbackTargetJid, "⚠️ Erro interno ao processar sua resposta para a pergunta $mlQuestionId. Contate o suporte se persistir."); }
            http_response_code(500); // Erro interno
            exit;
        }

    } catch (\Throwable $e) { // Captura erros na busca inicial do log ou conexão DB
        logMessage("[Webhook Receiver v15.4 Lookup/Init] ERRO CRÍTICO processando WA Msg ID '$quotedMessageId': " . $e->getMessage());
        // Não temos $feedbackTargetJid aqui ainda, então não podemos notificar o usuário facilmente
        http_response_code(500); // Erro interno
        exit;
    }

} else {
    // Mensagem não é texto ou não é um reply (quotedMessageId está null)
    // Loga apenas se for mensagem de texto para não poluir com status, etc.
    if($userReplyText !== null) {
      // logMessage("[Webhook Receiver v15.4] Mensagem de texto ignorada (não é reply ou texto vazio). Sender: $sender");
    }
    http_response_code(200); // OK, apenas ignoramos
    exit;
}

?>


<?php
/**
 * Arquivo: go_to_asaas_payment.php
 * Versão: v1.1 - Implementa busca de link para assinaturas existentes PENDENTE/OVERDUE.
 * Descrição: Se assinatura não existe, cria no Asaas e redireciona para pagamento.
 *            Se assinatura existe e está PENDENTE ou OVERDUE, tenta buscar o link
 *            da fatura existente no Asaas e redireciona para ele.
 *            Caso contrário (status existente não pagável), redireciona para billing.php.
 */

// Includes Essenciais
require_once __DIR__ . '/config.php'; // Contém session_start()
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/log_helper.php';
require_once __DIR__ . '/includes/asaas_api.php'; // Agora inclui getAsaasPendingPaymentLink

// --- Proteção: Exige Login ---
if (!isset($_SESSION['saas_user_id'])) {
    // Se não estiver logado, redireciona para o login
    header('Location: login.php?error=unauthorized');
    exit;
}
$saasUserId = $_SESSION['saas_user_id'];
logMessage("[GoToAsaas v1.1] Iniciando processo para SaaS User ID: $saasUserId");

// --- Buscar Asaas Customer ID e verificar Assinatura localmente ---
$asaasCustomerId = null;
$currentSubscriptionId = null;
$currentStatus = null; // Status local da assinatura
$pdo = null; // Inicializa PDO

try {
    $pdo = getDbConnection();
    logMessage("[GoToAsaas v1.1] Buscando dados Asaas do usuário $saasUserId no DB local...");
    // Busca dados relevantes do usuário
    $stmtUser = $pdo->prepare("SELECT asaas_customer_id, asaas_subscription_id, subscription_status FROM saas_users WHERE id = :id");
    $stmtUser->execute([':id' => $saasUserId]);
    $userData = $stmtUser->fetch();

    // Validações essenciais dos dados do usuário
    if (!$userData) {
        logMessage("[GoToAsaas v1.1] ERRO CRÍTICO: Usuário SaaS $saasUserId não encontrado no DB local.");
        header('Location: billing.php?billing_status=db_error&code=user_not_found');
        exit;
    }
    if (empty($userData['asaas_customer_id'])) {
         logMessage("[GoToAsaas v1.1] ERRO CRÍTICO: Usuário SaaS $saasUserId sem Asaas Customer ID.");
         // Isso indica um problema no cadastro ou fluxo anterior
         header('Location: billing.php?billing_status=asaas_error&code=no_customer_id');
         exit;
    }

    $asaasCustomerId = $userData['asaas_customer_id'];
    $currentSubscriptionId = $userData['asaas_subscription_id']; // Pode ser NULL
    $currentStatus = $userData['subscription_status'];           // Status local (PENDING, OVERDUE, etc.)
    logMessage("[GoToAsaas v1.1] Dados encontrados: CustID=$asaasCustomerId, SubID Local=" . ($currentSubscriptionId ?: 'NENHUM') . ", StatusLocal=$currentStatus");

    // --- Lógica Principal ---

    // Cenário 1: JÁ EXISTE uma assinatura Asaas registrada localmente
    if (!empty($currentSubscriptionId)) {
        logMessage("[GoToAsaas v1.1] Assinatura Asaas ID $currentSubscriptionId já existe localmente.");

        // Verifica se o status local permite tentar buscar um link de pagamento existente
        if ($currentStatus === 'PENDING' || $currentStatus === 'OVERDUE') {
            logMessage("  -> Status local é '$currentStatus'. Tentando buscar link de pagamento existente no Asaas...");

            // Chama a nova função para buscar o link da fatura PENDENTE ou OVERDUE
            $paymentUrl = getAsaasPendingPaymentLink($currentSubscriptionId);

            if ($paymentUrl) {
                // Encontrou o link da fatura existente! Redireciona para ele.
                logMessage("  -> Link da fatura existente encontrado: $paymentUrl. Redirecionando usuário...");
                // --- PONTO DE REDIRECIONAMENTO PARA FATURA EXISTENTE ---
                header('Location: ' . $paymentUrl);
                exit; // Garante a finalização do script
            } else {
                // Não encontrou link (pode ser erro na API Asaas ou não há fatura pendente/vencida lá)
                logMessage("  -> ERRO/AVISO: Não foi possível obter link de pagamento para a assinatura existente $currentSubscriptionId (Status Local: $currentStatus). Ver logs asaas_api. Redirecionando para billing.");
                // --- PONTO DE REDIRECIONAMENTO DE VOLTA (FALHA NA BUSCA) ---
                header('Location: billing.php?billing_status=link_error&reason=existing_not_found');
                exit; // Garante a finalização do script
            }
        } else {
            // O status local é diferente de PENDING/OVERDUE (ex: ACTIVE, CANCELED). Não há o que pagar.
            logMessage("  -> Status local é '$currentStatus', não é PENDENTE/OVERDUE. Redirecionando de volta para billing.php para exibir status.");
            // --- PONTO DE REDIRECIONAMENTO DE VOLTA (STATUS NÃO PAGÁVEL) ---
            header('Location: billing.php');
            exit; // Garante a finalização do script
        }
    }
    // Cenário 2: NÃO existe ID de assinatura local -> Tenta CRIAR uma nova
    else {
         logMessage("[GoToAsaas v1.1] Nenhuma assinatura Asaas registrada localmente. Tentando criar uma nova...");

         // Chama a função para criar a assinatura e obter link/dados da 1a cobrança
         $subscriptionData = createAsaasSubscriptionRedirect($asaasCustomerId);

         if ($subscriptionData && isset($subscriptionData['id'])) {
             $newSubscriptionId = $subscriptionData['id'];
             $paymentUrl = $subscriptionData['paymentLink'] ?? null; // Link da 1a cobrança

             logMessage("[GoToAsaas v1.1] Nova assinatura Asaas criada (ID: $newSubscriptionId). Link Pagamento: " . ($paymentUrl ?: 'NÃO OBTIDO'));

             // Atualiza DB local com o novo ID
             logMessage("[GoToAsaas v1.1] Atualizando DB local (SaaS ID $saasUserId) com Sub ID: $newSubscriptionId...");
             $stmtUpdate = $pdo->prepare("UPDATE saas_users SET asaas_subscription_id = :sub_id, updated_at = NOW() WHERE id = :saas_id");
             $updateSuccess = $stmtUpdate->execute([':sub_id' => $newSubscriptionId, ':saas_id' => $saasUserId]);

             if ($updateSuccess) {
                 logMessage("[GoToAsaas v1.1] DB local atualizado.");
                 $_SESSION['asaas_subscription_id'] = $newSubscriptionId; // Opcional: Atualiza sessão
             } else {
                  logMessage("[GoToAsaas v1.1] ERRO ao atualizar DB local com novo Sub ID $newSubscriptionId (usuário $saasUserId).");
             }

             // Redireciona para pagamento se obteve o link
             if ($paymentUrl) {
                 logMessage("[GoToAsaas v1.1] Redirecionando usuário para URL de pagamento da nova assinatura: $paymentUrl");
                 // --- PONTO DE REDIRECIONAMENTO PARA NOVA FATURA ---
                 header('Location: ' . $paymentUrl);
                 exit; // Garante a finalização do script
             } else {
                 // Assinatura criada, mas não conseguiu link
                 logMessage("[GoToAsaas v1.1] ERRO: Nova assinatura $newSubscriptionId criada, mas URL de pagamento não obtida. Redirecionando para billing com erro.");
                 // --- PONTO DE REDIRECIONAMENTO DE VOLTA (FALHA LINK NOVA FATURA) ---
                 header('Location: billing.php?billing_status=link_error&reason=new_sub_no_link');
                 exit; // Garante a finalização do script
             }
         } else {
             // Falha ao criar assinatura na API Asaas
             logMessage("[GoToAsaas v1.1] ERRO CRÍTICO: Falha ao chamar createAsaasSubscriptionRedirect para Customer ID $asaasCustomerId.");
              // --- PONTO DE REDIRECIONAMENTO DE VOLTA (FALHA CRIAÇÃO ASSINATURA) ---
             header('Location: billing.php?billing_status=asaas_error&code=sub_create_failed');
             exit; // Garante a finalização do script
         }
    } // Fim Cenário 2

} catch (\PDOException $e) {
    logMessage("[GoToAsaas v1.1] Erro CRÍTICO DB (SaaS ID $saasUserId): " . $e->getMessage());
    // --- PONTO DE REDIRECIONAMENTO DE VOLTA (ERRO DB) ---
    header('Location: billing.php?billing_status=db_error');
    exit; // Garante a finalização do script
} catch (\Throwable $e) { // Captura outros erros (ex: API Asaas, lógica)
    logMessage("[GoToAsaas v1.1] Erro CRÍTICO Geral (SaaS ID $saasUserId): " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine());
     // --- PONTO DE REDIRECIONAMENTO DE VOLTA (ERRO GERAL) ---
    header('Location: billing.php?billing_status=internal_error');
    exit; // Garante a finalização do script
}

// Código de fallback caso algo muito estranho aconteça
logMessage("[GoToAsaas v1.1] AVISO: Script terminou inesperadamente sem redirecionamento (SaaS ID $saasUserId).");
echo "Ocorreu um erro inesperado no servidor ao processar sua solicitação. Por favor, <a href='billing.php'>clique aqui para voltar</a> ou contate o suporte.";
exit; // Garante que nada mais seja impresso
?>


<?php
/**
 * Arquivo: index.php
 * Versão: v4.2 - Confirma includes após refatoração
 * Descrição: Página inicial (landing page) do Meli AI com Tailwind.
 *            Redireciona para o dashboard se o usuário já estiver logado.
 */

// Inclui config para iniciar a sessão e verificar login
require_once __DIR__ . '/config.php';

// Verifica se o usuário já está logado na sessão
if (isset($_SESSION['saas_user_id'])) {
    header('Location: dashboard.php'); // Redireciona para o painel
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br" class=""> <!-- Add class="" for potential future JS theme toggle -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bem-vindo ao Meli AI - Respostas Inteligentes para Mercado Livre</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css"> <!-- Link para o CSS centralizado -->
    <style>
        /* Minimal base styles if needed - prefer Tailwind utilities */
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 transition-colors duration-300 flex flex-col min-h-screen">

    <!-- Seção Hero -->
    <section class="main-content flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-2xl w-full space-y-8 text-center">
             <!-- Placeholder para Logo 
             <div class="mx-auto h-20 w-20 text-blue-500 dark:text-blue-400">
                Substitua pelo seu SVG ou IMG 
                 <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-full h-full">
                   <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                   <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 15.75V18m-7.5-2.25V18m-7.5-2.25H4.5v.75a.75.75 0 0 0 1.5 0v-.75h.75a.75.75 0 0 0 0-1.5h-.75V15a.75.75 0 0 0-1.5 0v.75H3a.75.75 0 0 0-.75.75Zm15 .75a.75.75 0 0 0 .75-.75v-.75a.75.75 0 0 0-1.5 0V15h-.75a.75.75 0 0 0 0 1.5h.75v.75a.75.75 0 0 0 .75.75Z" />
                 </svg>
             </div> -->
            <h1 class="text-4xl font-extrabold tracking-tight text-gray-900 dark:text-white sm:text-5xl">
                🤖 Meli AI
            </h1>
            <p class="mt-4 text-xl text-gray-500 dark:text-gray-400">
                Responda perguntas do Mercado Livre 10x mais rápido com Inteligência Artificial e notificações WhatsApp.
            </p>
            <!-- Botões de Ação -->
            <div class="mt-10 flex flex-col sm:flex-row sm:justify-center space-y-4 sm:space-y-0 sm:space-x-4">
                <a href="login.php" class="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-lg shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                    🔑 Acessar Painel
                </a>
                <a href="register.php" class="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-lg text-blue-700 bg-blue-100 hover:bg-blue-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:bg-green-600 dark:text-white dark:hover:bg-green-700 dark:focus:ring-offset-gray-800">
                    🚀 Criar Conta
                </a>
            </div>
        </div>
    </section>

     <!-- Rodapé Principal -->
     <footer class="py-6 text-center">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            <strong>Meli AI</strong> © <?php echo date('Y'); ?> Todos os direitos reservados.
        </p>
    </footer>

</body>
</html>


<?php
/**
 * Arquivo: login.php
 * Versão: v5.3 - Carrega status da assinatura na sessão e redireciona (Confirmado)
 * Descrição: Página de login. Verifica credenciais, carrega status da assinatura
 *            e ID do cliente Asaas para a sessão. Redireciona para dashboard
 *            (se ativo) ou billing (se não ativo).
 */

// Includes essenciais
require_once __DIR__ . '/config.php';             // Constantes e Session (inicia sessão)
require_once __DIR__ . '/db.php';                 // Para getDbConnection()
require_once __DIR__ . '/includes/log_helper.php'; // Para logMessage()

// --- Inicialização ---
$errors = [];
$message = null;
$email_value = ''; // Para repopular campo email em caso de erro

// --- Redirecionamento se já logado ---
// Se já existe uma sessão ativa, verifica o status e redireciona
if (isset($_SESSION['saas_user_id'])) {
    if (isset($_SESSION['subscription_status']) && $_SESSION['subscription_status'] === 'ACTIVE') {
        // Se sessão indica assinatura ativa, vai pro dashboard
        header('Location: dashboard.php');
    } else {
        // Se status não for ACTIVE ou indefinido na sessão, manda pra billing
        header('Location: billing.php');
    }
    exit; // Importante finalizar após redirecionamento
}

// --- Tratamento de Mensagens da URL (Feedback de outras páginas) ---
$message_classes = [ // Mapeamento para classes Tailwind CSS
    'is-info is-light' => 'bg-blue-100 dark:bg-blue-900 border border-blue-300 dark:border-blue-700 text-blue-700 dark:text-blue-300',
    'is-success' => 'bg-green-100 dark:bg-green-900 border border-green-300 dark:border-green-700 text-green-700 dark:text-green-300',
    'is-warning is-light' => 'bg-yellow-100 dark:bg-yellow-900 border border-yellow-400 dark:border-yellow-700 text-yellow-800 dark:text-yellow-300',
    'is-danger is-light' => 'bg-red-100 dark:bg-red-900 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300',
];
$message_class = '';
$error_class = $message_classes['is-danger is-light']; // Classe padrão para erros

// Verifica parâmetros GET para exibir mensagens de feedback
if (isset($_GET['status'])) {
    $status = $_GET['status'];
    if ($status === 'loggedout') {
        $message = ['type' => 'is-info is-light', 'text' => '👋 Você saiu com sucesso. Até logo!'];
    } elseif ($status === 'registered') { // Mensagem vinda do register.php (v5.4)
        $message = ['type' => 'is-success', 'text' => '✅ Cadastro realizado! Você já está logado. Siga para o pagamento.'];
        // Nota: Na v5.4 o usuário já é redirecionado para billing.php, esta mensagem pode não ser vista.
        // Mantida por compatibilidade ou caso o fluxo mude.
    } elseif ($status === 'registered_pending_payment') { // Mensagem da versão anterior do register.php
        $message = ['type' => 'is-success', 'text' => '✅ Cadastro realizado! Faça login para ir para a página de pagamento e ativar sua assinatura.'];
    }
} elseif (isset($_GET['error'])) {
    $error = $_GET['error'];
    if ($error === 'unauthorized') {
        $message = ['type' => 'is-warning is-light', 'text' => '✋ Você precisa fazer login para acessar essa página.'];
    } elseif ($error === 'session_expired') {
        $message = ['type' => 'is-warning is-light', 'text' => '⏱️ Sua sessão expirou. Faça login novamente.'];
    } elseif ($error === 'internal_error') {
        $message = ['type' => 'is-danger is-light', 'text' => '⚙️ Ocorreu um erro interno. Tente novamente mais tarde.'];
    } elseif ($error === 'inactive_subscription') { // Pode vir de outras verificações
         $message = ['type' => 'is-warning is-light', 'text' => '⚠️ Sua assinatura não está ativa. Faça login para verificar ou regularizar.'];
    }
}

// Define a classe CSS para a mensagem, se houver
if ($message && isset($message_classes[$message['type']])) {
    $message_class = $message_classes[$message['type']];
}

// Limpa os parâmetros GET da URL após lê-los para não persistirem no refresh
// Executado via Javascript no lado do cliente
if (isset($_GET['status']) || isset($_GET['error'])) {
    echo "<script> if (history.replaceState) { setTimeout(function() { history.replaceState(null, null, window.location.pathname); }, 1); } </script>";
}

// --- Processamento do Formulário de Login ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $email_value = $_POST['email'] ?? ''; // Guarda para repopular o campo

    // Validações básicas
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "📧 Formato de e-mail inválido.";
    }
    if (empty($password)) {
        $errors[] = "🔒 Senha é obrigatória.";
    }

    // Se não houver erros de validação inicial
    if (empty($errors)) {
        try {
            $pdo = getDbConnection();
            // Busca usuário pelo email e pega dados relevantes (incluindo status e ID Asaas)
            $stmt = $pdo->prepare("SELECT id, email, password_hash, is_saas_active, subscription_status, asaas_customer_id FROM saas_users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            // Verifica se o usuário existe e se a senha está correta
            if ($user && password_verify($password, $user['password_hash'])) {

                // Verifica se a conta está ativa administrativamente
                // Permite login se status for PENDING (para permitir que o usuário pague)
                if (!$user['is_saas_active'] && $user['subscription_status'] !== 'PENDING') {
                    $errors[] = "🚫 Sua conta está desativada administrativamente. Contate o suporte.";
                    logMessage("Login falhou (conta inativa admin): " . $email);
                } else {
                    // Sucesso no Login!
                    logMessage("Login SUCESSO: " . $email . " (SaaS ID: " . $user['id'] . ", Sub Status: " . $user['subscription_status'] . ")");

                    // Regenera o ID da sessão para segurança
                    session_regenerate_id(true);

                    // Armazena dados importantes na sessão
                    $_SESSION['saas_user_id'] = $user['id'];
                    $_SESSION['saas_user_email'] = $user['email'];
                    $_SESSION['subscription_status'] = $user['subscription_status']; // Guarda status da assinatura
                    $_SESSION['asaas_customer_id'] = $user['asaas_customer_id'];   // Guarda ID cliente Asaas

                    // Redirecionamento Condicional Baseado no Status da Assinatura
                    if ($user['subscription_status'] === 'ACTIVE') {
                         logMessage("Login OK, assinatura ATIVA. Redirecionando para dashboard.");
                         header('Location: dashboard.php');
                    } else {
                        // Se status for PENDING, OVERDUE, INACTIVE, CANCELED, etc., vai para billing.
                        logMessage("Login OK, mas assinatura NÃO está ativa ($user[subscription_status]). Redirecionando para billing.");
                        header('Location: billing.php');
                    }
                    exit; // Finaliza o script após o redirecionamento
                }
            } else {
                // Usuário não encontrado ou senha incorreta
                $errors[] = "❌ E-mail ou senha incorretos.";
                logMessage("Login falhou (credenciais inválidas): " . $email);
            }
        } catch (\PDOException $e) {
            logMessage("Erro DB login $email: " . $e->getMessage());
            $errors[] = "🛠️ Erro interno ao acessar dados. Tente novamente."; // Mensagem genérica
        } catch (\Exception $e) {
            logMessage("Erro geral login $email: " . $e->getMessage());
            $errors[] = "⚙️ Erro inesperado no servidor. Tente novamente."; // Mensagem genérica
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Meli AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <style> body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; } </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 transition-colors duration-300">
    <section class="flex flex-col items-center justify-center min-h-screen py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full bg-white dark:bg-gray-800 shadow-md rounded-lg p-8 space-y-6">
            <h1 class="text-3xl font-bold text-center text-gray-900 dark:text-white">🔑 Login Meli AI</h1>

            <!-- Mensagens de status/erro -->
            <?php if ($message && $message_class): ?>
                <div class="<?php echo $message_class; ?> px-4 py-3 rounded-md text-sm mb-4" role="alert">
                    <?php echo htmlspecialchars($message['text']); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="<?php echo $error_class; ?> px-4 py-3 rounded-md text-sm mb-4" role="alert">
                    <ul class="list-disc list-inside space-y-1">
                        <?php foreach ($errors as $e): ?>
                            <li><?php echo htmlspecialchars($e); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Formulário de Login -->
            <form action="login.php" method="POST" novalidate class="space-y-6">
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">📧 E-mail</label>
                    <input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                           type="email" id="email" name="email" placeholder="seuemail@exemplo.com" required
                           value="<?php echo htmlspecialchars($email_value); ?>" autocomplete="email">
                </div>

                <div>
                    <div class="flex justify-between items-baseline">
                         <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">🔒 Senha</label>
                         <!-- Link para recuperação de senha (se implementado) -->
                         <!-- <a href="forgot_password.php" class="text-xs text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Esqueceu a senha?</a> -->
                    </div>
                    <input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                           type="password" id="password" name="password" placeholder="Sua senha" required autocomplete="current-password">
                </div>

                <div>
                    <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-base font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                        Entrar
                    </button>
                </div>
            </form>

             <p class="text-sm text-center text-gray-500 dark:text-gray-400">
                 Não tem uma conta? <a href="register.php" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Cadastre-se aqui</a>.
             </p>
             <p class="text-center mt-2 text-xs text-gray-500 dark:text-gray-400">
                 <a href="index.php" class="hover:underline">← Voltar</a>
             </p>
        </div>

         <footer class="mt-8 text-center text-sm text-gray-500 dark:text-gray-400">
             <p>© <?php echo date('Y'); ?> Meli AI</p>
         </footer>
    </section>
</body>
</html>


<?php
/**
 * Arquivo: logout.php
 * Versão: v1.1 - Confirma includes após refatoração
 * Descrição: Destrói a sessão do usuário SaaS e redireciona para o login.
 */

// Inclui config para garantir que a sessão está iniciada antes de manipulá-la
require_once __DIR__ . '/config.php';

// 1. Unset todas as variáveis de sessão.
$_SESSION = [];

// 2. Se usar cookies de sessão, deleta o cookie.
// Nota: Isso destruirá a sessão, e não apenas os dados da sessão!
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, // Tempo no passado para expirar
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Finalmente, destrói a sessão no servidor.
session_destroy();

// 4. Redireciona para a página de login com uma mensagem indicando o logout.
header("Location: login.php?status=loggedout");
exit; // Garante que o script termine após o redirecionamento.
?>




<?php
/**
 * Arquivo: ml_webhook_receiver.php
 * Versão: v1.2 - Corrige includes para estrutura confirmada.
 * Descrição: Endpoint para receber notificações POST do ML sobre novas perguntas.
 */

// --- Includes Essenciais ---
// Inclui config e db da raiz do projeto (mesmo diretório)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Inclui helpers da subpasta 'includes'
require_once __DIR__ . '/includes/log_helper.php';       // logMessage (definida aqui ou em config.php)
require_once __DIR__ . '/includes/db_interaction.php'; // getQuestionLogStatus, upsertQuestionLog
require_once __DIR__ . '/includes/ml_api.php';           // Funções ML
require_once __DIR__ . '/includes/evolution_api.php';    // sendWhatsAppImageNotification

// Verifica se logMessage foi definida (pode ter sido em config.php)
if (!function_exists('logMessage')) {
    // Fallback básico se logMessage não estiver disponível
    function logMessage(string $message): void { error_log($message); }
}

logMessage("==== [ML Webhook Receiver v1.2] Notificação Recebida ====");

// --- Validação Inicial ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logMessage("[ML Webhook Receiver] ERRO: Método HTTP inválido.");
    http_response_code(405); exit;
}
// !! TODO: Adicionar validação de segurança (ex: segredo na URL via ML_WEBHOOK_SECRET) !!
/*
if (defined('ML_WEBHOOK_SECRET') && !empty(ML_WEBHOOK_SECRET)) {
    $receivedSecret = $_GET['secret'] ?? null;
    if (!hash_equals(ML_WEBHOOK_SECRET, $receivedSecret)) {
        logMessage("[ML Webhook Receiver] ERRO: Segredo inválido ou ausente na URL.");
        http_response_code(403); exit;
    }
    logMessage("[ML Webhook Receiver] Segredo da URL validado.");
} else {
    logMessage("[ML Webhook Receiver] AVISO: Validação de segredo da URL DESABILITADA.");
}
*/

// --- Processamento do Payload ---
$payload = file_get_contents('php://input');
$notificationData = $payload ? json_decode($payload, true) : null;
if (!$notificationData || json_last_error() !== JSON_ERROR_NONE) {
    logMessage("[ML Webhook Receiver] ERRO: Payload JSON inválido.");
    http_response_code(400); exit;
}

// --- Extração e Validação dos Dados ---
$topic = $notificationData['topic'] ?? null;
$resource = $notificationData['resource'] ?? null;
$userIdML = $notificationData['user_id'] ?? null;
$attempts = $notificationData['attempts'] ?? 1;
logMessage("[ML Webhook Receiver] Notificação: Topic='{$topic}', Resource='{$resource}', UserID_ML='{$userIdML}', Attempts='{$attempts}'");

// Processa apenas o tópico 'questions'
if ($topic !== 'questions' || !$resource || !$userIdML) {
    logMessage("[ML Webhook Receiver] Ignorada (tópico não é 'questions' ou dados ausentes).");
    http_response_code(200); exit; // OK para o ML, apenas ignoramos
}

// Extrai o ID da pergunta do resource
if (!preg_match('/\/questions\/(\d+)/', $resource, $matches)) {
    logMessage("[ML Webhook Receiver] ERRO: ID da pergunta não extraído do resource: '$resource'");
    http_response_code(400); exit;
}
$questionId = (int)$matches[1];
$mlUserId = (int)$userIdML;
logMessage("[ML Webhook Receiver] Pergunta ID: $questionId para Vendedor ML ID: $mlUserId");

// --- Lógica Principal ---
try {
    $pdo = getDbConnection(); // Função de db.php
    $globalNow = new DateTimeImmutable();

    // 1. Verificar log existente para evitar processamento duplicado
    $logEntry = getQuestionLogStatus($questionId); // Função de db_interaction.php
    if ($logEntry) {
        logMessage("  [QID $questionId] Já existe no log (Status: {$logEntry['status']}). Ignorando notificação webhook.");
        http_response_code(200); exit;
    }

    // 2. Buscar conexão ML ativa, JID e status da assinatura SaaS
    logMessage("  [QID $questionId] Buscando conexão ML/SaaS ativa para ML User ID: $mlUserId...");
    $stmtMLUser = $pdo->prepare(
        "SELECT mlu.id AS connection_id, mlu.saas_user_id, mlu.access_token, mlu.refresh_token, mlu.token_expires_at,
                su.whatsapp_jid, su.subscription_status
         FROM mercadolibre_users mlu
         JOIN saas_users su ON mlu.saas_user_id = su.id
         WHERE mlu.ml_user_id = :ml_uid AND mlu.is_active = TRUE AND su.is_saas_active = TRUE
         LIMIT 1"
    );
    $stmtMLUser->execute([':ml_uid' => $mlUserId]);
    $mlUserConn = $stmtMLUser->fetch();

    if (!$mlUserConn) {
        logMessage("  [QID $questionId] ERRO: Conexão ML ativa ou usuário SaaS ativo não encontrado para ML User ID: $mlUserId.");
        // Responde OK para o ML, pois não é um erro do webhook em si, mas da nossa lógica/dados.
        http_response_code(200); exit;
    }

    // Extrai dados da conexão
    $connectionIdInDb = $mlUserConn['connection_id'];
    $saasUserId = (int)$mlUserConn['saas_user_id'];
    $whatsappTargetJid = $mlUserConn['whatsapp_jid'];
    $dbAccessTokenEncrypted = $mlUserConn['access_token']; // Token criptografado
    $dbRefreshTokenEncrypted = $mlUserConn['refresh_token']; // Token criptografado
    $tokenExpiresAtStr = $mlUserConn['token_expires_at'];
    $subscriptionStatus = $mlUserConn['subscription_status'];
    $currentAccessToken = null; // Será preenchido após descriptografar/renovar

    logMessage("  [QID $questionId] Conexão encontrada: DB ID=$connectionIdInDb, SaaS ID=$saasUserId, JID=$whatsappTargetJid, Sub Status=$subscriptionStatus");

    // 3. VERIFICAÇÃO DE ASSINATURA ATIVA
    if ($subscriptionStatus !== 'ACTIVE') {
        logMessage("  [QID $questionId] Processamento IGNORADO: Assinatura do usuário SaaS ID $saasUserId não está ATIVA (Status: $subscriptionStatus).");
        // Não registra erro no log de perguntas, apenas ignora a notificação.
        http_response_code(200); // OK para o ML
        exit;
    }

    // 4. Validar/Refrescar Token ML (usando decryptData e refreshMercadoLibreToken)
    logMessage("    [ML $mlUserId QID $questionId] Validando/Refrescando token ML...");
    try {
        if (empty($dbAccessTokenEncrypted) || empty($dbRefreshTokenEncrypted)) { throw new Exception("Tokens criptografados vazios no DB."); }
        $currentAccessToken = decryptData($dbAccessTokenEncrypted); // Usa decryptData de db.php
        $refreshTokenDecrypted = decryptData($dbRefreshTokenEncrypted);
        if (empty($tokenExpiresAtStr)) { throw new Exception("Data de expiração do token vazia no DB."); }
        $tokenExpiresAt = new DateTimeImmutable($tokenExpiresAtStr);

        if ($globalNow >= $tokenExpiresAt->modify("-10 minutes")) {
            logMessage("    [ML $mlUserId QID $questionId] Token precisa ser renovado...");
            $refreshResult = refreshMercadoLibreToken($refreshTokenDecrypted); // Função de ml_api.php

            if ($refreshResult['httpCode'] == 200 && isset($refreshResult['response']['access_token'])) {
                $newData = $refreshResult['response'];
                $currentAccessToken = $newData['access_token'];
                $newRefreshToken = $newData['refresh_token'] ?? $refreshTokenDecrypted;
                $newExpiresIn = $newData['expires_in'] ?? 21600;
                $newExpAt = $globalNow->modify("+" . (int)$newExpiresIn . " seconds")->format('Y-m-d H:i:s');

                $encAT = encryptData($currentAccessToken); // Criptografa novos tokens
                $encRT = encryptData($newRefreshToken);

                $upSql = "UPDATE mercadolibre_users SET access_token = :at, refresh_token = :rt, token_expires_at = :exp, updated_at = NOW() WHERE id = :id";
                $upStmt = $pdo->prepare($upSql);
                $upStmt->execute([':at' => $encAT, ':rt' => $encRT, ':exp' => $newExpAt, ':id' => $connectionIdInDb]);
                logMessage("    [ML $mlUserId QID $questionId] Refresh do token ML OK e salvo no DB.");
            } else {
                // Falha grave no refresh
                $errorResponse = json_encode($refreshResult['response'] ?? $refreshResult['error'] ?? 'N/A');
                logMessage("    [ML $mlUserId QID $questionId] ERRO FATAL ao renovar token ML. HTTP: {$refreshResult['httpCode']}. Desativando conexão. Resp: " . $errorResponse);
                @$pdo->exec("UPDATE mercadolibre_users SET is_active=FALSE, updated_at = NOW() WHERE id=".$connectionIdInDb);
                upsertQuestionLog($questionId, $mlUserId, 'N/A', 'ERROR', null, null, null, 'Falha refresh token API ML (Webhook)', $saasUserId);
                http_response_code(200); // OK para o ML, mas falhamos internamente
                exit;
            }
        } else {
            logMessage("    [ML $mlUserId QID $questionId] Token ML ainda válido.");
        }
    } catch (Exception $e) {
        logMessage("    [ML $mlUserId QID $questionId] ERRO CRÍTICO ao lidar com token ML: ".$e->getMessage());
        @$pdo->exec("UPDATE mercadolibre_users SET is_active = FALSE, updated_at = NOW() WHERE id=".$connectionIdInDb);
        upsertQuestionLog($questionId, $mlUserId, 'N/A', 'ERROR', null, null, null, 'Erro decrypt/process token ML (Webhook): '.substr($e->getMessage(),0,100), $saasUserId);
        http_response_code(200); // OK para o ML
        exit;
    }
    if (empty($currentAccessToken)) { // Verificação extra
         logMessage("    [ML $mlUserId QID $questionId] ERRO FATAL INESPERADO: Access token vazio após lógica.");
         upsertQuestionLog($questionId, $mlUserId, 'N/A', 'ERROR', null, null, null, 'Token ML vazio inesperado (Webhook)', $saasUserId);
         http_response_code(200); exit;
    }
    logMessage("    [ML $mlUserId QID $questionId] Token ML pronto.");

    // 5. Buscar Detalhes da Pergunta Específica no ML
    logMessage("  [QID $questionId] Buscando detalhes da pergunta no ML...");
    $mlQuestionData = getMercadoLibreQuestionStatus($questionId, $currentAccessToken); // Função de ml_api.php
    if ($mlQuestionData['httpCode'] != 200 || !$mlQuestionData['is_json'] || !isset($mlQuestionData['response']['status'])) {
        $apiError = json_encode($mlQuestionData['response'] ?? $mlQuestionData['error'] ?? 'N/A');
        logMessage("  [QID $questionId] ERRO: Falha ao buscar detalhes/status da pergunta no ML. HTTP: {$mlQuestionData['httpCode']}. Detalhe: $apiError");
        // Registra o erro, mas responde OK ao ML, pois a notificação foi recebida.
        upsertQuestionLog($questionId, $mlUserId, 'N/A', 'ERROR', null, null, null, 'Falha API ML get status (Webhook)', $saasUserId);
        http_response_code(200); exit;
    }
    $questionDetails = $mlQuestionData['response'];
    $currentMLStatus = $questionDetails['status'];
    $itemId = $questionDetails['item_id'] ?? 'N/A';
    $questionTextRaw = $questionDetails['text'] ?? '';
    logMessage("  [QID $questionId] Detalhes obtidos. Status ML: '$currentMLStatus'. Item ID: '$itemId'.");

    // 6. Processar APENAS se estiver NÃO RESPONDIDA no ML
    if ($currentMLStatus !== 'UNANSWERED') {
        logMessage("  [QID $questionId] Status no ML não é 'UNANSWERED' (é '$currentMLStatus'). Ignorando.");
        http_response_code(200); exit;
    }
    // Validação adicional de dados essenciais
    if (empty(trim($questionTextRaw)) || empty($itemId) || $itemId === 'N/A') {
        logMessage("  [QID $questionId] ERRO: Texto da pergunta ou Item ID ausentes na resposta da API ML.");
        upsertQuestionLog($questionId, $mlUserId, $itemId ?: 'N/A', 'ERROR', $questionTextRaw, null, null, 'Dados inválidos API ML (Webhook)', $saasUserId);
        http_response_code(200); exit;
    }

    // 7. Verificar se há JID para notificar
    if (empty($whatsappTargetJid)) {
        logMessage("  [QID $questionId] Usuário SaaS ID $saasUserId não possui JID configurado. Marcando pergunta como PENDING_WHATSAPP.");
        upsertQuestionLog($questionId, $mlUserId, $itemId, 'PENDING_WHATSAPP', $questionTextRaw, null, null, 'JID usuário não configurado (Webhook)', $saasUserId);
        http_response_code(200); exit;
    }

    // 8. Buscar detalhes do item para imagem
    logMessage("  [QID $questionId] Buscando detalhes do item $itemId para imagem...");
    $itemTitle = '[Produto não encontrado]'; $itemImageUrl = null;
    $itemResult = getMercadoLibreItemDetails($itemId, $currentAccessToken); // Função de ml_api.php
    if ($itemResult['httpCode'] == 200 && $itemResult['is_json']) {
        $itemData = $itemResult['response'];
        $itemTitle = $itemData['title'] ?? $itemTitle;
        // Tenta pegar a primeira imagem ou o thumbnail
        $itemImageUrl = $itemData['pictures'][0]['secure_url'] ?? $itemData['thumbnail'] ?? null;
        logMessage("  [QID $questionId] Detalhes do item obtidos. Título: '$itemTitle'. URL Imagem: " . ($itemImageUrl ? 'OK' : 'NÃO ENCONTRADA'));
    } else {
        logMessage("  [QID $questionId] AVISO: Falha ao buscar detalhes do item $itemId. HTTP: {$itemResult['httpCode']}. Tentará enviar notificação sem imagem.");
    }

    // 9. Montar e Enviar Notificação WhatsApp
    $timeoutMinutes = defined('AI_FALLBACK_TIMEOUT_MINUTES') ? AI_FALLBACK_TIMEOUT_MINUTES : 10;
    $captionText = "🔔 *Nova pergunta no Mercado Livre:*\n\n";
    $captionText .= "```" . htmlspecialchars(trim($questionTextRaw)) . "```\n\n";
    $captionText .= "1️⃣ *Responder Manualmente:*\n   _(Responda esta mensagem com o texto)_.\n";
    $captionText .= "2️⃣ *Usar Resposta da IA:*\n   _(Responda esta mensagem apenas com o número `2`)_.\n\n";
    $captionText .= "⏳ A IA responderá automaticamente em *{$timeoutMinutes} minutos* se não houver ação.\n\n";
    $captionText .= "_(Ref: {$questionId} | Item: {$itemId} - " . mb_substr($itemTitle, 0, 50) . ($itemTitle !== '[Produto não encontrado]' && mb_strlen($itemTitle) > 50 ? '...' : '') . ")_";

    $whatsappMessageId = null;
    if ($itemImageUrl && filter_var($itemImageUrl, FILTER_VALIDATE_URL)) {
        logMessage("  [QID $questionId] Enviando notificação COM IMAGEM para $whatsappTargetJid...");
        $whatsappMessageId = sendWhatsAppImageNotification($whatsappTargetJid, $itemImageUrl, $captionText); // Função de evolution_api.php
    } else {
        logMessage("  [QID $questionId] Enviando notificação SEM IMAGEM para $whatsappTargetJid...");
        $whatsappMessageId = sendWhatsAppNotification($whatsappTargetJid, $captionText); // Função de evolution_api.php (texto simples)
    }

    // 10. Registrar no Log DB
    $initialStatus = $whatsappMessageId ? 'AWAITING_TEXT_REPLY' : 'PENDING_WHATSAPP';
    $sentTimestamp = $whatsappMessageId ? $globalNow->format('Y-m-d H:i:s') : null;
    $errorMsg = ($initialStatus === 'PENDING_WHATSAPP') ? 'Falha envio WhatsApp via webhook (ver logs evolution_api)' : null;

    logMessage("  [QID $questionId] Resultado envio WhatsApp: " . ($whatsappMessageId ? "Sucesso (MsgID: $whatsappMessageId)" : "Falha"));

    // Salva o log com o status apropriado e o ID da mensagem do WhatsApp (se houver)
    $upsertOK = upsertQuestionLog(
        $questionId, $mlUserId, $itemId, $initialStatus, $questionTextRaw,
        $sentTimestamp, null, $errorMsg, $saasUserId, null, $whatsappMessageId
    );

    if ($upsertOK) {
        logMessage("  [QID $questionId] UPSERT no log do banco de dados OK (Status: $initialStatus).");
    } else {
        logMessage("  [QID $questionId] ERRO ao executar UPSERT no log do banco de dados (Status: $initialStatus)!");
        // Considerar o que fazer se o upsert falhar? Por ora, só logamos.
    }

    // Responde OK para o Mercado Livre, pois processamos a notificação (mesmo que o envio Wpp tenha falhado)
    http_response_code(200);
    logMessage("==== [ML Webhook Receiver v1.2] Processamento concluído para QID $questionId ====");
    exit;

} catch (\Throwable $e) {
    // Captura erros fatais inesperados (DB, Lógica, etc.)
    $errorFile = basename($e->getFile()); $errorLine = $e->getLine();
    logMessage("[ML Webhook Receiver QID ".($questionId ?? 'N/A')."] **** ERRO FATAL INESPERADO ($errorFile Linha $errorLine) ****");
    logMessage("  Mensagem: {$e->getMessage()}");
    // Tenta registrar um erro genérico no log da pergunta, se possível
    if (isset($questionId) && $questionId > 0 && isset($mlUserId) && $mlUserId > 0) {
        $errorMsgForDb = "Exceção fatal webhook ($errorFile:$errorLine): ".substr($e->getMessage(),0,150);
        // Usa @ para suprimir erros se o upsert falhar aqui dentro do catch
        @upsertQuestionLog($questionId, $mlUserId, ($itemId ?? 'N/A'), 'ERROR', ($questionTextRaw ?? null), null, null, $errorMsgForDb, ($saasUserId ?? null));
    }
    // Responde com erro 500 para o ML indicar falha interna grave
    http_response_code(500);
    exit;
}



<?php
/**
 * Arquivo: oauth_callback.php
 * Versão: v1.1 - Atualiza includes após refatoração
 * Descrição: Recebe o retorno do Mercado Livre após autorização do usuário,
 *            troca o código de autorização por tokens e salva no banco de dados.
 */

// Includes Essenciais Refatorados
require_once __DIR__ . '/config.php'; // Constantes ML, DB, Session
require_once __DIR__ . '/db.php';     // Conexão DB e Funções de Criptografia (encryptData - !!PLACEHOLDER!!)
require_once __DIR__ . '/includes/log_helper.php'; // logMessage
require_once __DIR__ . '/includes/curl_helper.php';// makeCurlRequest

logMessage("[OAuth Callback v1.1] Recebido.");

// --- 1. Verificar se o usuário SaaS ainda está logado ---
if (!isset($_SESSION['saas_user_id'])) {
     logMessage("Erro Callback: Usuário SaaS não logado na sessão. Redirecionando para login.");
     header('Location: login.php?error=session_expired');
     exit;
}
$saasUserIdFromSession = $_SESSION['saas_user_id'];
logMessage("Callback: Sessão SaaS ativa para User ID: $saasUserIdFromSession");

// --- 2. Segurança: Validar o parâmetro 'state' (CSRF) ---
$receivedState = $_GET['state'] ?? null;
$expectedState = $_SESSION['oauth_state_expected'] ?? null;

// Limpa o state esperado da sessão imediatamente após lê-lo, independentemente do resultado
unset($_SESSION['oauth_state_expected']);

if (empty($receivedState) || empty($expectedState) || !hash_equals($expectedState, $receivedState)) {
    logMessage("Erro Callback CSRF: Estado OAuth inválido para SaaS User ID $saasUserIdFromSession. Recebido: '$receivedState' Esperado: '$expectedState'");
    header('Location: dashboard.php?status=ml_error&code=csrf_token_mismatch#conexao');
    exit;
}
logMessage("Callback: State CSRF validado OK para SaaS User ID: $saasUserIdFromSession.");

// Decodificar o state para verificar o UID interno (verificação adicional opcional mas recomendada)
$stateDecoded = json_decode(base64_decode($receivedState), true);
if (!$stateDecoded || !isset($stateDecoded['uid']) || $stateDecoded['uid'] != $saasUserIdFromSession) {
     logMessage("Erro Callback State Payload: UID no state não corresponde ao UID da sessão ($saasUserIdFromSession). State: '$receivedState'");
     header('Location: dashboard.php?status=ml_error&code=state_payload_mismatch#conexao');
     exit;
}
logMessage("Callback: State Payload UID verificado OK.");

// --- 3. Verificar se o código de autorização foi recebido ---
$code = $_GET['code'] ?? null;
if (empty($code)) {
    $error = $_GET['error'] ?? 'no_code';
    $errorDesc = $_GET['error_description'] ?? 'Código de autorização não recebido.';
    logMessage("Erro Callback: Código não recebido do ML para SaaS User ID $saasUserIdFromSession. Erro ML: $error - $errorDesc");
    header('Location: dashboard.php?status=ml_error&code=' . urlencode($error) . '#conexao');
    exit;
}
logMessage("Callback: Código de autorização recebido OK para SaaS User ID $saasUserIdFromSession.");

// --- 4. Trocar o código por tokens (Access Token e Refresh Token) ---
if (!defined('ML_TOKEN_URL') || !defined('ML_APP_ID') || !defined('ML_SECRET_KEY') || !defined('ML_REDIRECT_URI')) {
    logMessage("Erro Callback: Constantes de configuração ML ausentes.");
    header('Location: dashboard.php?status=ml_error&code=config_error#conexao');
    exit;
}
$tokenUrl = ML_TOKEN_URL;
$postData = [
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'client_id'     => ML_APP_ID,
    'client_secret' => ML_SECRET_KEY,
    'redirect_uri'  => ML_REDIRECT_URI // Essencial que seja EXATAMENTE a mesma
];
$headers = ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'];

logMessage("Callback: Trocando código por tokens na URL: $tokenUrl para SaaS User ID $saasUserIdFromSession");
$result = makeCurlRequest($tokenUrl, 'POST', $headers, $postData, false); // false = form-urlencoded

// --- 5. Validar Resposta da Troca de Tokens ---
if ($result['httpCode'] != 200 || !$result['is_json']) {
    logMessage("Erro Callback: Falha ao obter tokens do ML para SaaS User ID $saasUserIdFromSession. HTTP Code: {$result['httpCode']}. Response: " . json_encode($result['response']));
    $errorCode = 'token_fetch_failed_' . $result['httpCode'];
    header('Location: dashboard.php?status=ml_error&code=' . urlencode($errorCode) . '#conexao');
    exit;
}

$tokenData = $result['response'];
// Log apenas parcial dos tokens por segurança
$logTokenPreview = json_encode([
    'user_id' => $tokenData['user_id'] ?? 'N/A',
    'access_token_start' => isset($tokenData['access_token']) ? substr($tokenData['access_token'], 0, 8).'...' : 'N/A',
    'refresh_token_start' => isset($tokenData['refresh_token']) ? substr($tokenData['refresh_token'], 0, 8).'...' : 'N/A',
    'expires_in' => $tokenData['expires_in'] ?? 'N/A'
]);
logMessage("Callback: Resposta de Tokens recebida para SaaS User ID $saasUserIdFromSession: " . $logTokenPreview);

// Verificar campos essenciais na resposta
if (!isset($tokenData['access_token']) || !isset($tokenData['refresh_token']) || !isset($tokenData['user_id'])) {
    logMessage("Erro Callback: Resposta de token inválida do ML (campos faltando) para SaaS User ID $saasUserIdFromSession. Resp: " . json_encode($tokenData));
    header('Location: dashboard.php?status=ml_error&code=invalid_token_response#conexao');
    exit;
}

// --- 6. Extrair Dados e Salvar/Atualizar no Banco de Dados ---
$accessToken = $tokenData['access_token'];
$refreshToken = $tokenData['refresh_token'];
$mlUserId = $tokenData['user_id'];
$expiresIn = $tokenData['expires_in'] ?? 21600; // Padrão 6 horas se não vier
$tokenExpiresAt = (new DateTimeImmutable())->modify("+" . (int)$expiresIn . " seconds")->format('Y-m-d H:i:s');

try {
    // !! INSECURE PLACEHOLDER ENCRYPTION - SUBSTITUIR !!
    logMessage("Callback: Criptografando tokens (placeholder) para ML ID: $mlUserId / SaaS ID: $saasUserIdFromSession");
    $encryptedAccessToken = encryptData($accessToken);
    $encryptedRefreshToken = encryptData($refreshToken);
    // !! --------------------------------------------- !!

    $pdo = getDbConnection();

    // Usar INSERT ... ON DUPLICATE KEY UPDATE para tratar novos usuários e atualizações
    // A chave única deve ser em (saas_user_id, ml_user_id) ou apenas ml_user_id se um usuário ML só pode conectar a um SaaS user.
    // Assumindo UNIQUE KEY `idx_ml_user_id` (`ml_user_id`) na tabela `mercadolibre_users`
    $sql = "INSERT INTO mercadolibre_users (saas_user_id, ml_user_id, access_token, refresh_token, token_expires_at, is_active, created_at, updated_at)
            VALUES (:saas_user_id, :ml_user_id, :access_token, :refresh_token, :token_expires_at, TRUE, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                saas_user_id = VALUES(saas_user_id), -- Garante que se o ML user já existia mas com outro SaaS ID, ele seja atualizado para o SaaS ID atual
                access_token = VALUES(access_token),
                refresh_token = VALUES(refresh_token),
                token_expires_at = VALUES(token_expires_at),
                is_active = TRUE, -- Reativa a conexão se estava inativa
                updated_at = NOW()";

    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute([
        ':saas_user_id' => $saasUserIdFromSession,
        ':ml_user_id' => $mlUserId,
        ':access_token' => $encryptedAccessToken,  // Criptografado
        ':refresh_token' => $encryptedRefreshToken, // Criptografado
        ':token_expires_at' => $tokenExpiresAt
    ]);

    if ($success) {
        $action = ($stmt->rowCount() > 1) ? 'atualizados' : 'salvos'; // INSERT retorna 1, UPDATE retorna 1 ou 2 (MySQL)
        logMessage("Callback: Tokens $action com sucesso (usando cripto placeholder) para ML ID: $mlUserId (SaaS ID: $saasUserIdFromSession)");
        // Redireciona para o dashboard com sucesso, focando na aba de conexão
        header('Location: dashboard.php?status=ml_connected#conexao');
        exit;
    } else {
        logMessage("Erro Callback SQL: Falha ao executar save/update de tokens para SaaS ID $saasUserIdFromSession / ML ID $mlUserId.");
        header('Location: dashboard.php?status=ml_error&code=db_save_failed#conexao');
        exit;
    }

} catch (\PDOException $e) {
    logMessage("Erro Callback DB Exception para SaaS ID $saasUserIdFromSession: " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
    $errorCode = 'db_error';
    if ($e->getCode() == 23000) { // Código SQLSTATE para violação de chave única/primária
        logMessage("Erro Callback: Tentativa de inserir entrada duplicada para ML User ID $mlUserId ou SaaS User ID $saasUserIdFromSession, mas ON DUPLICATE KEY falhou?");
        $errorCode = 'db_duplicate_error'; // Pode indicar problema na definição da chave UNIQUE
    }
    header('Location: dashboard.php?status=ml_error&code=' . $errorCode . '#conexao');
    exit;
} catch (\Exception $e) { // Captura erros de criptografia também
    logMessage("Erro Callback Geral/Cripto Exception para SaaS ID $saasUserIdFromSession: " . $e->getMessage());
    header('Location: dashboard.php?status=ml_error&code=internal_error#conexao');
    exit;
}
?>



<?php
/**
 * Arquivo: oauth_start.php
 * Versão: v1.3 - Adiciona verificação de assinatura ativa no DB.
 * Descrição: Inicia o fluxo de autorização OAuth2 do Mercado Livre.
 *            Verifica se o usuário tem assinatura ativa (sessão ou DB) antes de permitir.
 *            Redireciona para a página de autorização do ML se a assinatura estiver ativa.
 */

// Includes Essenciais
require_once __DIR__ . '/config.php'; // Inicia sessão implicitamente
require_once __DIR__ . '/db.php';     // Necessário para verificar DB
require_once __DIR__ . '/includes/log_helper.php'; // Para logMessage()

// --- Proteção: Exige Login SaaS ---
if (!isset($_SESSION['saas_user_id'])) {
    logMessage("OAuth Start v1.3: Tentativa de acesso não autorizado (sem sessão SaaS). Redirecionando para login.");
    header('Location: login.php?error=unauthorized');
    exit;
}
$saasUserId = $_SESSION['saas_user_id'];

// *** Proteção de Assinatura Ativa (com verificação DB como fallback) ***
$subscriptionStatus = $_SESSION['subscription_status'] ?? null; // Pega status da sessão

// Se o status na SESSÃO não for explicitamente 'ACTIVE', verifica no DB
if ($subscriptionStatus !== 'ACTIVE') {
    $logMsg = "OAuth Start v1.3: Sessão não ativa ($subscriptionStatus) para SaaS ID $saasUserId. Verificando DB...";
    function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);

    try {
        $pdoCheck = getDbConnection();
        // Consulta apenas o status da assinatura no DB
        $stmtCheck = $pdoCheck->prepare("SELECT subscription_status FROM saas_users WHERE id = :id");
        $stmtCheck->execute([':id' => $saasUserId]);
        $dbStatusData = $stmtCheck->fetch();
        // Assume INACTIVE se usuário não for encontrado ou status for NULL/vazio no DB
        $dbStatus = $dbStatusData['subscription_status'] ?? 'INACTIVE';

        // Se o status no DB for ATIVO, atualiza a sessão e permite o acesso
        if ($dbStatus === 'ACTIVE') {
            $_SESSION['subscription_status'] = 'ACTIVE'; // Corrige a sessão
            $subscriptionStatus = 'ACTIVE'; // Atualiza a variável local
             $logMsg = "OAuth Start v1.3: DB está ATIVO para SaaS ID $saasUserId. Sessão atualizada. Prosseguindo com OAuth...";
             function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
             // Permite que o script continue para gerar o link OAuth
        } else {
            // Se o DB também confirma que não está ativo, redireciona para billing
            $logMsg = "OAuth Start v1.3: Assinatura NÃO está ATIVA no DB ($dbStatus) para SaaS ID $saasUserId. Redirecionando para billing.";
             function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
             // Redireciona para billing informando que a assinatura está inativa
            header('Location: billing.php?billing_status=inactive');
            exit;
        }
    } catch (\Exception $e) {
         // Em caso de erro ao verificar o DB, redireciona para billing por segurança
         $logMsg = "OAuth Start v1.3: Erro CRÍTICO ao verificar DB status para $saasUserId: " . $e->getMessage() . ". Redirecionando para billing.";
         function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
         // Limpa status da sessão para evitar loops se o erro DB persistir
         unset($_SESSION['subscription_status']);
         header('Location: billing.php?error=db_check_failed'); // Informa erro na checagem
         exit;
    }
}
// *** FIM PROTEÇÃO ASSINATURA ***

// --- Se chegou aqui, está logado E assinatura está ATIVA ---
logMessage("[OAuth Start v1.3] Iniciando fluxo OAuth2 para SaaS User ID: $saasUserId (Assinatura Ativa)");

// --- Gerar o parâmetro 'state' para segurança (CSRF Protection) ---
// O state contém um valor aleatório (nonce), o ID do usuário SaaS e um timestamp
// É codificado para ser passado na URL
try {
    $statePayload = [
        'nonce' => bin2hex(random_bytes(16)), // String aleatória forte
        'uid'   => $saasUserId,               // ID do usuário logado
        'ts'    => time()                     // Timestamp da geração
    ];
    // Codifica o payload como JSON e depois em Base64 para segurança na URL
    $state = base64_encode(json_encode($statePayload));
} catch (Exception $e) {
     // Erro na geração de bytes aleatórios (raro, mas possível)
     logMessage("OAuth Start v1.3: ERRO CRÍTICO ao gerar state para SaaS User ID $saasUserId: " . $e->getMessage());
     // Redireciona de volta para o dashboard com mensagem de erro
     header('Location: dashboard.php?status=ml_error&code=state_gen_failed#conexao');
     exit;
}

// Armazena o state gerado na sessão para comparar no callback
$_SESSION['oauth_state_expected'] = $state;
logMessage("[OAuth Start v1.3] State CSRF gerado e salvo na sessão para SaaS User ID: $saasUserId");

// --- Montar a URL de autorização do Mercado Livre ---
// Verifica se as constantes de configuração do ML estão definidas
if (!defined('ML_APP_ID') || !defined('ML_REDIRECT_URI') || !defined('ML_AUTH_URL')) {
     logMessage("OAuth Start v1.3: ERRO CRÍTICO - Constantes ML (ML_APP_ID, ML_REDIRECT_URI, ML_AUTH_URL) não definidas em config.php.");
     header('Location: dashboard.php?status=ml_error&code=config_error#conexao');
     exit;
}

// Parâmetros para a URL de autorização
$authParams = [
    'response_type' => 'code',          // Tipo de fluxo OAuth2 (Authorization Code)
    'client_id'     => ML_APP_ID,       // ID da sua aplicação no ML
    'redirect_uri'  => ML_REDIRECT_URI, // URI para onde o ML redirecionará após autorização
    'state'         => $state,          // Parâmetro de segurança CSRF
    'scope'         => 'read write offline_access' // Escopos solicitados (ler/escrever dados, obter refresh token)
];

// Constrói a URL final
$authUrl = ML_AUTH_URL . '?' . http_build_query($authParams);

// --- Redirecionar o Usuário ---
logMessage("[OAuth Start v1.3] Redirecionando SaaS User ID $saasUserId para URL de Autorização ML: " . ML_AUTH_URL . "..."); // Loga sem os parâmetros sensíveis completos
header('Location: ' . $authUrl);
exit; // Finaliza o script após o redirecionamento
?>



<?php
/**
 * Arquivo: poll_questions.php
 * Versão: v20 - Corrige includes para estrutura confirmada.
 *
 * Descrição:
 * Script CRON que atua como:
 * 1. FALLBACK: Busca perguntas recentes perdidas pelo webhook.
 * 2. GERENCIADOR DE TIMEOUT: Aciona IA para perguntas com timeout.
 */

// Configurações de execução
set_time_limit(900); // 15 minutos
date_default_timezone_set('America/Sao_Paulo');

// --- Includes Essenciais ---
// Inclui config e db da raiz do projeto (mesmo diretório)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Inclui helpers da subpasta 'includes'
require_once __DIR__ . '/includes/log_helper.php';        // logMessage
require_once __DIR__ . '/includes/db_interaction.php';  // getQuestionLogStatus, upsertQuestionLog
require_once __DIR__ . '/includes/ml_api.php';            // Funções ML
require_once __DIR__ . '/includes/evolution_api.php';     // sendWhatsAppImageNotification
require_once __DIR__ . '/includes/core_logic.php';        // triggerAiForQuestion

// Verifica se logMessage foi definida
if (!function_exists('logMessage')) {
    function logMessage(string $message): void { error_log($message); }
}

logMessage("==== [CRON START v20] Iniciando ciclo Híbrido (Fallback + Timeout) ====");

try {
    $pdo = getDbConnection();
    $globalNow = new DateTimeImmutable();

    // 1. Buscar Conexões Ativas
    logMessage("[CRON v20] Buscando conexões ativas...");
    // Query SQL igual à v19
    $sql_connections = "SELECT mlu.id AS connection_id, mlu.saas_user_id, mlu.ml_user_id,
                               mlu.access_token, mlu.refresh_token, mlu.token_expires_at,
                               su.whatsapp_jid, su.email AS saas_user_email
                        FROM mercadolibre_users mlu
                        JOIN saas_users su ON mlu.saas_user_id = su.id
                        WHERE mlu.is_active = TRUE AND su.is_saas_active = TRUE
                        ORDER BY mlu.updated_at ASC"; // Processa menos recentes primeiro
    $stmt_connections = $pdo->query($sql_connections);
    $activeConnections = $stmt_connections->fetchAll();

    if (!$activeConnections) {
        logMessage("[CRON v20 INFO] Nenhuma conexão ativa válida encontrada.");
        logMessage("==== [CRON END v20] Ciclo finalizado sem usuários ativos ====");
        exit;
    }
    $totalActiveConnections = count($activeConnections);
    logMessage("[CRON v20 INFO] Conexões ativas encontradas: " . $totalActiveConnections);

    // 2. Loop por cada conexão ativa
    $processedConnectionCount = 0;
    foreach ($activeConnections as $conn) {
        $processedConnectionCount++;
        $connectionIdInDb = $conn['connection_id'];
        $mlUserId = $conn['ml_user_id'];
        $saasUserId = $conn['saas_user_id'];
        $saasUserEmail = $conn['saas_user_email'];
        $whatsappTargetJid = $conn['whatsapp_jid'];
        $dbAccessTokenEncrypted = $conn['access_token'];
        $dbRefreshTokenEncrypted = $conn['refresh_token'];
        $tokenExpiresAtStr = $conn['token_expires_at'];
        $currentAccessToken = null; // Reset a cada loop de usuário

        logMessage("--> [ML $mlUserId / SaaS $saasUserId ($processedConnectionCount/$totalActiveConnections)] Processando...");

        try {
            // --- 2.1. Refresh Token ---
            logMessage("    [ML $mlUserId] Verificando token...");
            try {
                 if (empty($tokenExpiresAtStr)) { throw new Exception("Data expiração vazia DB."); }
                 $tokenExpiresAt = new DateTimeImmutable($tokenExpiresAtStr);
                 if ($globalNow >= $tokenExpiresAt->modify("-10 minutes")) {
                     logMessage("    [ML $mlUserId] REFRESH NECESSÁRIO...");
                     $decryptedRefreshToken = decryptData($dbRefreshTokenEncrypted); // Usa Defuse
                     $refreshResult = refreshMercadoLibreToken($decryptedRefreshToken);
                     if ($refreshResult['httpCode'] == 200 && isset($refreshResult['response']['access_token'])) {
                         $newData = $refreshResult['response'];
                         $currentAccessToken = $newData['access_token'];
                         $newRefreshToken = $newData['refresh_token'] ?? $decryptedRefreshToken; // Usa novo se vier
                         $newExpiresIn = $newData['expires_in'] ?? 21600;
                         $newExpAt = $globalNow->modify("+" . (int)$newExpiresIn . " seconds")->format('Y-m-d H:i:s');
                         // Criptografa antes de salvar
                         $encAT = encryptData($currentAccessToken);
                         $encRT = encryptData($newRefreshToken);
                         $upSql = "UPDATE mercadolibre_users SET access_token = :at, refresh_token = :rt, token_expires_at = :exp, updated_at = NOW() WHERE id = :id";
                         $upStmt = $pdo->prepare($upSql);
                         if($upStmt->execute([':at'=>$encAT, ':rt'=>$encRT,':exp'=>$newExpAt,':id'=>$connectionIdInDb])) {
                             logMessage("    [ML $mlUserId] Refresh OK, DB atualizado.");
                         } else {
                             logMessage("    [ML $mlUserId] ERRO SQL ao salvar token pós-refresh.");
                             continue; // Pula para próximo usuário
                         }
                     } else {
                         $errorResponse = json_encode($refreshResult['response'] ?? $refreshResult['error'] ?? 'N/A');
                         logMessage("    [ML $mlUserId] ERRO FATAL no refresh API ML. Desativando conexão. Code: {$refreshResult['httpCode']}. Resp: $errorResponse");
                         @$pdo->exec("UPDATE mercadolibre_users SET is_active=FALSE, updated_at = NOW() WHERE id=".$connectionIdInDb);
                         // Loga erro genérico para o usuário
                         @upsertQuestionLog(0, $mlUserId, 'N/A', 'ERROR', null, null, null, 'Falha refresh token API ML (CRON)', $saasUserId);
                         continue; // Pula para próximo usuário
                     }
                 } else {
                     logMessage("    [ML $mlUserId] Token válido, descriptografando...");
                     $currentAccessToken = decryptData($dbAccessTokenEncrypted); // Usa Defuse
                 }
            } catch (Exception $e) {
                 logMessage("    [ML $mlUserId] ERRO validação/refresh/decrypt token: ".$e->getMessage());
                 @upsertQuestionLog(0, $mlUserId, 'N/A', 'ERROR', null, null, null, 'Erro token ML (CRON): '.substr($e->getMessage(),0,150), $saasUserId);
                 continue; // Pula para próximo usuário
            }
            // Verificação final do token
            if (empty($currentAccessToken)) {
                logMessage("    [ML $mlUserId] ERRO INTERNO INESPERADO: Access token vazio após lógica. Pulando usuário.");
                continue;
            }
            logMessage("    [ML $mlUserId] Token pronto.");
            // --- Fim Refresh Token ---


            // --- 2.2. [FASE 1 - FALLBACK] Buscar Perguntas RECENTES Perdidas ---
            // (Lógica de paginação e processamento igual à v19, usando as funções corretas)
            logMessage("    [ML $mlUserId - Fallback] Buscando perguntas recentes (últimos 7 dias) não registradas...");
            $daysToLookBackFallback = 7;
            $dateFromFilterFallback = $globalNow->modify("-{$daysToLookBackFallback} days")->format(DateTime::ATOM);
            $limitPerPageFallback = 50; $offsetFallback = 0; $processedInFallback = 0;
            $maxPagesFallback = 5; $currentPageFallback = 0;

            do {
                $currentPageFallback++;
                logMessage("      [ML $mlUserId Fallback Page $currentPageFallback] Buscando...");
                $questionsResult = getMercadoLibreQuestions($mlUserId, $currentAccessToken, $dateFromFilterFallback, $limitPerPageFallback, $offsetFallback);
                $returnedQuestions = []; $returnedCount = 0;

                if ($questionsResult['httpCode'] == 200 && $questionsResult['is_json'] && isset($questionsResult['response']['questions'])) {
                    $returnedQuestions = $questionsResult['response']['questions'];
                    $returnedCount = count($returnedQuestions);
                    logMessage("      [ML $mlUserId Fallback Page $currentPageFallback] Recebidas $returnedCount perguntas.");

                    if ($returnedCount > 0) {
                        foreach ($returnedQuestions as $question) {
                             $questionId = $question['id'] ?? null; if (!$questionId) continue; $questionId = (int)$questionId;
                             $logStatus = getQuestionLogStatus($questionId);
                             if (!$logStatus) {
                                 // Pergunta NÃO está no log -> Processar (igual ao webhook)
                                 $processedInFallback++;
                                 logMessage("        [QID $questionId / Fallback] Pergunta RECENTE encontrada e NÃO está no log. Processando...");
                                 $itemId = $question['item_id'] ?? 'N/A'; $questionTextRaw = $question['text'] ?? '';
                                 if (empty(trim($questionTextRaw)) || empty($itemId) || $itemId === 'N/A') { logMessage("          [QID $questionId / Fallback] ERRO: Dados inválidos da pergunta. Pulando."); continue; }
                                 if (empty($whatsappTargetJid)) { logMessage("          [QID $questionId / Fallback] Sem JID para notificar. Marcando PENDING."); upsertQuestionLog($questionId, $mlUserId, $itemId, 'PENDING_WHATSAPP', $questionTextRaw, null, null, 'JID não config (Detectado CRON)', $saasUserId); continue; }

                                 // Buscar item e enviar notificação...
                                 logMessage("          [QID $questionId / Fallback] Buscando item $itemId...");
                                 $itemTitle = '[Prod não encontrado]'; $itemImageUrl = null;
                                 $itemResult = getMercadoLibreItemDetails($itemId, $currentAccessToken);
                                 if ($itemResult['httpCode'] == 200 && $itemResult['is_json']) { $itemData = $itemResult['response']; $itemTitle = $itemData['title'] ?? $itemTitle; $itemImageUrl = $itemData['pictures'][0]['secure_url'] ?? $itemData['thumbnail'] ?? null; }
                                 else { logMessage("          [QID $questionId / Fallback] WARN: Falha detalhes item $itemId."); }

                                 // Montar caption... (igual ao webhook)
                                 $timeoutMinutes = defined('AI_FALLBACK_TIMEOUT_MINUTES') ? AI_FALLBACK_TIMEOUT_MINUTES : 10;
                                 $captionText = "🔔 *Nova pergunta no Mercado Livre:*\n\n```" . htmlspecialchars(trim($questionTextRaw)) . "```\n\n1️⃣ *Responder Manualmente:*\n   _(Responda esta mensagem com o texto)_.\n2️⃣ *Usar Resposta da IA:*\n   _(Responda esta mensagem apenas com o número `2`)_.\n\n⏳ A IA responderá automaticamente em *{$timeoutMinutes} minutos* se não houver ação.\n\n_(Ref: {$questionId} | Item: {$itemId})_";

                                 // Enviar notificação...
                                 $whatsappMessageId = null;
                                 if ($itemImageUrl && filter_var($itemImageUrl, FILTER_VALIDATE_URL)) {
                                     logMessage("          [QID $questionId / Fallback] Enviando notificação COM IMAGEM para $whatsappTargetJid...");
                                     $whatsappMessageId = sendWhatsAppImageNotification( $whatsappTargetJid, $itemImageUrl, $captionText );
                                 } else {
                                     logMessage("          [QID $questionId / Fallback] Enviando notificação SEM IMAGEM para $whatsappTargetJid...");
                                     $whatsappMessageId = sendWhatsAppNotification( $whatsappTargetJid, $captionText );
                                 }

                                 // Registrar no log...
                                 $initialStatus = $whatsappMessageId ? 'AWAITING_TEXT_REPLY' : 'PENDING_WHATSAPP';
                                 $sentTimestamp = $whatsappMessageId ? $globalNow->format('Y-m-d H:i:s') : null;
                                 $errorMsg = ($initialStatus === 'PENDING_WHATSAPP') ? 'Falha envio Wpp via CRON Fallback' : null;
                                 logMessage("          [QID $questionId / Fallback] Resultado envio Wpp: " . ($whatsappMessageId ? "Sucesso (MsgID: $whatsappMessageId)" : "Falha"));
                                 $upsertOK = upsertQuestionLog($questionId, $mlUserId, $itemId, $initialStatus, $questionTextRaw, $sentTimestamp, null, $errorMsg, $saasUserId, null, $whatsappMessageId);
                                 if($upsertOK){ logMessage("          [QID $questionId / Fallback] UPSERT LOG OK (Status: $initialStatus)."); } else { logMessage("          [QID $questionId / Fallback] ERRO UPSERT LOG (Status: $initialStatus)!"); }
                                 sleep(mt_rand(1, 2)); // Pausa
                             }
                             // else { logMessage("        [QID $questionId / Fallback] Já existe no log. Ignorando."); }
                        }
                        $offsetFallback += $returnedCount;
                    }
                } else {
                    logMessage("      [ML $mlUserId Fallback Page $currentPageFallback] ERRO ao buscar perguntas recentes. HTTP: {$questionsResult['httpCode']}. Error: " . ($questionsResult['error'] ?? 'N/A'));
                     if ($questionsResult['httpCode'] == 403 || $questionsResult['httpCode'] == 401) { logMessage("      [ML $mlUserId Fallback Page $currentPageFallback] ERRO 401/403. Desativando conexão."); @$pdo->exec("UPDATE mercadolibre_users SET is_active=FALSE, updated_at = NOW() WHERE id=".$connectionIdInDb); }
                    $returnedCount = 0; // Força saída do loop
                }
            } while ($returnedCount === $limitPerPageFallback && $currentPageFallback < $maxPagesFallback);

            if ($currentPageFallback >= $maxPagesFallback && $returnedCount === $limitPerPageFallback) { logMessage("      [ML $mlUserId Fallback] ATENÇÃO: Limite máximo de páginas ($maxPagesFallback) atingido."); }
            logMessage("    [ML $mlUserId - Fallback] Processadas $processedInFallback perguntas recentes que não estavam no log.");
            // --- Fim Fase 1 (Fallback) ---


            // --- 2.3. [FASE 2 - TIMEOUT] Verificar Timeout e Acionar IA ---
            // (Lógica igual à v19, usando triggerAiForQuestion)
            $aiTimeoutMinutes = defined('AI_FALLBACK_TIMEOUT_MINUTES') ? AI_FALLBACK_TIMEOUT_MINUTES : 10;
            logMessage("    [ML $mlUserId - Timeout Check] Verificando perguntas 'AWAITING_TEXT_REPLY' com timeout > $aiTimeoutMinutes min...");
            $timeoutThreshold = $globalNow->modify("-" . $aiTimeoutMinutes . " minutes")->format('Y-m-d H:i:s');
            $sqlTimeout = "SELECT ml_question_id FROM question_processing_log
                           WHERE ml_user_id = :ml_uid
                             AND status = 'AWAITING_TEXT_REPLY'
                             AND sent_to_whatsapp_at IS NOT NULL
                             AND sent_to_whatsapp_at <= :limit
                           ORDER BY sent_to_whatsapp_at DESC"; // Processa mais recentes primeiro
            $stmtTimeout = $pdo->prepare($sqlTimeout);
            $stmtTimeout->execute([':ml_uid' => $mlUserId, ':limit' => $timeoutThreshold]);
            $pendingTimeoutQuestions = $stmtTimeout->fetchAll(PDO::FETCH_COLUMN); // Pega só os IDs

            if (!empty($pendingTimeoutQuestions)) {
                $countTimeout = count($pendingTimeoutQuestions);
                logMessage("    [ML $mlUserId - Timeout Check] Encontradas $countTimeout perguntas com timeout para IA: " . implode(', ', $pendingTimeoutQuestions));
                $processedAiCount = 0;
                $maxAiPerCron = 20; // Limite de segurança

                foreach ($pendingTimeoutQuestions as $questionIdToProcess) {
                    if ($processedAiCount >= $maxAiPerCron) {
                        logMessage("    [ML $mlUserId - Timeout Check] Limite processamento IA por usuário ($maxAiPerCron) atingido neste ciclo.");
                        break;
                    }
                    $questionIdToProcess = (int)$questionIdToProcess;
                    logMessage("      [QID $questionIdToProcess / Timeout] Acionando IA via core_logic...");

                    // Chama a função centralizada que lida com tudo (busca log, refresh token, IA, post, logs, notificação)
                    $aiSuccess = triggerAiForQuestion($questionIdToProcess);

                    if ($aiSuccess) {
                        logMessage("      [QID $questionIdToProcess / Timeout] triggerAiForQuestion retornou SUCESSO.");
                        $processedAiCount++;
                    } else {
                        logMessage("      [QID $questionIdToProcess / Timeout] triggerAiForQuestion retornou FALHA (ver logs anteriores).");
                        // O erro já foi logado e status atualizado dentro de triggerAiForQuestion
                    }
                    sleep(mt_rand(3, 6)); // Pausa maior entre chamadas de IA
                }
                 logMessage("    [ML $mlUserId - Timeout Check] Processadas $processedAiCount perguntas via IA neste ciclo.");
            } else {
                logMessage("    [ML $mlUserId - Timeout Check] Nenhuma pergunta encontrada com timeout para IA.");
            }
            // --- Fim Fase 2 (Timeout) ---

        } catch (\Exception $userProcessingError) {
            // Captura erros inesperados durante o processamento de um usuário específico
            $errorFile = basename($userProcessingError->getFile()); $errorLine = $userProcessingError->getLine();
            logMessage("!! ERRO GERAL INESPERADO processando ML ID $mlUserId ($errorFile Linha $errorLine): " . $userProcessingError->getMessage());
            // Tenta logar um erro genérico para o usuário, se possível
            @upsertQuestionLog(0, $mlUserId, 'N/A', 'ERROR', null, null, null, 'Exceção CRON usuário: '.substr($userProcessingError->getMessage(),0,150), $saasUserId);
        } finally {
             // Pausa curta entre processar diferentes usuários
             sleep(mt_rand(1, 2));
        }

    } // Fim foreach $activeConnections

} catch (\PDOException $dbErr) {
    logMessage("!!!! ERRO FATAL CRON v20 (DB Connection/Query): " . $dbErr->getMessage());
} catch (\Throwable $e) {
    $errorFile = basename($e->getFile()); $errorLine = $e->getLine();
    logMessage("!!!! ERRO FATAL CRON v20 (Geral - $errorFile Linha $errorLine): " . $e->getMessage());
}

logMessage("==== [CRON END v20] Ciclo Híbrido finalizado ====\n");
// (Não coloque a tag ?> de fechamento no final)






<?php
/**
 * Arquivo: register.php
 * Versão: v5.4 - Adiciona Auto-Login e redireciona para billing.php
 * Descrição: Página de cadastro. Cria usuário local, cliente Asaas,
 *            inicia a sessão do usuário e redireciona para billing.
 */

// Includes Essenciais
require_once __DIR__ . '/config.php'; // Inicia sessão implicitamente
require_once __DIR__ . '/db.php';     // Para getDbConnection()
require_once __DIR__ . '/includes/log_helper.php'; // Para logMessage()
require_once __DIR__ . '/includes/asaas_api.php'; // Para createAsaasCustomer()

// --- Inicialização ---
$errors = [];
// Preenche $formData com valores POST ou vazios para repopular o formulário
$formData = [
    'email' => $_POST['email'] ?? '',
    'whatsapp_number' => $_POST['whatsapp_number'] ?? '',
    'name' => $_POST['name'] ?? '',
    'cpf_cnpj' => $_POST['cpf_cnpj'] ?? ''
];
$error_class = 'bg-red-100 dark:bg-red-900 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300'; // Classe Tailwind para erros

// --- Redirecionamento se já logado ---
// Se o usuário já tem uma sessão ativa, redireciona para evitar recadastro
if (isset($_SESSION['saas_user_id'])) {
    // Verifica o status da assinatura na sessão para decidir o destino
    if (isset($_SESSION['subscription_status']) && $_SESSION['subscription_status'] === 'ACTIVE') {
        header('Location: dashboard.php'); // Se ativo, vai pro dashboard
    } else {
        header('Location: billing.php'); // Se não ativo (ou status desconhecido), vai pra billing
    }
    exit;
}

// --- Processamento do Formulário de Cadastro ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Sanitização e validação dos inputs
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $whatsapp_number_raw = $_POST['whatsapp_number'] ?? '';
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $cpf_cnpj_raw = $_POST['cpf_cnpj'] ?? '';

    // Limpa CPF/CNPJ para validação e armazenamento
    $cpf_cnpj_cleaned = preg_replace('/[^0-9]/', '', $cpf_cnpj_raw);

    // Validações dos campos
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "📧 Formato de e-mail inválido.";
    }
    if (empty($name)) {
        $errors[] = "👤 Nome é obrigatório.";
    }
    if (empty($cpf_cnpj_cleaned)) {
        $errors[] = "📄 CPF/CNPJ é obrigatório.";
    } elseif (strlen($cpf_cnpj_cleaned) != 11 && strlen($cpf_cnpj_cleaned) != 14) {
        $errors[] = "📄 CPF/CNPJ inválido (deve conter 11 ou 14 dígitos).";
        // TODO: Implementar validação de dígito verificador para CPF/CNPJ aqui para maior robustez.
    }
    if (empty($password)) {
        $errors[] = "🔒 Senha é obrigatória.";
    } elseif (strlen($password) < 8) {
        $errors[] = "📏 Senha deve ter no mínimo 8 caracteres.";
    } elseif ($password !== $password_confirm) {
        $errors[] = "👯 As senhas não coincidem.";
    }

    // Validação e formatação do WhatsApp
    $whatsapp_jid_to_save = null;
    $jid_cleaned = preg_replace('/[^\d]/', '', $whatsapp_number_raw); // Remove não-dígitos
    if (empty($jid_cleaned)) {
        $errors[] = "📱 Número WhatsApp é obrigatório.";
    } elseif (preg_match('/^\d{10,11}$/', $jid_cleaned)) { // Valida DDD + Número (10 ou 11 dígitos)
        $whatsapp_jid_to_save = "55" . $jid_cleaned . "@s.whatsapp.net"; // Formato JID Brasil
    } else {
        $errors[] = "📱 Formato do WhatsApp inválido (DDD + Número, 10 ou 11 dígitos).";
    }

    // Se não houver erros de validação, tenta processar o cadastro
    if (empty($errors)) {
        $pdo = null;
        try {
            $pdo = getDbConnection();
            $pdo->beginTransaction(); // Inicia transação DB

            // 1. Verifica se o email já existe no sistema local
            $stmtCheck = $pdo->prepare("SELECT id FROM saas_users WHERE email = :email LIMIT 1");
            $stmtCheck->execute([':email' => $email]);
            if ($stmtCheck->fetch()) {
                $errors[] = "📬 Este e-mail já está cadastrado em nosso sistema. Tente fazer login.";
                $pdo->rollBack(); // Cancela transação se email já existe
            } else {
                // 2. Cria ou busca o cliente correspondente no Asaas
                 logMessage("[Register v5.4] Verificando/Criando cliente Asaas para: $email / $cpf_cnpj_cleaned");
                 $asaasCustomer = createAsaasCustomer($name, $email, $cpf_cnpj_cleaned, $jid_cleaned); // Passa o número limpo

                 // Verifica se a criação/busca no Asaas foi bem-sucedida
                 if (!$asaasCustomer || !isset($asaasCustomer['id'])) {
                     // Lança uma exceção para ser capturada pelo catch geral
                     throw new Exception("Falha ao criar ou buscar cliente na plataforma de pagamento Asaas. Verifique os logs da API Asaas.");
                 }
                 $asaasCustomerId = $asaasCustomer['id'];
                 logMessage("[Register v5.4] Cliente Asaas OK (ID: $asaasCustomerId). Criando usuário local...");

                // 3. Cria o hash da senha
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                if (!$password_hash) {
                    // Falha crítica ao gerar hash
                    throw new Exception("Erro crítico ao gerar hash da senha.");
                }

                // 4. Insere o usuário no banco de dados local
                $sqlInsert = "INSERT INTO saas_users (email, password_hash, whatsapp_jid, name, cpf_cnpj, asaas_customer_id, subscription_status, is_saas_active, created_at, updated_at)
                              VALUES (:email, :pwd, :jid, :name, :cpf_cnpj, :asaas_id, 'PENDING', FALSE, NOW(), NOW())";
                $stmtInsert = $pdo->prepare($sqlInsert);
                $successLocal = $stmtInsert->execute([
                    ':email' => $email,
                    ':pwd' => $password_hash,
                    ':jid' => $whatsapp_jid_to_save,
                    ':name' => $name,
                    ':cpf_cnpj' => $cpf_cnpj_cleaned, // Salva só os números
                    ':asaas_id' => $asaasCustomerId  // Vincula ao ID do cliente Asaas
                ]);
                $localUserId = $pdo->lastInsertId(); // Pega o ID do usuário recém-criado

                // Verifica se a inserção local foi bem-sucedida
                if ($successLocal && $localUserId) {

                    // Opcional: Atualizar a referência externa no cliente Asaas com o ID local
                    // Isso pode ser útil para futuras consultas. Exigiria uma função updateAsaasCustomer.
                    // updateAsaasCustomer($asaasCustomerId, ['externalReference' => $localUserId]);
                    // logMessage("[Register v5.4] Referência externa atualizada no Asaas para $asaasCustomerId com local ID $localUserId.");

                    $pdo->commit(); // Confirma a transação no banco de dados ANTES de iniciar a sessão
                    logMessage("[Register v5.4] Usuário $email (ID: $localUserId) criado com sucesso no DB local.");

                    // **** NOVO: INICIAR SESSÃO AUTOMATICAMENTE ****
                    session_regenerate_id(true); // Regenera o ID da sessão por segurança
                    $_SESSION['saas_user_id'] = $localUserId;
                    $_SESSION['saas_user_email'] = $email;
                    $_SESSION['subscription_status'] = 'PENDING'; // Define o status inicial na sessão
                    $_SESSION['asaas_customer_id'] = $asaasCustomerId; // Guarda o ID Asaas na sessão
                    logMessage("[Register v5.4] Sessão iniciada para usuário $localUserId.");

                    // **** NOVO: REDIRECIONAR PARA BILLING APÓS CADASTRO ****
                    // Envia para a página de billing com uma mensagem de sucesso
                    header('Location: billing.php?status=registered');
                    exit; // Finaliza o script após o redirecionamento
                    // **** FIM DAS MUDANÇAS ****

                } else {
                    // Falha ao inserir o usuário localmente, mesmo após sucesso/busca no Asaas
                    throw new Exception("Falha ao salvar usuário local no banco de dados após criar/buscar cliente Asaas.");
                }
            } // Fim else (email não existe localmente)
        } catch (\PDOException | \Exception $e) {
            // Rollback em caso de erro durante a transação
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            logMessage("[Register v5.4] Erro CRÍTICO cadastro $email: " . $e->getMessage());
            // Define mensagem de erro genérica para o usuário
             if (strpos($e->getMessage(), 'cliente na plataforma de pagamento') !== false) {
                 $errors[] = "⚠️ Erro ao comunicar com o sistema de pagamento. Verifique os dados ou tente mais tarde.";
             } else {
                 $errors[] = "⚙️ Erro inesperado durante o cadastro. Por favor, tente novamente ou contate o suporte.";
             }
             // Não redireciona, permite que a página seja recarregada com os erros
        }
    } // Fim if empty($errors)
}
?>
<!DOCTYPE html>
<html lang="pt-br" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro - Meli AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <style> body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; } </style>
    <!-- Incluir JS para máscara de CPF/CNPJ aqui se desejar -->
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 transition-colors duration-300">
    <section class="flex flex-col items-center justify-center min-h-screen py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full bg-white dark:bg-gray-800 shadow-md rounded-lg p-8 space-y-6">
            <h1 class="text-3xl font-bold text-center text-gray-900 dark:text-white">🚀 Criar Conta Meli AI</h1>

            <!-- Exibição de Erros -->
            <?php if (!empty($errors)): ?>
                <div class="<?php echo $error_class; ?> px-4 py-3 rounded-md text-sm mb-4" role="alert">
                    <p class="font-bold mb-2">🔔 Corrija os seguintes erros:</p>
                    <ul class="list-disc list-inside space-y-1">
                        <?php foreach ($errors as $e): ?>
                            <li><?php echo htmlspecialchars($e); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Formulário de Cadastro -->
            <form action="register.php" method="POST" novalidate class="space-y-4">
                 <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">👤 Nome Completo</label>
                    <input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                           type="text" id="name" name="name" placeholder="Seu nome completo" required
                           value="<?php echo htmlspecialchars($formData['name']); ?>" autocomplete="name">
                </div>
                 <div>
                    <label for="cpf_cnpj" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">📄 CPF ou CNPJ</label>
                    <input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                           type="text" id="cpf_cnpj" name="cpf_cnpj" placeholder="Apenas números" required
                           value="<?php echo htmlspecialchars($formData['cpf_cnpj']); ?>" >
                     <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Necessário para pagamento e nota fiscal.</p>
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">📧 E-mail</label>
                    <input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                           type="email" id="email" name="email" placeholder="Seu melhor e-mail" required
                           value="<?php echo htmlspecialchars($formData['email']); ?>" autocomplete="email">
                </div>
                <div>
                    <label for="whatsapp_number" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        📱 Seu WhatsApp <span class="text-gray-500 dark:text-gray-400 font-normal">(Obrigatório)</span>
                    </label>
                    <input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                           type="tel" id="whatsapp_number" name="whatsapp_number"
                           value="<?php echo htmlspecialchars($formData['whatsapp_number']); ?>" placeholder="Ex: 11987654321" pattern="\d{10,11}" title="Informe DDD + Número (10 ou 11 dígitos)" required autocomplete="tel">
                     <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Para receber notificações.</p>
                </div>
                 <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">🔒 Crie uma Senha</label>
                    <input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                           type="password" id="password" name="password" placeholder="Mínimo 8 caracteres" required minlength="8" autocomplete="new-password">
                </div>
                 <div>
                    <label for="password_confirm" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">🔑 Confirme a Senha</label>
                    <input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                           type="password" id="password_confirm" name="password_confirm" placeholder="Repita a senha criada" required autocomplete="new-password">
                </div>
                <div>
                    <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-base font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                        Criar Conta e Ir para Pagamento
                    </button>
                </div>
            </form>

             <p class="text-sm text-center text-gray-500 dark:text-gray-400">
                 Já tem uma conta? <a href="login.php" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Faça Login</a>.
             </p>
             <p class="text-center mt-2 text-xs text-gray-500 dark:text-gray-400">
                 <a href="index.php" class="hover:underline">← Voltar</a>
             </p>
        </div>

         <footer class="mt-8 text-center text-sm text-gray-500 dark:text-gray-400">
             <p>© <?php echo date('Y'); ?> Meli AI</p>
         </footer>
    </section>
</body>
</html>



/**
 * Arquivo: style.css
 * VersÃ£o: v2.0 - Estilos Complementares para Meli AI (Tailwind CDN)
 * DescriÃ§Ã£o: Define estilos base mÃ­nimos, fontes e pequenas melhorias
 *            que complementam o Tailwind CSS.
 */

/* --- 1. CSS Variables (Optional but good practice) --- */
:root {
    /* Define base colors - Tailwind's dark: prefix will override where used */
    --color-text-base: #1f2937;       /* gray-800 */
    --color-text-muted: #6b7280;      /* gray-500 */
    --color-bg-base: #f9fafb;         /* gray-50 */
    --color-link: #2563eb;            /* blue-600 */
    --color-link-hover: #1d4ed8;      /* blue-700 */

    /* Define fonts */
    --font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
}

@media (prefers-color-scheme: dark) {
    :root {
        --color-text-base: #f3f4f6;       /* gray-100 */
        --color-text-muted: #9ca3af;      /* gray-400 */
        --color-bg-base: #111827;         /* gray-900 */
        --color-link: #60a5fa;            /* blue-400 */
        --color-link-hover: #93c5fd;      /* blue-300 */
    }
}

/* --- 2. Base HTML & Body Styles --- */
html {
  scroll-behavior: smooth; /* Rolagem suave para links internos (#) */
}

body {
  font-family: var(--font-sans);
  color: var(--color-text-base);
  background-color: var(--color-bg-base);
  /* TransiÃ§Ã£o suave ao mudar tema claro/escuro */
  transition: background-color 0.3s ease, color 0.3s ease;
  /* Garante que o body ocupe pelo menos a altura da tela */
  min-height: 100vh;
   /* Usa flexbox para empurrar o footer para baixo */
  display: flex;
  flex-direction: column;
}

/* --- 3. Base Link Styles (Tailwind often overrides this with specific classes) --- */
a {
  color: var(--color-link);
  text-decoration: none; /* Remover sublinhado padrÃ£o */
  transition: color 0.2s ease;
}
a:hover {
  color: var(--color-link-hover);
  text-decoration: underline; /* Adicionar sublinhado no hover */
}

/* --- 4. Specific Component Enhancements (Keep Minimal) --- */

/* Empurrar o footer para baixo */
/* Aplique a classe 'main-content' ao container principal de cada pÃ¡gina */
.main-content {
    flex-grow: 1;
}

/* Custom Scrollbar for elements with class 'custom-scrollbar' */
/* Apply this class e.g., to '.log-container' in dashboard.php */
.custom-scrollbar::-webkit-scrollbar {
  width: 6px;
  height: 6px; /* For horizontal scrollbars if needed */
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
  border-radius: 3px;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: rgba(156, 163, 175, 0.5); /* gray-400 with 50% opacity */
  border-radius: 3px;
  border: 1px solid transparent; /* Prevent border issues */
  background-clip: content-box; /* Ensure border doesn't make thumb look smaller */
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background-color: rgba(107, 114, 128, 0.6); /* gray-500 with 60% opacity */
}

/* Dark mode scrollbar */
@media (prefers-color-scheme: dark) {
    .custom-scrollbar::-webkit-scrollbar-thumb {
        background-color: rgba(107, 114, 128, 0.5); /* gray-500 with 50% opacity */
    }
     .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background-color: rgba(75, 85, 99, 0.6); /* gray-600 with 60% opacity */
    }
}


/* Basic Details/Summary Styling (Remove default marker, add basic hover) */
details > summary {
  list-style: none; /* Hide the default marker */
  cursor: pointer;
  display: inline-block; /* Prevent summary taking full width */
  /* Add any other base summary styles here if needed */
}
details > summary::-webkit-details-marker {
  display: none; /* Hide marker specifically for Webkit */
}
/* Add slight visual feedback on summary hover if desired */
/* details > summary:hover {
   opacity: 0.8;
} */

/* Style for the arrow inside the summary (if you add one via HTML/JS) */
details[open] > summary .arrow-down {
  transform: rotate(180deg);
}
.arrow-down {
    display: inline-block;
    transition: transform 0.2s ease-in-out;
    /* Style the SVG arrow itself with Tailwind classes in the HTML */
}

/* Estilos para tabelas no super_admin.php (mantidos do exemplo anterior) */
/* (Apenas se vocÃª nÃ£o estiver usando classes Tailwind diretamente nas tabelas) */
/*
table { width: 100%; }
th, td { padding: 0.75rem; text-align: left; border-bottom-width: 1px; border-style: solid; }
.light th, .light td { border-color: #e5e7eb; }
.dark th, .dark td { border-color: #374151; }
thead th { font-weight: 600; }
*/

/* Estilo para botÃµes de aÃ§Ã£o pequenos (mantidos do exemplo anterior) */
/* (Apenas se vocÃª nÃ£o estiver usando classes Tailwind diretamente nos botÃµes) */
/*
.action-btn {
     padding: 0.25rem 0.5rem; font-size: 0.75rem; border-radius: 0.375rem;
     margin-right: 0.5rem; margin-bottom: 0.25rem; display: inline-flex;
     align-items: center; justify-content: center; text-decoration: none !important;
     box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); transition: background-color 0.2s ease-in-out;
}
.action-btn:last-child { margin-right: 0; }
.btn-green { background-color: #10b981; color: white; } .btn-green:hover { background-color: #059669; }
.btn-yellow { background-color: #f59e0b; color: white; } .btn-yellow:hover { background-color: #d97706; }
.btn-red { background-color: #ef4444; color: white; } .btn-red:hover { background-color: #dc2626; }
*/

/* --- 5. Helper Classes (Use Sparingly) --- */
/* Example: Add a class if you need very specific text wrapping */
/* .force-break-word {
    word-wrap: break-word;
    overflow-wrap: break-word;
} */



<?php
/**
 * Arquivo: super_admin_actions.php
 * Versão: v1.1 - Confirma includes após refatoração (Confirmado)
 * Descrição: Processa ações de gerenciamento de usuários (ativar, desativar, excluir)
 *            vindas do painel Super Admin (super_admin.php).
 *            Requer que o usuário logado seja Super Admin.
 */

// Includes Essenciais
require_once __DIR__ . '/config.php';             // Inicia sessão, carrega constantes
require_once __DIR__ . '/db.php';                 // Para getDbConnection()
require_once __DIR__ . '/includes/log_helper.php'; // Para logMessage()

// --- Validação de Acesso: Super Admin Logado ---
if (!isset($_SESSION['saas_user_id'])) {
    // Se não há usuário logado na sessão, redireciona para login
    header('Location: login.php?error=unauthorized');
    exit;
}

$loggedInSaasUserId = $_SESSION['saas_user_id']; // ID do admin logado
$isSuperAdmin = false;
$pdo = null;

try {
    $pdo = getDbConnection();
    // Verifica no banco se o usuário logado tem a flag de super admin
    $stmtAdmin = $pdo->prepare("SELECT is_super_admin FROM saas_users WHERE id = :id LIMIT 1");
    $stmtAdmin->execute([':id' => $loggedInSaasUserId]);
    $adminData = $stmtAdmin->fetch();

    // Se não encontrou o usuário ou ele não é super admin, nega acesso
    if (!$adminData || !$adminData['is_super_admin']) {
        logMessage("ALERTA: Tentativa de acesso a super_admin_actions.php por NÃO Super Admin ID: $loggedInSaasUserId");
        // Redireciona para o dashboard normal, pois não tem permissão aqui
        header('Location: dashboard.php');
        exit;
    }
    // Se chegou aqui, o usuário é Super Admin
    $isSuperAdmin = true;

} catch (\Exception $e) {
    // Erro crítico ao verificar permissões, melhor deslogar ou ir para erro
    logMessage("Erro crítico ao verificar privilégios em super_admin_actions.php para ID $loggedInSaasUserId: " . $e->getMessage());
    header('Location: login.php?error=internal_error'); // Volta para login com erro
    exit;
}

// --- Processamento das Ações ---

// Pega a ação e o ID do usuário alvo dos parâmetros GET
$action = $_GET['action'] ?? null;
$targetUserId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT); // Pega e valida se é um inteiro

// Define valores padrão para feedback
$status = 'error'; // Assume erro por padrão
$message = 'Ação inválida ou ID de usuário não fornecido.';
$targetUserEmail = ($targetUserId) ? 'ID ' . $targetUserId : 'N/A'; // Email padrão para logs

// Verifica se a ação e o ID são válidos
if ($action && $targetUserId && $targetUserId > 0) {

    // Impede Super Admin de executar ações em sua própria conta
    if ($targetUserId == $loggedInSaasUserId) {
        $message = 'Você não pode executar esta ação em sua própria conta.';
        $status = 'warning'; // Usa status de aviso
        logMessage("Super Admin $loggedInSaasUserId tentou ação '$action' em si mesmo.");
    } else {
        // Tenta encontrar o usuário alvo no banco antes de agir
        try {
            $stmtTarget = $pdo->prepare("SELECT email FROM saas_users WHERE id = :id");
            $stmtTarget->execute([':id' => $targetUserId]);
            $targetUser = $stmtTarget->fetch();

            // Se o usuário alvo não existe no banco
            if (!$targetUser) {
                $message = "Usuário com ID $targetUserId não encontrado.";
                $status = 'error';
                logMessage("Super Admin $loggedInSaasUserId tentou ação '$action' em usuário inexistente ID: $targetUserId");
            } else {
                // Usuário alvo encontrado, pega o email para logs mais claros
                $targetUserEmail = $targetUser['email'];
                logMessage("Super Admin $loggedInSaasUserId iniciando ação '$action' no usuário '$targetUserEmail' (ID: $targetUserId)");

                $pdo->beginTransaction(); // Inicia transação para garantir atomicidade da operação

                // Executa a ação solicitada
                switch ($action) {
                    case 'activate': // Ativa a conta SaaS do usuário
                        $sql = "UPDATE saas_users SET is_saas_active = TRUE, updated_at = NOW() WHERE id = :id";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([':id' => $targetUserId]);
                        $message = "Usuário '$targetUserEmail' (ID: $targetUserId) ativado com sucesso.";
                        $status = 'success';
                        logMessage("Usuário '$targetUserEmail' (ID: $targetUserId) ATIVADO por Super Admin $loggedInSaasUserId.");
                        break;

                    case 'deactivate': // Desativa a conta SaaS do usuário
                        $sql = "UPDATE saas_users SET is_saas_active = FALSE, updated_at = NOW() WHERE id = :id";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([':id' => $targetUserId]);

                        // Opcional: Desativar também a conexão ML associada?
                        // Poderia ser feito aqui ou deixar que o CRON trate conexões de usuários inativos.
                        // Ex: $pdo->exec("UPDATE mercadolibre_users SET is_active = FALSE WHERE saas_user_id = $targetUserId");
                        // logMessage("Conexão ML para usuário '$targetUserEmail' (ID: $targetUserId) também desativada.");

                        $message = "Usuário '$targetUserEmail' (ID: $targetUserId) desativado com sucesso.";
                        $status = 'success';
                        logMessage("Usuário '$targetUserEmail' (ID: $targetUserId) DESATIVADO por Super Admin $loggedInSaasUserId.");
                        break;

                    case 'delete': // Exclui permanentemente o usuário SaaS
                        // !! ALERTA DE INTEGRIDADE DE DADOS !!
                        // Esta exclusão remove o usuário da tabela `saas_users`.
                        // NÃO remove automaticamente registros relacionados em outras tabelas
                        // (`mercadolibre_users`, `question_processing_log`) que usam `saas_user_id`.
                        // Considere usar chaves estrangeiras com ON DELETE SET NULL ou ON DELETE CASCADE,
                        // ou implementar uma lógica de limpeza aqui ou em um processo separado.
                        logMessage("AVISO: Excluindo usuário SaaS ID $targetUserId ('$targetUserEmail'). Dados relacionados (ML conns, logs) NÃO serão removidos automaticamente por este script.");

                        $sql = "DELETE FROM saas_users WHERE id = :id";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([':id' => $targetUserId]);

                        // Verifica se alguma linha foi realmente deletada
                        if ($stmt->rowCount() > 0) {
                            $message = "Usuário '$targetUserEmail' (ID: $targetUserId) EXCLUÍDO permanentemente do sistema SaaS.";
                            $status = 'success';
                            logMessage("Usuário '$targetUserEmail' (ID: $targetUserId) EXCLUÍDO por Super Admin $loggedInSaasUserId.");
                            // TODO: Implementar limpeza de dados relacionados aqui, se desejado.
                            // Ex: DELETE FROM mercadolibre_users WHERE saas_user_id = $targetUserId;
                            // Ex: UPDATE question_processing_log SET saas_user_id = NULL WHERE saas_user_id = $targetUserId;
                        } else {
                             // Nenhuma linha afetada (usuário já tinha sido removido?)
                             $message = "Não foi possível excluir o usuário ID $targetUserId (talvez já tenha sido removido).";
                             $status = 'warning';
                             logMessage("Tentativa de exclusão do usuário ID $targetUserId por Super Admin $loggedInSaasUserId falhou (rowCount 0).");
                        }
                        break;

                    default: // Ação desconhecida
                        $pdo->rollBack(); // Desfaz transação se ação for inválida
                        $message = "Ação desconhecida: '$action'. Nenhuma alteração realizada.";
                        $status = 'error';
                        logMessage("Super Admin $loggedInSaasUserId tentou ação desconhecida: '$action' no usuário ID: $targetUserId");
                        break;
                }

                // Se a ação foi válida e não houve exceção, commita a transação
                 if ($status !== 'error' && $action !== 'default') {
                    $pdo->commit();
                 } elseif ($action !== 'default') {
                     // Se houve erro SQL ou outro dentro do switch, faz rollback
                     if($pdo->inTransaction()) { $pdo->rollBack(); }
                 }

            } // Fim else (targetUser encontrado)
        } catch (\PDOException $e) {
             // Erro no banco de dados durante a execução da ação
             if($pdo->inTransaction()) { $pdo->rollBack(); } // Garante rollback
             logMessage("Erro DB ao executar ação '$action' no usuário ID $targetUserId ($targetUserEmail): " . $e->getMessage());
             $message = "Erro no banco de dados ao tentar executar a ação '$action'. Consulte os logs.";
             $status = 'error';
        } catch (\Exception $e) {
             // Outro erro inesperado
             if($pdo->inTransaction()) { $pdo->rollBack(); } // Garante rollback
             logMessage("Erro GERAL ao executar ação '$action' no usuário ID $targetUserId ($targetUserEmail): " . $e->getMessage());
             $message = "Erro inesperado ao tentar executar a ação '$action'. Consulte os logs.";
             $status = 'error';
        }
    } // Fim else (não é auto-ação)

} else {
    // Se a ação ou user_id não foram fornecidos ou são inválidos
    logMessage("Tentativa de acesso a super_admin_actions.php com parâmetros inválidos: Action='$action', UserID='$targetUserId'");
    // Mensagem padrão de erro já definida
}

// --- Redirecionamento Final ---
// Redireciona de volta para o painel Super Admin com a mensagem de status/erro
// Adiciona #tab-users para focar na aba de usuários após a ação
header('Location: super_admin.php?action_status='.$status.'&action_msg=' . urlencode($message) . '#tab-users');
exit; // Finaliza o script
?>



<?php
/**
 * Arquivo: super_admin.php
 * Versão: v1.4 - Confirma ID da div do histórico
 * Descrição: Painel de Super Administrador com gerenciamento de usuários,
 *            visualização de conexões ML, logs globais e informações de assinatura Asaas.
 */

// --- Includes Essenciais ---
require_once __DIR__ . '/config.php'; // Inicia sessão implicitamente
require_once __DIR__ . '/db.php';     // Para getDbConnection()
require_once __DIR__ . '/includes/log_helper.php'; // Para logMessage()
require_once __DIR__ . '/includes/helpers.php'; // Para getSubscriptionStatusClass() e getStatusTagClasses()

// --- Proteção: Exige Login e Privilégio de Super Admin ---
if (!isset($_SESSION['saas_user_id'])) {
    header('Location: login.php?error=unauthorized');
    exit;
}
$loggedInSaasUserId = $_SESSION['saas_user_id'];
$isSuperAdmin = false;
$pdo = null;
$loggedInSaasUserEmail = 'Admin'; // Valor padrão

try {
    $pdo = getDbConnection();
    // Verifica se o usuário logado tem a flag is_super_admin
    $stmtAdmin = $pdo->prepare("SELECT is_super_admin, email FROM saas_users WHERE id = :id LIMIT 1");
    $stmtAdmin->execute([':id' => $loggedInSaasUserId]);
    $adminData = $stmtAdmin->fetch();

    // Se não encontrou o usuário ou ele não é super admin, redireciona
    if (!$adminData || !$adminData['is_super_admin']) {
        logMessage("ALERTA: Tentativa acesso super_admin.php por NÃO Super Admin ID: $loggedInSaasUserId");
        header('Location: dashboard.php'); // Redireciona para o dashboard normal
        exit;
    }
    $isSuperAdmin = true;
    $loggedInSaasUserEmail = $adminData['email'] ?? $loggedInSaasUserEmail; // Pega o email do admin
    logMessage("Acesso Super Admin concedido para SaaS User ID: $loggedInSaasUserId ($loggedInSaasUserEmail)");

} catch (\PDOException | \Exception $e) {
    logMessage("Erro crítico ao verificar privilégios Super Admin para ID $loggedInSaasUserId: " . $e->getMessage());
    header('Location: login.php?error=internal_error'); // Falha crítica, volta pro login
    exit;
}

// --- Inicialização e Feedback de Ações ---
$allSaaSUsers = [];         // Array para guardar usuários SaaS
$allMLConnections = [];     // Array para guardar conexões ML
$allQuestionLogs = [];      // Array para guardar logs globais
$feedbackMessage = null;    // Mensagem de feedback (ex: usuário ativado)
$feedbackMessageClass = ''; // Classe CSS para a mensagem

// Mapeamento de status de ação para classes Tailwind
$message_classes = [
    'success' => 'bg-green-100 dark:bg-green-900 border border-green-300 dark:border-green-700 text-green-700 dark:text-green-300',
    'error'   => 'bg-red-100 dark:bg-red-900 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300',
    'warning' => 'bg-yellow-100 dark:bg-yellow-900 border border-yellow-400 dark:border-yellow-700 text-yellow-800 dark:text-yellow-300',
    // Adicionado para compatibilidade com mensagens de erro genéricas
    'is-danger is-light' => 'bg-red-100 dark:bg-red-900 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300',
];

// Processa mensagens de feedback vindas de super_admin_actions.php
if (isset($_GET['action_status']) && isset($_GET['action_msg'])) {
    $statusType = $_GET['action_status'];
    $messageText = urldecode($_GET['action_msg']);
    // Define a mensagem e a classe se o tipo for válido
    if (isset($message_classes[$statusType])) {
        $feedbackMessage = ['type' => $statusType, 'text' => $messageText];
        $feedbackMessageClass = $message_classes[$statusType];
    }
    // Limpa os parâmetros da URL via JS
    echo "<script> if (history.replaceState) { setTimeout(function() { history.replaceState(null, null, window.location.pathname + window.location.hash); }, 1); } </script>";
}

// --- Busca de Dados Globais ---
try {
    // 1. Buscar Todos os Usuários SaaS com dados Asaas
    $stmtUsers = $pdo->query(
        "SELECT id, email, name, cpf_cnpj, is_saas_active, created_at,
                asaas_customer_id, asaas_subscription_id, subscription_status, subscription_expires_at
         FROM saas_users ORDER BY created_at DESC"
    );
    $allSaaSUsers = $stmtUsers->fetchAll();

    // 2. Buscar Todas as Conexões ML Ativas e Inativas, com email do usuário SaaS
    $stmtML = $pdo->query(
        "SELECT m.id, m.ml_user_id, m.is_active, m.token_expires_at, m.updated_at, s.email as saas_email, s.id as saas_user_id
         FROM mercadolibre_users m
         JOIN saas_users s ON m.saas_user_id = s.id
         ORDER BY m.updated_at DESC"
    );
    $allMLConnections = $stmtML->fetchAll();

    // 3. Buscar os Últimos Logs Globais de Processamento de Perguntas
    $logLimit = 500; // Limite de logs a exibir
    $stmtLogs = $pdo->prepare(
        "SELECT q.*, s.email as saas_email
         FROM question_processing_log q
         LEFT JOIN saas_users s ON q.saas_user_id = s.id -- LEFT JOIN para mostrar logs mesmo se usuário for deletado
         ORDER BY q.last_processed_at DESC
         LIMIT :limit"
    );
    $stmtLogs->bindParam(':limit', $logLimit, PDO::PARAM_INT);
    $stmtLogs->execute();
    $allQuestionLogs = $stmtLogs->fetchAll();

} catch (\PDOException | \Exception $e) {
    logMessage("Erro DB/Geral Super Admin Dashboard: " . $e->getMessage());
    // Define mensagem de erro se ainda não houver uma vinda de _GET
    if (!$feedbackMessage) {
        $feedbackMessage = ['type' => 'is-danger is-light', 'text' => '⚠️ Erro ao carregar dados globais para o painel.'];
        $feedbackMessageClass = $message_classes['is-danger is-light'];
    }
}

// (As funções helper getStatusTagClasses e getSubscriptionStatusClass estão em includes/helpers.php)

?>
<!DOCTYPE html>
<html lang="pt-br" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin - Meli AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Estilos para quebra de texto em células de tabela */
        .break-all { word-break: break-all; }
        .truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        /* Ajustes finos de padding/margin se necessário */
        /* th, td { padding: 0.5rem 0.75rem; } */
    </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100 min-h-screen flex flex-col transition-colors duration-300">
    <section class="main-content container mx-auto px-2 sm:px-4 py-8">
        <!-- Cabeçalho -->
        <header class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 mb-6">
            <div class="flex justify-between items-center flex-wrap gap-y-2">
                <h1 class="text-xl font-semibold flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-purple-500"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                    <span>Meli AI - Super Admin</span>
                </h1>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-600 dark:text-gray-400 hidden sm:inline" title="Admin Logado">
                        Admin: <?php echo htmlspecialchars($loggedInSaasUserEmail); ?>
                    </span>
                    <a href="dashboard.php" class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300" title="Ir para Dashboard Normal">
                        Dashboard
                    </a>
                    <a href="logout.php" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 dark:focus:ring-offset-gray-800" title="Sair da Conta">
                        🚪 Sair
                    </a>
                </div>
            </div>
        </header>

        <!-- Mensagem de Feedback de Ações -->
        <?php if ($feedbackMessage && $feedbackMessageClass): ?>
            <div id="feedback-message" class="<?php echo htmlspecialchars($feedbackMessageClass); ?> px-4 py-3 rounded-md text-sm mb-6 flex justify-between items-center" role="alert">
                <span><?php echo htmlspecialchars($feedbackMessage['text']); ?></span>
                <button onclick="document.getElementById('feedback-message').style.display='none';" class="ml-4 -mr-1 p-1 rounded-md focus:outline-none focus:ring-2 focus:ring-current hover:bg-opacity-20 hover:bg-current" aria-label="Fechar">
                   <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
        <?php endif; ?>

        <!-- Abas de Navegação -->
         <div class="mb-6">
             <div class="border-b border-gray-200 dark:border-gray-700">
                 <nav id="superadmin-tabs" class="-mb-px flex space-x-4 sm:space-x-6 overflow-x-auto" aria-label="Tabs">
                     <a href="#tab-users" data-tab="users" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center space-x-1.5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 border-transparent">
                         <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372m-1.062-3.538a9.38 9.38 0 0 1-.372 2.625M15 19.128v-1.5a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 9 12.75v-1.5a3.375 3.375 0 0 0-3.375-3.375H4.5a1.125 1.125 0 0 1-1.125-1.125v-1.5A3.375 3.375 0 0 0 6.75 3h10.5A3.375 3.375 0 0 0 21 6.75v1.5a1.125 1.125 0 0 1-1.125 1.125h-1.5a3.375 3.375 0 0 0-3.375 3.375v1.5a1.125 1.125 0 0 1-1.125 1.125h-1.5Zm-6 0a9.375 9.375 0 1 1 18 0 9.375 9.375 0 0 1-18 0Z" /></svg>
                         <span>Usuários SaaS</span>
                     </a>
                     <a href="#tab-ml-connections" data-tab="ml-connections" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center space-x-1.5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 border-transparent">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" /></svg>
                         <span>Conexões ML</span>
                     </a>
                     <a href="#tab-all-logs" data-tab="all-logs" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center space-x-1.5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 border-transparent">
                         <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" /></svg>
                         <span>Todos os Logs</span>
                     </a>
                 </nav>
             </div>
         </div>

        <!-- Container Conteúdo das Abas -->
        <div class="space-y-6">

            <!-- Aba Usuários SaaS -->
            <div id="tab-users" class="tab-content hidden bg-white dark:bg-gray-800 shadow rounded-lg p-4 sm:p-6">
                 <h2 class="text-lg font-semibold mb-4">👥 Usuários SaaS Registrados</h2>
                 <?php if (empty($allSaaSUsers)): ?>
                     <p class="text-gray-500 dark:text-gray-400">Nenhum usuário SaaS encontrado.</p>
                 <?php else: ?>
                     <div class="overflow-x-auto">
                         <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                             <thead class="bg-gray-50 dark:bg-gray-700/50">
                                 <tr>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">ID</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Email / Nome</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status Conta</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status Assinatura</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Expira em</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Asaas Cust ID</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Asaas Sub ID</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Registro</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Ações</th>
                                 </tr>
                             </thead>
                             <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                 <?php foreach ($allSaaSUsers as $user): ?>
                                     <tr>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo $user['id']; ?></td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                             <div class="truncate max-w-xs" title="<?php echo htmlspecialchars($user['email']); ?>"><?php echo htmlspecialchars($user['email']); ?></div>
                                             <?php if(!empty($user['name'])): ?>
                                                <div class="text-xs text-gray-500 dark:text-gray-400 truncate max-w-xs" title="<?php echo htmlspecialchars($user['name']); ?>"><?php echo htmlspecialchars($user['name']); ?></div>
                                             <?php endif; ?>
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm">
                                             <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $user['is_saas_active'] ? 'bg-green-100 text-green-800 dark:bg-green-700 dark:text-green-100' : 'bg-red-100 text-red-800 dark:bg-red-700 dark:text-red-100'; ?>">
                                                 <?php echo $user['is_saas_active'] ? 'Ativo' : 'Inativo'; ?>
                                             </span>
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm">
                                             <span class="<?php echo getSubscriptionStatusClass($user['subscription_status']); ?>">
                                                 <?php echo htmlspecialchars(ucfirst(strtolower($user['subscription_status'] ?? 'N/A'))); ?>
                                             </span>
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                             <?php echo $user['subscription_expires_at'] ? htmlspecialchars(date('d/m/Y', strtotime($user['subscription_expires_at']))) : '-'; ?>
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400 break-all" title="<?php echo htmlspecialchars($user['asaas_customer_id'] ?? '-'); ?>">
                                             <?php echo htmlspecialchars($user['asaas_customer_id'] ?? '-'); ?>
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400 break-all" title="<?php echo htmlspecialchars($user['asaas_subscription_id'] ?? '-'); ?>">
                                             <?php echo htmlspecialchars($user['asaas_subscription_id'] ?? '-'); ?>
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                             <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($user['created_at']))); ?>
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm font-medium">
                                             <?php if ($user['id'] != $loggedInSaasUserId): // Não permite ações no próprio admin ?>
                                                 <?php if ($user['is_saas_active']): ?>
                                                     <a href="super_admin_actions.php?action=deactivate&user_id=<?php echo $user['id']; ?>" class="text-yellow-600 hover:text-yellow-900 dark:text-yellow-400 dark:hover:text-yellow-300 mr-3" title="Desativar Conta SaaS">Desativar</a>
                                                 <?php else: ?>
                                                     <a href="super_admin_actions.php?action=activate&user_id=<?php echo $user['id']; ?>" class="text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300 mr-3" title="Ativar Conta SaaS">Ativar</a>
                                                 <?php endif; ?>
                                                 <a href="super_admin_actions.php?action=delete&user_id=<?php echo $user['id']; ?>" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300" title="Excluir Conta SaaS Permanentemente" onclick="return confirm('EXCLUIR USUÁRIO <?php echo htmlspecialchars(addslashes($user['email'])); ?>?\n\nATENÇÃO: Esta ação não pode ser desfeita e removerá o acesso do usuário permanentemente!');">
                                                     Excluir
                                                 </a>
                                             <?php else: ?>
                                                 <span class="text-xs italic text-gray-500 dark:text-gray-400">(Você)</span>
                                             <?php endif; ?>
                                         </td>
                                     </tr>
                                 <?php endforeach; ?>
                             </tbody>
                         </table>
                     </div>
                 <?php endif; ?>
            </div> <!-- Fim #tab-users -->

            <!-- Aba Conexões ML -->
            <div id="tab-ml-connections" class="tab-content hidden bg-white dark:bg-gray-800 shadow rounded-lg p-4 sm:p-6 overflow-x-auto">
                 <h2 class="text-lg font-semibold mb-4">🔗 Conexões Mercado Livre Ativas/Inativas</h2>
                  <?php if (empty($allMLConnections)): ?>
                     <p class="text-gray-500 dark:text-gray-400">Nenhuma conexão Mercado Livre encontrada no sistema.</p>
                 <?php else: ?>
                     <div class="overflow-x-auto">
                         <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                             <thead class="bg-gray-50 dark:bg-gray-700/50">
                                 <tr>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">ID Conexão</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Usuário SaaS</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">ML User ID</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status Conexão</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Token Expira</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Última Atualização</th>
                                     <!-- Adicionar Coluna Ações se necessário (ex: desativar conexão ML) -->
                                 </tr>
                             </thead>
                             <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                 <?php foreach ($allMLConnections as $conn): ?>
                                     <tr>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo $conn['id']; ?></td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300 truncate max-w-xs" title="<?php echo htmlspecialchars($conn['saas_email']); ?>">
                                             <?php echo htmlspecialchars($conn['saas_email']); ?> (ID: <?php echo $conn['saas_user_id']; ?>)
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($conn['ml_user_id']); ?></td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm">
                                             <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $conn['is_active'] ? 'bg-green-100 text-green-800 dark:bg-green-700 dark:text-green-100' : 'bg-red-100 text-red-800 dark:bg-red-700 dark:text-red-100'; ?>">
                                                 <?php echo $conn['is_active'] ? 'Ativa' : 'Inativa'; ?>
                                             </span>
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                              <?php echo $conn['token_expires_at'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($conn['token_expires_at']))) : 'N/A'; ?>
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                             <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($conn['updated_at']))); ?>
                                         </td>
                                     </tr>
                                 <?php endforeach; ?>
                             </tbody>
                         </table>
                     </div>
                 <?php endif; ?>
            </div> <!-- Fim #tab-ml-connections -->

            <!-- Aba Todos os Logs -->
            <div id="tab-all-logs" class="tab-content hidden bg-white dark:bg-gray-800 shadow rounded-lg p-4 sm:p-6">
                 <h2 class="text-lg font-semibold mb-4">📜 Todos os Logs Recentes (Últimos <?php echo $logLimit; ?>)</h2>
                  <?php if (empty($allQuestionLogs)): ?>
                      <p class="text-center text-gray-500 dark:text-gray-400 py-10 text-sm">Nenhum log de processamento encontrado no sistema.</p>
                  <?php else: ?>
                      <div class="log-container custom-scrollbar border border-gray-200 dark:border-gray-700 rounded-lg max-h-[70vh] overflow-y-auto divide-y divide-gray-200 dark:divide-gray-700">
                          <?php foreach ($allQuestionLogs as $log): ?>
                              <div class="log-entry px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors duration-150">
                                  <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mb-1">
                                      <span class="text-sm font-medium text-gray-800 dark:text-gray-200">P: <?php echo htmlspecialchars($log['ml_question_id']); ?></span>
                                      <span class="text-xs text-gray-500 dark:text-gray-400" title="Usuário SaaS"><?php echo htmlspecialchars($log['saas_email'] ?? 'ID: ' . ($log['saas_user_id'] ?? 'N/A')); ?></span>
                                      <span class="text-sm text-gray-600 dark:text-gray-400">ML UID: <?php echo htmlspecialchars($log['ml_user_id']); ?></span>
                                      <span class="text-sm text-gray-600 dark:text-gray-400">Item: <?php echo htmlspecialchars($log['item_id']); ?></span>
                                      <span class="<?php echo getStatusTagClasses($log['status']); ?>" title="Status"><?php echo htmlspecialchars(str_replace('_', ' ', $log['status'])); ?></span>
                                  </div>
                                  <div class="text-xs text-gray-500 dark:text-gray-400 flex flex-wrap gap-x-3 gap-y-1">
                                      <?php if (!empty($log['sent_to_whatsapp_at'])): ?> <span title="Notif Wpp: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['sent_to_whatsapp_at']))); ?>">🔔 <?php echo htmlspecialchars(date('d/m H:i', strtotime($log['sent_to_whatsapp_at']))); ?></span> <?php endif; ?>
                                      <?php if (!empty($log['human_answered_at'])): ?> <span title="Resp Wpp: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['human_answered_at']))); ?>">✍️ <?php echo htmlspecialchars(date('d/m H:i', strtotime($log['human_answered_at']))); ?></span> <?php endif; ?>
                                      <?php if (!empty($log['ai_answered_at'])): ?> <span title="Resp IA: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['ai_answered_at']))); ?>">🤖 <?php echo htmlspecialchars(date('d/m H:i', strtotime($log['ai_answered_at']))); ?></span> <?php endif; ?>
                                  </div>
                                  <?php if (!empty($log['question_text'])): ?> <details class="mt-2"><summary class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline cursor-pointer inline-flex items-center group"> Ver Pergunta <svg class="arrow-down h-4 w-4 ml-1 transition-transform duration-200 group-focus:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg> </summary><pre class="mt-1 p-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded text-xs text-gray-700 dark:text-gray-300 max-h-40 overflow-y-auto whitespace-pre-wrap break-words"><code><?php echo htmlspecialchars($log['question_text']); ?></code></pre></details><?php endif; ?>
                                  <?php if (!empty($log['ia_response_text']) && in_array(strtoupper($log['status']), ['AI_ANSWERED', 'AI_FAILED', 'AI_PROCESSING', 'AI_TRIGGERED_BY_TEXT'])): ?> <details class="mt-2"><summary class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline cursor-pointer inline-flex items-center group"> Ver Resposta IA <?php if (strtoupper($log['status']) == 'AI_ANSWERED') echo '(Enviada)'; elseif (strtoupper($log['status']) == 'AI_FAILED') echo '(Inválida/Falhou)'; else echo '(Gerada/Tentada)'; ?> <svg class="arrow-down h-4 w-4 ml-1 transition-transform duration-200 group-focus:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg> </summary><pre class="mt-1 p-2 border rounded text-xs max-h-40 overflow-y-auto whitespace-pre-wrap break-words <?php echo strtoupper($log['status']) == 'AI_ANSWERED' ? 'bg-green-50 dark:bg-green-900/50 border-green-200 dark:border-green-700 text-green-800 dark:text-green-200' : 'bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-300'; ?>"><code><?php echo htmlspecialchars($log['ia_response_text']); ?></code></pre></details><?php endif; ?>
                                  <?php if (!empty($log['error_message'])): ?><p class="text-red-600 dark:text-red-400 text-xs mt-1"><strong>Erro:</strong> <?php echo htmlspecialchars($log['error_message']); ?></p><?php endif; ?>
                                  <p class="text-xs text-gray-400 dark:text-gray-500 mt-2 text-right">Última Atualização: <?php echo htmlspecialchars(date('d/m/Y H:i:s', strtotime($log['last_processed_at']))); ?></p>
                              </div>
                          <?php endforeach; ?>
                      </div>
                  <?php endif; // Fim else $allQuestionLogs ?>
             </div> <!-- Fim #tab-all-logs -->
        </div> <!-- Fim container conteúdo abas -->
    </section> <!-- Fim .main-content -->

     <!-- Rodapé -->
     <footer class="py-6 text-center">
         <p class="text-sm text-gray-500 dark:text-gray-400">
             <strong>Meli AI - Super Admin</strong> © <?php echo date('Y'); ?>
         </p>
     </footer>

    <!-- Script JS Abas -->
    <script>
         document.addEventListener('DOMContentLoaded', () => {
             const tabs = document.querySelectorAll('#superadmin-tabs a[data-tab]');
             const tabContents = document.querySelectorAll('.tab-content'); // Seleciona todas as divs de conteúdo
             const activeTabClasses = ['text-blue-600', 'dark:text-blue-400', 'border-blue-500'];
             const inactiveTabClasses = ['text-gray-500', 'dark:text-gray-400', 'hover:text-gray-700', 'dark:hover:text-gray-200', 'hover:border-gray-300', 'dark:hover:border-gray-500', 'border-transparent'];

             function switchTab(targetTabId) {
                 tabs.forEach(tab => {
                     const isTarget = tab.getAttribute('data-tab') === targetTabId;
                     tab.classList.toggle(...activeTabClasses, isTarget);
                     tab.classList.toggle(...inactiveTabClasses, !isTarget);
                     tab.setAttribute('aria-selected', isTarget ? 'true' : 'false');
                 });
                 tabContents.forEach(content => {
                     if(content.id === `tab-${targetTabId}`) { content.classList.remove('hidden'); }
                     else { content.classList.add('hidden'); }
                 });
                 if (history.pushState) { setTimeout(() => history.pushState(null, null, '#tab-' + targetTabId), 0); }
                 else { window.location.hash = '#tab-' + targetTabId; }
             }

             tabs.forEach(tab => {
                 tab.addEventListener('click', (event) => {
                     event.preventDefault();
                     const tabId = tab.getAttribute('data-tab');
                     if (tabId) { switchTab(tabId); }
                 });
             });

             let activeTabId = 'users'; // Aba padrão
             if (window.location.hash && window.location.hash.startsWith('#tab-')) {
                 const hash = window.location.hash.substring(5); // Remove '#tab-'
                 const requestedTab = document.querySelector(`#superadmin-tabs a[data-tab="${hash}"]`);
                 if (requestedTab) { activeTabId = hash; }
             }
             switchTab(activeTabId); // Ativa a aba inicial
         });
    </script>
</body>
</html>



<?php
/**
 * Arquivo: update_profile.php
 * Versão: v1.1 - Atualiza includes após refatoração
 * Descrição: Processa a atualização do número de WhatsApp do usuário logado.
 *            (Não implementa alteração de senha no momento).
 */

// Includes Essenciais
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';                 // Para getDbConnection()
require_once __DIR__ . '/includes/log_helper.php'; // Para logMessage()

// --- Proteção: Exige Login ---
if (!isset($_SESSION['saas_user_id'])) {
    header('Location: login.php?error=unauthorized');
    exit;
}
$saasUserId = $_SESSION['saas_user_id'];

// --- Processar apenas se for método POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Processamento do Número WhatsApp ---
    $whatsapp_number_raw = $_POST['whatsapp_number'] ?? '';
    $whatsapp_jid_to_save = null;
    $error_message = null;
    $redirect_code = 'generic'; // Código de erro para URL

    logMessage("[Profile Update] SaaS User $saasUserId - Iniciando atualização. WhatsApp Raw: '$whatsapp_number_raw'");

    // Validar e Formatar o Número se não estiver vazio
    if (!empty(trim($whatsapp_number_raw))) {
        $jid_cleaned = preg_replace('/[^\d]/', '', $whatsapp_number_raw); // Remove não-dígitos

        // Validação de tamanho (10 ou 11 dígitos para DDD + Número no Brasil)
        if (preg_match('/^\d{10,11}$/', $jid_cleaned)) {
            $whatsapp_jid_to_save = "55" . $jid_cleaned . "@s.whatsapp.net"; // Formato JID Brasil
            logMessage("[Profile Update] SaaS User $saasUserId - Número '$jid_cleaned' validado. JID formatado: '$whatsapp_jid_to_save'");
        } else {
            $error_message = "Formato do Número WhatsApp inválido. Use apenas DDD + Número (10 ou 11 dígitos).";
            $redirect_code = 'validation';
            logMessage("[Profile Update] SaaS User $saasUserId - Número inválido (formato/tamanho): '$whatsapp_number_raw'");
        }
    } else {
        // Permite limpar o número (define JID como NULL no banco)
        $whatsapp_jid_to_save = null;
        logMessage("[Profile Update] SaaS User $saasUserId - Número WhatsApp removido (campo vazio).");
    }

    // --- TODO: Implementar Lógica de Alteração de Senha ---
    // $current_password = $_POST['current_password'] ?? '';
    // $new_password = $_POST['new_password'] ?? '';
    // $confirm_password = $_POST['confirm_password'] ?? '';
    // if (!empty($current_password) || !empty($new_password) || !empty($confirm_password)) {
    //     // Validar senha atual
    //     // Validar nova senha (mín 8 chars, etc.)
    //     // Validar se nova senha e confirmação batem
    //     // Se tudo OK, buscar hash atual, verificar senha atual com password_verify()
    //     // Se senha atual OK, gerar novo hash com password_hash()
    //     // Atualizar o hash no banco de dados
    //     // Adicionar mensagens de erro ou sucesso específicas para senha
    //     logMessage("[Profile Update] SaaS User $saasUserId - Tentativa de alteração de senha (ainda não implementada).");
    // }
    // --- Fim TODO Senha ---


    // --- Atualizar no Banco de Dados (se não houve erro de validação do WhatsApp) ---
    if ($error_message === null) {
        try {
            $pdo = getDbConnection();
            // Query para atualizar APENAS o JID por enquanto
            $sql = "UPDATE saas_users SET whatsapp_jid = :jid, updated_at = NOW() WHERE id = :saas_user_id";
            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([
                ':jid' => $whatsapp_jid_to_save, // Salva o JID formatado ou NULL
                ':saas_user_id' => $saasUserId
            ]);

            if ($success) {
                logMessage("[Profile Update] SaaS User $saasUserId - JID atualizado no DB com sucesso.");
                // TODO: Adicionar mensagem de sucesso para senha se implementado
                header('Location: dashboard.php?profile_status=updated#perfil');
                exit;
            } else {
                $error_message = "Erro interno ao salvar as alterações no banco de dados.";
                $redirect_code = 'db';
                logMessage("[Profile Update] SaaS User $saasUserId - Falha SQL ao atualizar JID (execute retornou false).");
            }

        } catch (\PDOException $e) {
            logMessage("[Profile Update DB Error] SaaS User $saasUserId: " . $e->getMessage());
            $error_message = "Erro técnico ao salvar as alterações (DB)."; // Mensagem genérica para usuário
             $redirect_code = 'db';
        } catch (\Exception $e) { // Captura outros erros (ex: falha na criptografia de senha se implementado)
             logMessage("[Profile Update General Error] SaaS User $saasUserId: " . $e->getMessage());
             $error_message = "Erro inesperado ao processar a solicitação.";
              $redirect_code = 'internal';
        }
    }

    // Se chegou aqui, houve um erro (validação ou DB/Geral)
    // Redireciona de volta com mensagem de erro genérica e código
    logMessage("[Profile Update] SaaS User $saasUserId - Redirecionando com erro: Code='$redirect_code', Msg='$error_message'");
    // Usar a sessão para passar a mensagem de erro pode ser mais robusto que URL
    // $_SESSION['profile_error_msg'] = $error_message;
    header('Location: dashboard.php?profile_status=error&code=' . $redirect_code . '#perfil');
    exit;

} else {
    // Se não for POST, redireciona para o dashboard (aba padrão)
    logMessage("[Profile Update] Acesso não POST ignorado para SaaS User $saasUserId.");
    header('Location: dashboard.php');
    exit;
}
?>




<?php
/**
 * Arquivo: includes/asaas_api.php
 * Versão: v1.4 - Adiciona getAsaasPendingPaymentLink
 * Descrição: Funções para interagir com a API REST do Asaas v3.
 *            Inclui criação/busca de cliente, criação de assinatura e busca de link pendente/vencido.
 *            !! VERIFIQUE a documentação Asaas V3 para campos mínimos obrigatórios em /customers !!
 */

require_once __DIR__ . '/../config.php'; // Para constantes ASAAS_API_URL, ASAAS_API_KEY
require_once __DIR__ . '/log_helper.php';
require_once __DIR__ . '/curl_helper.php'; // Usando v1.3 (com correção URL)

/**
 * Cria um novo cliente na plataforma Asaas ou busca um existente.
 * Tenta criar via POST, se falhar por duplicidade (400), tenta buscar via GET.
 *
 * @param string $name Nome do cliente.
 * @param string $email Email do cliente.
 * @param string $cpfCnpj CPF ou CNPJ do cliente (será limpo).
 * @param string|null $phone Telefone celular (opcional, verifique necessidade no payload).
 * @param string|null $externalReference ID de referência externo (opcional).
 * @return array<string, mixed>|null Retorna dados do cliente criado ou encontrado, ou null em caso de erro grave.
 */
function createAsaasCustomer(string $name, string $email, string $cpfCnpj, ?string $phone = null, ?string $externalReference = null): ?array {
    $cpfCnpjCleaned = preg_replace('/[^0-9]/', '', $cpfCnpj);
    logMessage("[Asaas API v1.4 createCustomer] Tentando criar/buscar cliente: Email=$email, CPF/CNPJ=$cpfCnpjCleaned");
    if (!defined('ASAAS_API_URL') || !defined('ASAAS_API_KEY')) {
        logMessage("[Asaas API v1.4 createCustomer] ERRO: Constantes ASAAS não definidas.");
        return null;
    }

    $url = rtrim(ASAAS_API_URL, '/') . '/customers'; // Endpoint de criação
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'access_token: ' . ASAAS_API_KEY
    ];

    // Payload (simplificado v1.3, mantido na v1.4 - adicione 'mobilePhone' se necessário)
    $postData = [
        'name' => $name,
        'email' => $email,
        'cpfCnpj' => $cpfCnpjCleaned,
        // 'mobilePhone' => $phone, // Descomente e passe o $phone se for usar/necessário
        'externalReference' => $externalReference
    ];
    logMessage("[Asaas API v1.4 createCustomer] Payload POST /customers: " . json_encode($postData));

    $result = makeCurlRequest($url, 'POST', $headers, $postData, true); // true para JSON

    // Sucesso na criação
    if (($result['httpCode'] == 200 || $result['httpCode'] == 201) && $result['is_json'] && isset($result['response']['id'])) {
        logMessage("[Asaas API v1.4 createCustomer] Cliente criado via POST. Asaas ID: " . $result['response']['id']);
        return $result['response'];
    }
    // Tratamento de Erros
    else {
        $errorDetails = $result['is_json'] ? json_encode($result['response']) : ($result['response'] ?? 'N/A');
        logMessage("[Asaas API v1.4 createCustomer] ERRO no POST /customers. HTTP: {$result['httpCode']}. cURL Error: " . ($result['error'] ?? 'N/A') . ". API Resp: " . $errorDetails);

        // Tratamento específico para 404 (URL base, chave, permissão?)
        if ($result['httpCode'] == 404) {
             logMessage("[Asaas API v1.4 createCustomer] !!! ALERTA 404 !!! Verifique ASAAS_API_URL ('".ASAAS_API_URL."'), endpoint, e API Key ('".substr(ASAAS_API_KEY,0,10)."...').");
        }
        // Tratamento específico para 400 (Duplicidade?)
        elseif ($result['httpCode'] == 400 && isset($result['response']['errors']) && is_array($result['response']['errors'])) {
             foreach($result['response']['errors'] as $error) {
                if (isset($error['code']) && isset($error['description']) && strpos(strtolower($error['description']), 'already registered') !== false) {
                     logMessage("[Asaas API v1.4 createCustomer] Cliente já existe (erro 400 duplicidade). Tentando buscar...");
                     return findAsaasCustomerByEmailOrCpf($email, $cpfCnpjCleaned); // Tenta buscar
                 }
             }
             logMessage("[Asaas API v1.4 createCustomer] Erro 400 recebido, mas não identificado como duplicidade padrão.");
        }
        // Outros erros
        return null;
    }
}

/**
 * Busca um cliente Asaas pelo Email ou CPF/CNPJ (GET /customers).
 *
 * @param string $email Email a ser buscado.
 * @param string $cpfCnpj CPF/CNPJ a ser buscado (será limpo).
 * @return array<string, mixed>|null Dados do primeiro cliente encontrado ou null.
 */
function findAsaasCustomerByEmailOrCpf(string $email, string $cpfCnpj): ?array {
     $cpfCnpjCleaned = preg_replace('/[^0-9]/', '', $cpfCnpj);
     logMessage("[Asaas API v1.4 findCustomer] Buscando por Email='$email' OU CPF/CNPJ='$cpfCnpjCleaned'");
     if (!defined('ASAAS_API_URL') || !defined('ASAAS_API_KEY')) {
         logMessage("[Asaas API v1.4 findCustomer] ERRO: Constantes ASAAS não definidas.");
         return null;
     }

     $headers = ['Accept: application/json', 'access_token: ' . ASAAS_API_KEY];
     $baseUrl = rtrim(ASAAS_API_URL, '/'); // Garante que a base não tenha barra final para concatenar

     // 1. Tenta por CPF/CNPJ
     $urlCpf = $baseUrl . '/customers?' . http_build_query(['cpfCnpj' => $cpfCnpjCleaned]);
     logMessage("[Asaas API v1.4 findCustomer] Tentando GET por CPF/CNPJ: $urlCpf");
     $resultCpf = makeCurlRequest($urlCpf, 'GET', $headers);
     if ($resultCpf['httpCode'] == 200 && $resultCpf['is_json'] && isset($resultCpf['response']['data']) && !empty($resultCpf['response']['data'][0])) {
         $customerData = $resultCpf['response']['data'][0];
         logMessage("[Asaas API v1.4 findCustomer] Encontrado por CPF/CNPJ. ID: " . ($customerData['id'] ?? 'N/A'));
         return $customerData;
     } else {
          logMessage("[Asaas API v1.4 findCustomer] Não encontrado por CPF/CNPJ. HTTP: {$resultCpf['httpCode']}.");
     }

     // 2. Tenta por Email
     $urlEmail = $baseUrl . '/customers?' . http_build_query(['email' => $email]);
     logMessage("[Asaas API v1.4 findCustomer] Tentando GET por Email: $urlEmail");
     $resultEmail = makeCurlRequest($urlEmail, 'GET', $headers);
     if ($resultEmail['httpCode'] == 200 && $resultEmail['is_json'] && isset($resultEmail['response']['data']) && !empty($resultEmail['response']['data'][0])) {
         $customerData = $resultEmail['response']['data'][0];
         logMessage("[Asaas API v1.4 findCustomer] Encontrado por Email. ID: " . ($customerData['id'] ?? 'N/A'));
         return $customerData;
     } else {
           logMessage("[Asaas API v1.4 findCustomer] Não encontrado por Email. HTTP: {$resultEmail['httpCode']}.");
     }

     // 3. Não encontrou
     logMessage("[Asaas API v1.4 findCustomer] Cliente não encontrado por Email ou CPF/CNPJ.");
     return null;
 }


/**
 * Cria uma nova assinatura no Asaas e retorna dados incluindo o link de pagamento da primeira cobrança.
 * Usa o checkout redirect (billingType UNDEFINED).
 *
 * @param string $customerId ID do cliente Asaas.
 * @param string|null $externalReference ID de referência externo (opcional).
 * @return array<string, mixed>|null Dados da assinatura e primeira cobrança, ou null.
 */
function createAsaasSubscriptionRedirect(string $customerId, ?string $externalReference = null): ?array {
    logMessage("[Asaas API v1.4 createSub] Tentando criar assinatura (Redirect) para Customer ID: $customerId");
    if (!defined('ASAAS_API_URL') || !defined('ASAAS_API_KEY') || !defined('ASAAS_PLAN_VALUE') || !defined('ASAAS_PLAN_CYCLE') || !defined('ASAAS_PLAN_DESCRIPTION')) {
        logMessage("[Asaas API v1.4 createSub] ERRO: Constantes ASAAS assinatura não definidas.");
        return null;
    }

    $url = rtrim(ASAAS_API_URL, '/') . '/subscriptions'; // Endpoint correto
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'access_token: ' . ASAAS_API_KEY];
    $nextDueDate = date('Y-m-d', strtotime('+3 days')); // Data da primeira cobrança

    $postData = [
        'customer' => $customerId,
        'billingType' => 'UNDEFINED', // Para checkout redirect
        'value' => ASAAS_PLAN_VALUE,
        'nextDueDate' => $nextDueDate,
        'cycle' => ASAAS_PLAN_CYCLE,
        'description' => ASAAS_PLAN_DESCRIPTION,
        'externalReference' => $externalReference
    ];
    logMessage("[Asaas API v1.4 createSub] Payload POST /subscriptions: " . json_encode($postData));
    $result = makeCurlRequest($url, 'POST', $headers, $postData, true);

    if (($result['httpCode'] == 200 || $result['httpCode'] == 201) && $result['is_json'] && isset($result['response']['id'])) {
        $subscriptionData = $result['response'];
        logMessage("[Asaas API v1.4 createSub] Assinatura criada. ID Asaas: " . $subscriptionData['id']);

        // Tenta obter o link de pagamento da primeira cobrança
        $paymentLink = $subscriptionData['invoiceUrl'] ?? null;
        if (!$paymentLink) {
             logMessage("[Asaas API v1.4 createSub] invoiceUrl não veio na resposta. Buscando 1ª cobrança (PENDING)...");
             // Busca a cobrança PENDENTE mais antiga associada
             $paymentsUrl = rtrim(ASAAS_API_URL, '/') . '/payments?' . http_build_query(['subscription' => $subscriptionData['id'], 'limit' => 1, 'status' => 'PENDING', 'order' => 'asc', 'sort' => 'dueDate']);
             $paymentsHeaders = ['Accept: application/json', 'access_token: ' . ASAAS_API_KEY];
             $paymentsResult = makeCurlRequest($paymentsUrl, 'GET', $paymentsHeaders);
             if ($paymentsResult['httpCode'] == 200 && $paymentsResult['is_json'] && isset($paymentsResult['response']['data'][0])) {
                $firstPayment = $paymentsResult['response']['data'][0];
                $subscriptionData['paymentId'] = $firstPayment['id'] ?? null;
                // Prioriza invoiceUrl, depois bankSlipUrl
                $subscriptionData['paymentLink'] = $firstPayment['invoiceUrl'] ?? $firstPayment['bankSlipUrl'] ?? null;
                $paymentLink = $subscriptionData['paymentLink'];
                logMessage("[Asaas API v1.4 createSub] Detalhes 1ª cobrança obtidos. Payment ID: " . ($subscriptionData['paymentId'] ?? 'N/A') . ". Link: " . ($paymentLink ? 'OK' : 'NÃO ENCONTRADO'));
             } else {
                 logMessage("[Asaas API v1.4 createSub] AVISO: Falha obter detalhes 1ª cobrança PENDENTE para sub " . $subscriptionData['id'] . ". HTTP: {$paymentsResult['httpCode']}.");
             }
        } else {
            logMessage("[Asaas API v1.4 createSub] invoiceUrl (" . $paymentLink . ") obtido da criação.");
        }

        $subscriptionData['paymentLink'] = $paymentLink; // Garante que está no array retornado (pode ser null)
        return $subscriptionData;
    } else {
        $errorDetails = $result['is_json'] ? json_encode($result['response']) : ($result['response'] ?? 'N/A');
        logMessage("[Asaas API v1.4 createSub] ERRO criar assinatura Customer $customerId. HTTP: {$result['httpCode']}. API Resp: " . $errorDetails);
        if ($result['httpCode'] == 404) {
            logMessage("[Asaas API v1.4 createSub] !!! ALERTA 404 !!! Verifique URL base, endpoint, API Key.");
        }
        return null;
    }
}


/**
 * Busca o link de pagamento da primeira cobrança PENDENTE ou OVERDUE
 * associada a uma assinatura Asaas existente.
 * Tenta primeiro PENDING, depois OVERDUE.
 *
 * @param string $subscriptionId O ID da assinatura Asaas.
 * @return string|null A URL da fatura/pagamento encontrada, ou null se não houver pendente/vencida ou em caso de erro.
 */
function getAsaasPendingPaymentLink(string $subscriptionId): ?string {
    logMessage("[Asaas API v1.4 getLink] Buscando link pagamento para Sub ID existente: $subscriptionId");
    if (!defined('ASAAS_API_URL') || !defined('ASAAS_API_KEY')) {
        logMessage("[Asaas API v1.4 getLink] ERRO: Constantes ASAAS não definidas.");
        return null;
    }
    if (empty($subscriptionId)) {
        logMessage("[Asaas API v1.4 getLink] ERRO: ID da assinatura vazio.");
        return null;
    }

    $headers = ['Accept: application/json', 'access_token: ' . ASAAS_API_KEY];
    $baseUrl = rtrim(ASAAS_API_URL, '/');
    $statusesToTry = ['PENDING', 'OVERDUE']; // Ordem de prioridade
    $paymentUrlFound = null;

    foreach ($statusesToTry as $status) {
        logMessage("  -> [getLink] Tentando buscar cobrança com status: $status");
        $queryParams = [
            'subscription' => $subscriptionId,
            'status' => $status,
            'limit' => 1,
            'order' => 'asc', // Pega a mais antiga com esse status
            'sort' => 'dueDate'
        ];
        $url = $baseUrl . '/payments?' . http_build_query($queryParams);
        logMessage("  -> [getLink] Chamando GET: $url");
        $result = makeCurlRequest($url, 'GET', $headers);

        if ($result['httpCode'] == 200 && $result['is_json'] && isset($result['response']['data']) && !empty($result['response']['data'][0])) {
            $payment = $result['response']['data'][0];
            $invoiceUrl = $payment['invoiceUrl'] ?? null;
            $bankSlipUrl = $payment['bankSlipUrl'] ?? null;
            $paymentUrlFound = $invoiceUrl ?: $bankSlipUrl; // Prioriza link da fatura

            if ($paymentUrlFound) {
                logMessage("  -> [getLink] Link encontrado (Status: $status, Payment ID: {$payment['id']}): $paymentUrlFound");
                break; // Encontrou, sai do loop
            } else {
                 logMessage("  -> [getLink] Cobrança encontrada (Status: $status, Payment ID: {$payment['id']}), mas sem link de pagamento (invoiceUrl/bankSlipUrl).");
                 // Continua o loop
            }
        } else {
             $errorResp = $result['is_json'] ? json_encode($result['response']) : ($result['response'] ?? 'N/A');
             logMessage("  -> [getLink] Nenhuma cobrança encontrada com status '$status' ou erro API. HTTP: {$result['httpCode']}. Erro cURL: " . ($result['error'] ?? 'N/A') . ". API Resp: " . $errorResp);
             // Continua para o próximo status se houver
        }
    } // Fim foreach status

    if ($paymentUrlFound) {
        logMessage("[Asaas API v1.4 getLink] Link de pagamento final encontrado para Sub ID $subscriptionId: $paymentUrlFound");
    } else {
        logMessage("[Asaas API v1.4 getLink] Nenhum link de pagamento PENDENTE ou OVERDUE encontrado para Sub ID $subscriptionId.");
    }
    return $paymentUrlFound;
}

?>



<?php
/**
 * Arquivo: includes/core_logic.php
 * Versão: v1.0
 * Descrição: Lógica principal de processamento de IA para responder perguntas.
 */

require_once __DIR__ . '/../config.php'; // Para constantes
require_once __DIR__ . '/../db.php';       // Para conexão e criptografia (placeholders)
require_once __DIR__ . '/log_helper.php';
require_once __DIR__ . '/db_interaction.php';
require_once __DIR__ . '/ml_api.php';
require_once __DIR__ . '/gemini_api.php';
require_once __DIR__ . '/evolution_api.php';


/**
 * **Processamento de Resposta Automática via IA (v32.6 - Logs Notificação Aprimorados)**
 *
 * Orquestra o processo completo para responder a uma pergunta do Mercado Livre usando IA.
 *
 * @param int $questionId ID da pergunta do Mercado Livre a ser processada.
 * @return bool `true` se a IA gerou e postou com sucesso no ML, `false` caso contrário.
 */
function triggerAiForQuestion(int $questionId): bool
{
    $functionVersion = "v32.6";
    logMessage("      [AI_TRIGGER QID $questionId] --- INÍCIO IA ($functionVersion - SEM VALIDAÇÃO) ---");
    $pdo = null; $mlUserId = 0; $itemId = 'N/A'; $qSaasUserId = null; $whatsappTargetJid = null; $currentAccessToken = null; $refreshToken = null; $questionText = null; $logEntry = null; $mlConnectionDbId = null; $itemTitle = '[Item não carregado]';

    try {
        // 1. Conexão DB e Busca Log
        logMessage("      [AI_TRIGGER QID $questionId] Obtendo DB..."); $pdo = getDbConnection(); $now = new DateTimeImmutable();
        logMessage("      [AI_TRIGGER QID $questionId] Buscando log..."); $logEntry = getQuestionLogStatus($questionId);
        if (!$logEntry) { logMessage("      [AI_TRIGGER QID $questionId] ERRO FATAL: Log não encontrado."); return false; }
        $mlUserId = isset($logEntry['ml_user_id']) ? (int)$logEntry['ml_user_id'] : 0; $itemId = $logEntry['item_id'] ?? 'N/A'; $questionText = $logEntry['question_text'] ?? null; $qSaasUserId = isset($logEntry['saas_user_id']) ? (int)$logEntry['saas_user_id'] : null;
        if ($mlUserId <= 0 || empty($itemId) || $itemId === 'N/A' || empty(trim((string)$questionText))) { logMessage("      [AI_TRIGGER QID $questionId] ERRO FATAL: Dados inválidos no log."); @upsertQuestionLog($questionId, $mlUserId ?: 0, $itemId ?: 'N/A', 'ERROR', $questionText, null, null, 'Dados inválidos log IA', $qSaasUserId); return false; }
        logMessage("      [AI_TRIGGER QID $questionId] Log OK. Status DB: '{$logEntry['status']}'. ML UID: $mlUserId.");

        // 2. Busca JID
        if ($qSaasUserId) { $stmtSaas = $pdo->prepare("SELECT whatsapp_jid FROM saas_users WHERE id = :id AND is_saas_active = TRUE LIMIT 1"); $stmtSaas->execute([':id' => $qSaasUserId]); $saasUser = $stmtSaas->fetch(); $whatsappTargetJid = ($saasUser && !empty($saasUser['whatsapp_jid'])) ? $saasUser['whatsapp_jid'] : null; logMessage("      [AI_TRIGGER QID $questionId] JID Notificação: " . ($whatsappTargetJid ?: 'Nenhum')); } else { logMessage("      [AI_TRIGGER QID $questionId] Aviso: SaaS User ID não encontrado no log."); }

        // 3. Busca Credenciais ML
        logMessage("      [AI_TRIGGER QID $questionId] Buscando credenciais ML..."); $stmtMLUser = $pdo->prepare("SELECT id, access_token, refresh_token, token_expires_at FROM mercadolibre_users WHERE ml_user_id = :ml_uid AND saas_user_id = :saas_uid AND is_active = TRUE LIMIT 1"); $stmtMLUser->execute([':ml_uid' => $mlUserId, ':saas_uid' => $qSaasUserId]); $mlUserConn = $stmtMLUser->fetch();
        if (!$mlUserConn) { logMessage("      [AI_TRIGGER QID $questionId] ERRO FATAL: Conexão ML $mlUserId (SaaS $qSaasUserId) inativa/não encontrada."); upsertQuestionLog($questionId, $mlUserId, $itemId, 'ERROR', $questionText, null, null, 'Conn ML inativa IA', $qSaasUserId); return false; }
        $mlConnectionDbId = $mlUserConn['id'];
        try { /* !! SECURITY !! */ $currentAccessToken = decryptData($mlUserConn['access_token']); $refreshToken = decryptData($mlUserConn['refresh_token']); } catch (Exception $e) { logMessage("      [AI_TRIGGER QID $questionId] ERRO CRÍTICO decrypt tokens. Desativando. ".$e->getMessage()); @$pdo->exec("UPDATE mercadolibre_users SET is_active = FALSE, updated_at = NOW() WHERE id=".$mlConnectionDbId); upsertQuestionLog($questionId, $mlUserId, $itemId, 'ERROR', $questionText, null,null,null,'Falha decrypt tokens (IA)', $qSaasUserId); return false; }
        logMessage("      [AI_TRIGGER QID $questionId] Tokens descriptografados (placeholder).");

        // 4. Refresh Token
        logMessage("      [AI_TRIGGER QID $questionId] Verificando refresh token..."); try { $tokenExpiresAtStr = $mlUserConn['token_expires_at']; if (empty($tokenExpiresAtStr)) { throw new Exception("Data expiração vazia."); } $tokenExpiresAt = new DateTimeImmutable($tokenExpiresAtStr); if ($now >= $tokenExpiresAt->modify("-10 minutes")) { logMessage("      [AI_TRIGGER QID $questionId] Refresh necessário..."); $refreshResult = refreshMercadoLibreToken($refreshToken); if ($refreshResult['httpCode'] == 200 && isset($refreshResult['response']['access_token'])) { $newData = $refreshResult['response']; $currentAccessToken = $newData['access_token']; $newRefreshToken = $newData['refresh_token'] ?? $refreshToken; $newExpiresIn = $newData['expires_in'] ?? 21600; $newExpAt = $now->modify("+" . (int)$newExpiresIn . " seconds")->format('Y-m-d H:i:s'); try { /* !! SECURITY !! */ $encAT = encryptData($currentAccessToken); $encRT = encryptData($newRefreshToken); } catch(Exception $e) { logMessage("      [AI_TRIGGER QID $questionId] ERRO CRÍTICO encrypt pós-refresh. ".$e->getMessage()); throw $e; } $upSql = "UPDATE mercadolibre_users SET access_token = :at, refresh_token = :rt, token_expires_at = :exp, updated_at = NOW() WHERE id = :id"; $upStmt = $pdo->prepare($upSql); $upStmt->execute([':at'=>$encAT, ':rt'=>$encRT, ':exp'=>$newExpAt, ':id'=>$mlConnectionDbId]); $refreshToken = $newRefreshToken; logMessage("      [AI_TRIGGER QID $questionId] Refresh OK."); } else { logMessage("      [AI_TRIGGER QID $questionId] ERRO FATAL refresh API ML. Desativando. Code: {$refreshResult['httpCode']}."); @$pdo->exec("UPDATE mercadolibre_users SET is_active=FALSE, updated_at = NOW() WHERE id=".$mlConnectionDbId); upsertQuestionLog($questionId, $mlUserId, $itemId, 'ERROR', $questionText, null,null,null,'Falha refresh token API (IA)', $qSaasUserId); return false; } } else { logMessage("      [AI_TRIGGER QID $questionId] Refresh não necessário."); } } catch (Exception $ex) { logMessage("      [AI_TRIGGER QID $questionId] ERRO validação/refresh token: ".$ex->getMessage()); upsertQuestionLog($questionId, $mlUserId, $itemId, 'ERROR', $questionText, null,null,null,'Erro token ML (IA): '.substr($ex->getMessage(),0,100), $qSaasUserId); return false; }
        if(empty($currentAccessToken)) { logMessage("      [AI_TRIGGER QID $questionId] ERRO FATAL: Access token vazio."); @upsertQuestionLog($questionId, $mlUserId, $itemId, 'ERROR', $questionText, null, null, null, 'Token ML vazio (IA)', $qSaasUserId); return false; }
        logMessage("      [AI_TRIGGER QID $questionId] Token ML pronto.");

        // 5. Verifica Status ML
        logMessage("      [AI_TRIGGER QID $questionId] Verificando status pergunta ML..."); $mlQuestionData = getMercadoLibreQuestionStatus($questionId, $currentAccessToken); $currentMLStatus = $mlQuestionData['response']['status'] ?? 'UNKNOWN'; if ($mlQuestionData['httpCode'] != 200) { logMessage("      [AI_TRIGGER QID $questionId] ERRO API ML status check."); upsertQuestionLog($questionId, $mlUserId, $itemId, 'ERROR', $questionText, null, null, null, "Falha API ML status check (Code: {$mlQuestionData['httpCode']})", $qSaasUserId); return false; } if ($currentMLStatus !== 'UNANSWERED') { $finalStatus = ($currentMLStatus === 'ANSWERED') ? 'HUMAN_ANSWERED_ON_ML' : strtoupper($currentMLStatus); if (!in_array($finalStatus, ['HUMAN_ANSWERED_ON_ML', 'DELETED', 'CLOSED_UNANSWERED', 'UNDER_REVIEW', 'BANNED'])) { $finalStatus = 'UNKNOWN_ML_STATUS_' . $currentMLStatus; } upsertQuestionLog($questionId, $mlUserId, $itemId, $finalStatus, $questionText, null, null, null, $qSaasUserId, null, $logEntry['whatsapp_notification_message_id'] ?? null); logMessage("      [AI_TRIGGER QID $questionId] Pergunta ML não 'UNANSWERED' ($currentMLStatus). Saindo."); return false; } logMessage("      [AI_TRIGGER QID $questionId] Status ML confirmado 'UNANSWERED'.");

        // 6. Atualiza Status Log
        upsertQuestionLog($questionId, $mlUserId, $itemId, 'AI_PROCESSING', null, null, null, null, $qSaasUserId, null, $logEntry['whatsapp_notification_message_id'] ?? null); logMessage("      [AI_TRIGGER QID $questionId] Status log interno -> 'AI_PROCESSING'.");

        // 7. Busca Detalhes Item
        logMessage("      [AI_TRIGGER QID $questionId] Buscando detalhes item $itemId..."); $itemResult = getMercadoLibreItemDetails($itemId, $currentAccessToken); if ($itemResult['httpCode'] != 200 || !$itemResult['is_json'] || !isset($itemResult['response']['id'])) { logMessage("      [AI_TRIGGER QID $questionId] ERRO detalhes item $itemId."); upsertQuestionLog($questionId, $mlUserId, $itemId, 'AI_FAILED', $questionText, null, null, null, "Falha obter detalhes item $itemId (IA)", $qSaasUserId, null, $logEntry['whatsapp_notification_message_id'] ?? null); return false; } $itemData = $itemResult['response']; $itemTitle = $itemData['title'] ?? '[Título indisponível]'; // ATUALIZA itemTitle aqui
        $attributesText = "";
        if (!empty($itemData['attributes'])) { $count = 0; foreach ($itemData['attributes'] as $attr) { if ($count >= 15) break; $name = $attr['name'] ?? null; $value = $attr['value_name'] ?? null; if ($name && $value && !empty(trim($value))) { $attributesText .= "- " . htmlspecialchars(trim($name)) . ": " . htmlspecialchars(trim($value)) . "\n"; $count++; } } }
        if (empty(trim($attributesText))) { $attributesText = "Nenhum atributo técnico relevante disponível."; }
        logMessage("      [AI_TRIGGER QID $questionId] Detalhes item OK. Atributos formatados.");

        // --- 8. Define Prompt Agente 1 ---
        logMessage("      [AI_TRIGGER QID $questionId] Montando prompt Agente 1 (Gemini com Grounding)...");
         $systemPromptAgent1 = "Você é um assistente virtual de vendas especialista em responder perguntas de clientes em anúncios do Mercado Livre. Seu objetivo é fornecer respostas claras, concisas, educadas e que incentivem a compra, baseadas **prioritariamente** nas informações do produto fornecidas (título, atributos técnicos) **e complementadas pelos resultados de busca da ferramenta Google Search, se disponíveis e relevantes**.\n\n**Regras Importantes:**\n*   **Priorize Dados do Anúncio:** Use o título e os atributos fornecidos como fonte primária.\n*   **Use a Busca:** Complemente com informações da busca do Google APENAS se os dados do anúncio forem insuficientes ou se a busca fornecer um detalhe crucial diretamente relacionado à pergunta. NÃO confie cegamente na busca se ela contradisser os dados do anúncio.\n*   **NÃO Invente:** JAMAIS crie informações, características, estoque, cores ou compatibilidades que não estejam explicitamente listados nos dados do anúncio ou confirmados pela busca relevante.\n*   **Seja Honesto:** Se a informação solicitada não estiver disponível nem no anúncio nem na busca, informe educadamente que não possui esse detalhe específico no momento. Se possível, reforce um ponto positivo conhecido do produto ou sugira uma alternativa se aplicável e seguro.\n*   **Objetividade:** Vá direto ao ponto. Evite saudações genéricas como 'Olá!', 'Bom dia!' ou despedidas longas como 'Aguardamos sua compra!'.\n*   **Concisão:** Mantenha a resposta o mais curta e objetiva possível, idealmente **abaixo de 250 caracteres**, para caber no campo de resposta do Mercado Livre.\n*   **Tom:** Mantenha um tom profissional, prestativo e vendedor.\n*   **Não faça perguntas de volta:** Apenas responda à pergunta do cliente.";
         $userPromptAgent1 = "Instrução Principal:\n{$systemPromptAgent1}\n\n--- CONTEXTO DO PRODUTO (ID do Anúncio no ML: {$itemId}) ---\n* Título do Anúncio: {$itemTitle}\n* Atributos Técnicos Disponíveis:\n{$attributesText}\n-----------------------------------\n\n--- PERGUNTA ORIGINAL DO CLIENTE ---\n{$questionText}\n-----------------------------------\n\nTarefa: Gere a resposta para a pergunta do cliente seguindo RIGOROSAMENTE a Instrução Principal, usando o Contexto do Produto e os resultados da ferramenta de busca (se disponíveis e relevantes). Retorne APENAS o texto da resposta.";
         $geminiDataAgent1 = [ 'contents' => [['role' => 'user', 'parts' => [['text' => $userPromptAgent1]]]], 'generationConfig' => [ 'temperature' => 0.7, 'maxOutputTokens' => 300, 'stopSequences' => ["\n\n"] ] ];
         logMessage("      [AI_TRIGGER QID $questionId] Prompt Agente 1 definido.");

        // --- 9. Chama Agente 1 (Gerador) com Grounding ---
        logMessage("      [AI_TRIGGER QID $questionId] Chamando Agente 1 (API Gemini) COM Grounding...");
        $geminiResult1 = callGeminiAPI($geminiDataAgent1, true);

        // --- Extração e Validação Resposta Agente 1 ---
        $agent1ResponseText = null; $agent1ErrorReason = '';
        if ($geminiResult1['httpCode'] == 200 && isset($geminiResult1['response'])) { $responseBody = $geminiResult1['response']; if (isset($responseBody['promptFeedback']['blockReason'])) { $agent1ErrorReason = 'Blocked by API: ' . $responseBody['promptFeedback']['blockReason']; if (isset($responseBody['promptFeedback']['safetyRatings'])) { $agent1ErrorReason .= ' Ratings: ' . json_encode($responseBody['promptFeedback']['safetyRatings']); } logMessage("      [AI_TRIGGER QID $questionId] Agente 1 - BLOQUEADO API. Razão: " . $agent1ErrorReason); } elseif (isset($responseBody['candidates'][0])) { $candidate = $responseBody['candidates'][0]; $finishReason = $candidate['finishReason'] ?? 'UNKNOWN'; if (!in_array($finishReason, ['STOP', 'MAX_TOKENS'])) { $agent1ErrorReason = 'Finished Abnormally: ' . $finishReason; if (isset($candidate['safetyRatings'])) { $agent1ErrorReason .= ' Ratings: ' . json_encode($candidate['safetyRatings']); } logMessage("      [AI_TRIGGER QID $questionId] Agente 1 - Finalização ANORMAL. Razão: " . $agent1ErrorReason); } if (isset($candidate['content']['parts']) && is_array($candidate['content']['parts'])) { $textParts = []; foreach ($candidate['content']['parts'] as $part) { if (isset($part['text']) && is_string($part['text'])) { $textParts[] = $part['text']; } } if (!empty($textParts)) { $agent1ResponseText = trim(implode("\n", $textParts)); if (!empty($agent1ResponseText) && $agent1ErrorReason !== '' && strpos($agent1ErrorReason, 'Blocked by API') === false) { logMessage("      [AI_TRIGGER QID $questionId] Agente 1 - Texto extraído ('".mb_substr($agent1ResponseText,0,30)."...') apesar finalização anormal ('$finishReason')."); } } elseif (empty($agent1ErrorReason)) { $agent1ErrorReason = 'No text parts found.'; logMessage("      [AI_TRIGGER QID $questionId] Agente 1 - Nenhuma parte de texto encontrada."); } } elseif (empty($agent1ErrorReason)) { $agent1ErrorReason = 'Content parts not found/invalid.'; logMessage("      [AI_TRIGGER QID $questionId] Agente 1 - Campo 'parts' inválido/não encontrado."); } } elseif (empty($agent1ErrorReason)) { $agent1ErrorReason = 'No candidates found.'; logMessage("      [AI_TRIGGER QID $questionId] Agente 1 - Nenhum candidato (HTTP 200)."); }
        } else { $httpCode = $geminiResult1['httpCode'] ?? 'N/A'; $curlError = $geminiResult1['error'] ?? 'N/A'; $responseDetail = json_encode($geminiResult1['response'] ?? 'N/A'); $agent1ErrorReason = "API Call Failed. HTTP Error: {$httpCode}. cURL Error: {$curlError}. Response: {$responseDetail}"; logMessage("      [AI_TRIGGER QID $questionId] Agente 1 - Falha requisição/resposta inválida. " . $agent1ErrorReason); }

        // --- Validação Final Resposta Agente 1 ---
        if ($agent1ResponseText === null || empty(trim($agent1ResponseText)) || strpos($agent1ErrorReason, 'Blocked by API') !== false) {
            $finalErrorMessage = 'Falha geração IA (Agente 1)'; if (!empty($agent1ErrorReason)) { $finalErrorMessage .= " - Razão: " . $agent1ErrorReason; } elseif ($agent1ResponseText !== null && empty(trim($agent1ResponseText))) { $finalErrorMessage .= ' - Resposta vazia.'; logMessage("      [AI_TRIGGER QID $questionId] ERRO Agente 1: Resposta gerada vazia."); } else { $finalErrorMessage .= ' - Resposta nula/falha extração.'; }
            logMessage("      [AI_TRIGGER QID $questionId] ABORTANDO IA. " . $finalErrorMessage);
            upsertQuestionLog($questionId, $mlUserId, $itemId, 'AI_FAILED', $questionText, null, null, $finalErrorMessage, $qSaasUserId, $agent1ResponseText, $logEntry['whatsapp_notification_message_id'] ?? null); return false;
        }

        // --- Sucesso Geração Agente 1 ---
        $agent1ResponseText = trim($agent1ResponseText); logMessage("      [AI_TRIGGER QID $questionId] Agente 1 retornou resposta válida: '$agent1ResponseText'");

        // --- 10. Pular Validação (Agente 2 Removido v32.0) ---
        logMessage("      [AI_TRIGGER QID $questionId] *** Lógica Agente 2 (Validação) REMOVIDA ($functionVersion) ***");
        $answerResponseTextFinal = $agent1ResponseText;

        // --- 11. Tenta Postar no Mercado Livre ---
        logMessage("      [AI_TRIGGER QID $questionId] Tentando postar resposta final (Agente 1) no ML...");
        $answerResult = postMercadoLibreAnswer($questionId, $answerResponseTextFinal, $currentAccessToken);
        $aiAnsweredTimestamp = $now->format('Y-m-d H:i:s');

        // --- 12. Processa Resultado Postagem ML ---
        if ($answerResult['httpCode'] == 200 || $answerResult['httpCode'] == 201) {
            // SUCESSO POST ML
            logMessage("      [AI_TRIGGER QID $questionId] Resposta postada SUCESSO no ML (Agente 1 direto).");
            upsertQuestionLog($questionId, $mlUserId, $itemId, 'AI_ANSWERED', null, null, $aiAnsweredTimestamp, null, $qSaasUserId, $answerResponseTextFinal, $logEntry['whatsapp_notification_message_id'] ?? null, null);

            // --- Bloco de Notificação de Sucesso (com logs adicionais v32.6) ---
            logMessage("      [AI_TRIGGER QID $questionId] Verificando JID para notificação de sucesso: " . ($whatsappTargetJid ? $whatsappTargetJid : 'NULO/VAZIO'));
            if ($whatsappTargetJid) {
                logMessage("      [AI_TRIGGER QID $questionId] JID válido ($whatsappTargetJid). Preparando para enviar notificação de sucesso...");
                try {
                    logMessage("      [AI_TRIGGER QID $questionId] Construindo mensagem de sucesso Wpp...");
                    $waMsg = "🤖 *Resposta Automática Enviada (IA)*\n\n";
                    $waMsg .= "A pergunta sobre o item '$itemTitle' (ID: $itemId) foi respondida automaticamente pela IA:\n\n"; // Usa $itemTitle carregado
                    $waMsg .= "*Pergunta Original:* ```" . htmlspecialchars($questionText) . "```\n";
                    $waMsg .= "*Resposta Enviada:* ```" . htmlspecialchars($answerResponseTextFinal) . "```\n\n";
                    $waMsg .= "_(Ref. Pergunta ML: $questionId)_";

                    logMessage("      [AI_TRIGGER QID $questionId] Tentando enviar notificação de sucesso para $whatsappTargetJid via sendWhatsAppNotification...");
                    $notificationResultId = sendWhatsAppNotification($whatsappTargetJid, $waMsg); // Chama a função de texto simples (v32.7)

                    if ($notificationResultId) {
                         logMessage("      [AI_TRIGGER QID $questionId] Notificação WhatsApp de sucesso enviada para $whatsappTargetJid (Msg ID: $notificationResultId).");
                    } else {
                         logMessage("      [AI_TRIGGER QID $questionId] AVISO: Falha ao enviar notificação WhatsApp de sucesso para $whatsappTargetJid (sendWhatsAppNotification retornou NULO/FALSO). Verificar logs da função sendWhatsAppNotification.");
                    }
                } catch (Exception $e) {
                    logMessage("      [AI_TRIGGER QID $questionId] AVISO: Exceção ao tentar enviar notificação WhatsApp de sucesso: " . $e->getMessage());
                }
            } else {
                logMessage("      [AI_TRIGGER QID $questionId] Sucesso na postagem ML, mas sem JID do WhatsApp para notificar.");
            }
            // --- Fim Bloco de Notificação ---

            logMessage("      [AI_TRIGGER QID $questionId] --- FIM IA (SUCESSO - Agente 1 Direto) ---"); return true;
        } else {
            // FALHA POST ML
            logMessage("      [AI_TRIGGER QID $questionId] ERRO post resposta (Agente 1) no ML. HTTP: {$answerResult['httpCode']}. Resp API ML: ". json_encode($answerResult['response']));
            $postErrorMessage = "Falha postar resposta IA no ML (Code: {$answerResult['httpCode']}) (v $functionVersion)";
            upsertQuestionLog($questionId, $mlUserId, $itemId, 'AI_FAILED', null, null, null, $postErrorMessage, $qSaasUserId, $answerResponseTextFinal, $logEntry['whatsapp_notification_message_id'] ?? null, null);
            logMessage("      [AI_TRIGGER QID $questionId] --- FIM IA (ERRO POST ML - Agente 1 Direto) ---"); return false;
        }

    } catch (\Throwable $e) {
        // --- Erro Fatal Inesperado ---
        logMessage("      [AI_TRIGGER QID $questionId] **** ERRO FATAL INESPERADO IA ****"); logMessage("      Mensagem: {$e->getMessage()} | Arquivo: " . basename($e->getFile()) . " | Linha: {$e->getLine()}");
        if ($questionId > 0 && $mlUserId > 0) { $errorMsgForDb = 'Exceção fatal IA: ' . mb_substr($e->getMessage(), 0, 150); @upsertQuestionLog( $questionId, ($mlUserId ?? 0), ($itemId ?? 'N/A'), 'ERROR', ($questionText ?? null), null, null, $errorMsgForDb, ($qSaasUserId ?? null), null, ($logEntry['whatsapp_notification_message_id'] ?? null) ); } else { logMessage("      [AI_TRIGGER QID $questionId] Não foi possível atualizar log erro fatal."); }
        logMessage("      [AI_TRIGGER QID $questionId] --- FIM IA (ERRO FATAL INESPERADO) ---"); return false;
    }
} // --- Fim da função triggerAiForQuestion ---

?>


<?php
/**
 * Arquivo: includes/curl_helper.php
 * Versão: v1.3 - Corrige cURL Error #3 (No URL set)
 * Descrição: Helper para realizar requisições cURL.
 *            Adicionado curl_setopt individual para CURLOPT_URL para garantir que a URL seja definida.
 */

require_once __DIR__ . '/log_helper.php'; // Para logar erros

/**
 * Realiza uma requisição HTTP genérica usando a biblioteca cURL do PHP.
 *
 * @param string $url A URL completa do endpoint.
 * @param string $method O método HTTP ('GET', 'POST', 'PUT', 'DELETE', etc.).
 * @param array<int, string> $headers Array de strings de cabeçalho HTTP.
 * @param mixed $postData Dados a serem enviados no corpo da requisição (para POST/PUT, etc.).
 *                        Se $isJson for true, deve ser um array ou objeto PHP.
 *                        Se $isJson for false, deve ser um array para form-urlencoded ou string.
 * @param bool $isJson Se true, codifica $postData como JSON e define Content-Type apropriado.
 *                     Se false, envia como application/x-www-form-urlencoded (se $postData for array).
 * @return array<string, mixed> Retorna um array com:
 *         - 'httpCode': (int) Código de status HTTP.
 *         - 'error': (string|null) Mensagem de erro cURL ou da função, ou null se sucesso.
 *         - 'response': (mixed) Corpo da resposta decodificado (se JSON) ou string bruta.
 *         - 'is_json': (bool) True se a resposta foi decodificada como JSON com sucesso.
 */
function makeCurlRequest(string $url, string $method = 'GET', array $headers = [], $postData = null, bool $isJson = false): array
{
    // Identifica quem chamou a função para logs mais claros
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $callerFunction = $backtrace[1]['function'] ?? 'unknown_caller';
    $defaultReturn = ['httpCode' => 0, 'error' => 'Unknown cURL/Function Error', 'response' => null, 'is_json' => false];

    logMessage("[makeCurlRequest v1.3 from $callerFunction] Método: $method. URL Final: $url"); // Log da URL confirmada

    $ch = curl_init();
    if (!$ch) {
        logMessage("[makeCurlRequest v1.3 from $callerFunction] ERRO CRÍTICO: curl_init() falhou para $url");
        return $defaultReturn;
    }

    // Configuração de Log Verbose cURL (útil para depuração profunda)
    $logFilePath = defined('LOG_FILE') ? dirname(LOG_FILE) . '/curl_verbose.log' : '/tmp/curl_verbose.log'; // Log verbose em arquivo separado
    $logHandle = @fopen($logFilePath, 'a'); // 'a' para append
    if (!$logHandle) {
        logMessage("[makeCurlRequest v1.3 from $callerFunction] AVISO: Não foi possível abrir o arquivo de log verbose cURL '$logFilePath'. Verifique permissões.");
    }

    try {
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_USERAGENT => 'SaaS-Responder-ML/1.0 (+https://d3ecom.com.br/meliai)',
            CURLOPT_HTTPHEADER => $headers, // Define headers iniciais
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_VERBOSE => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, // Mantido por enquanto
            // NÃO incluir CURLOPT_URL aqui no array inicial
        ];

        // Define o stream para o log verbose
        if ($logHandle) {
            $curlOptions[CURLOPT_STDERR] = $logHandle;
        }

        // Define as opções do array
        curl_setopt_array($ch, $curlOptions);

        // *** CORREÇÃO: Define a URL explicitamente APÓS curl_setopt_array ***
        if (!curl_setopt($ch, CURLOPT_URL, $url)) {
            if ($logHandle) @fclose($logHandle);
            @curl_close($ch);
            logMessage("[makeCurlRequest v1.3 from $callerFunction] ERRO CRÍTICO: curl_setopt(CURLOPT_URL) falhou para $url");
            return ['httpCode' => 0, 'error' => 'Falha ao definir CURLOPT_URL', 'response' => null, 'is_json' => false];
        }
        // *** FIM DA CORREÇÃO ***

        // Adiciona dados POST/PUT/PATCH se houver
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH']) && $postData !== null) {
             $payload = '';
             $contentTypeHeaderToAdd = null;
             if ($isJson) {
                 $payload = json_encode($postData);
                 if (json_last_error() !== JSON_ERROR_NONE) { throw new Exception('Erro ao codificar dados para JSON: ' . json_last_error_msg()); }
                 $contentTypeHeaderToAdd = 'Content-Type: application/json';
                 logMessage("[makeCurlRequest v1.3 from $callerFunction] Enviando payload JSON.");
                 $currentHeaders = curl_getinfo($ch, CURLINFO_HEADER_OUT); // Pega headers atuais para verificar
                 $hasContentLength = stripos($currentHeaders ?? '', 'Content-Length:') !== false;
                 if (!$hasContentLength) { $headers[] = 'Content-Length: ' . strlen($payload); curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); } // Atualiza headers com CL
             } else {
                 if (is_array($postData)) { $payload = http_build_query($postData); $contentTypeHeaderToAdd = 'Content-Type: application/x-www-form-urlencoded'; logMessage("[makeCurlRequest v1.3 from $callerFunction] Enviando payload form-urlencoded."); }
                 else { $payload = (string) $postData; logMessage("[makeCurlRequest v1.3 from $callerFunction] Enviando payload string bruto."); }
             }
             curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
             $currentHeaders = curl_getinfo($ch, CURLINFO_HEADER_OUT); // Pega headers novamente
             $hasContentType = stripos($currentHeaders ?? '', 'Content-Type:') !== false;
             if (!$hasContentType && $contentTypeHeaderToAdd) { $headers[] = $contentTypeHeaderToAdd; curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); } // Atualiza headers com CT se necessário
        }

        // Executa a requisição
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrorNum = curl_errno($ch);
        $curlErrorMsg = curl_error($ch);

        if ($logHandle) { @fclose($logHandle); }
        curl_close($ch);

        if ($response === false) { throw new Exception("Erro na execução do cURL (#$curlErrorNum): " . $curlErrorMsg); }
        $responseData = json_decode((string)$response, true); $isJsonResult = (json_last_error() === JSON_ERROR_NONE);
        $logRespPreview = $isJsonResult ? '[JSON Decodificado]' : '[String Bruta] ' . mb_substr((string)$response, 0, 100) . '...';
        logMessage("[makeCurlRequest v1.3 from $callerFunction] Resultado: HTTP $httpCode. Resposta: $logRespPreview");

        return ['httpCode' => $httpCode, 'error' => null, 'response' => $isJsonResult ? $responseData : $response, 'is_json' => $isJsonResult];

    } catch (\Throwable $e) {
        $errorMsg = "Exceção em makeCurlRequest v1.3 ($callerFunction) para $url: " . $e->getMessage();
        logMessage($errorMsg);
        if (isset($ch) && (is_resource($ch) || (gettype($ch) === 'object' && get_class($ch) === 'CurlHandle'))) { @curl_close($ch); }
        if (isset($logHandle) && is_resource($logHandle)) { @fclose($logHandle); }
        return ['httpCode' => 0, 'error' => $errorMsg, 'response' => null, 'is_json' => false];
    }
}
?>



<?php
/**
 * Arquivo: includes/db_interaction.php
 * Versão: v1.0
 * Descrição: Funções para interagir com a tabela de log `question_processing_log`.
 */

require_once __DIR__ . '/log_helper.php';
require_once __DIR__ . '/../db.php'; // Para getDbConnection()

/**
 * Busca o estado atual e os dados de uma pergunta específica no log interno (`question_processing_log`).
 * @param int $questionId O ID da pergunta do Mercado Livre.
 * @return array<string, mixed>|false Retorna um array associativo ou `false`.
 * @throws PDOException Em caso de falha grave.
 */
function getQuestionLogStatus(int $questionId): array|false
{
    if ($questionId <= 0) { logMessage("[getQuestionLogStatus] Tentativa de busca com ID inválido: $questionId"); return false; }
    try {
        $pdo = getDbConnection(); $stmt = $pdo->prepare("SELECT * FROM question_processing_log WHERE ml_question_id = :qid LIMIT 1");
        $stmt->execute([':qid' => $questionId]); $logEntry = $stmt->fetch();
        if (is_array($logEntry)) { return $logEntry; } else { if ($logEntry !== false) { logMessage("[getQuestionLogStatus] AVISO: fetch() QID $questionId retornou tipo inesperado: " . gettype($logEntry)); } return false; }
    } catch (\PDOException $e) { logMessage("[getQuestionLogStatus] ERRO DB ao buscar QID $questionId: " . $e->getMessage()); return false; }
}

/**
 * Insere ou atualiza um registro no log de processamento de perguntas (`question_processing_log`).
 * @param int $questionId ID da pergunta ML.
 * @param int $mlUserId ID do vendedor ML.
 * @param string $itemId ID do item ML.
 * @param string $status Novo status da pergunta.
 * @param string|null $questionText Texto da pergunta (opcional).
 * @param string|null $sentAtTimestamp Timestamp ISO 8601 do envio ao WhatsApp (opcional).
 * @param string|null $aiAnsweredTimestamp Timestamp ISO 8601 da resposta da IA (opcional).
 * @param string|null $errorMessage Mensagem de erro (opcional, SEMPRE atualiza).
 * @param int|null $saasUserId ID do usuário SaaS associado (opcional).
 * @param string|null $iaResponseText Texto da resposta gerada pela IA (opcional).
 * @param string|null $whatsappMsgId ID da mensagem de notificação no WhatsApp (opcional).
 * @param string|null $humanAnsweredTimestamp Timestamp ISO 8601 da resposta humana via WhatsApp (opcional).
 * @return bool True se a operação foi bem-sucedida, False em caso de falha.
 * @throws PDOException Em caso de falha grave.
 */
function upsertQuestionLog( int $questionId, int $mlUserId, string $itemId, string $status, ?string $questionText = null, ?string $sentAtTimestamp = null, ?string $aiAnsweredTimestamp = null, ?string $errorMessage = null, ?int $saasUserId = null, ?string $iaResponseText = null, ?string $whatsappMsgId = null, ?string $humanAnsweredTimestamp = null ): bool
{
    if (($questionId <= 0 || $mlUserId <= 0) && strtoupper($status) !== 'ERROR') { logMessage("[upsertQuestionLog] ERRO: Upsert com QID ($questionId) ou ML UID ($mlUserId) inválido para status '$status'."); return false; }
    $maxLengthErrorMessage = 250; $truncatedErrorMessage = $errorMessage !== null ? mb_substr((string)$errorMessage, 0, $maxLengthErrorMessage) : null;
    if ($errorMessage !== null && mb_strlen($errorMessage) > $maxLengthErrorMessage) { logMessage("[upsertQuestionLog] Aviso: Msg erro truncada QID $questionId."); }
    logMessage("[upsertQuestionLog] Iniciando QID: $questionId. Status: '$status'. ML UID: $mlUserId.");
    try {
        $pdo = getDbConnection();
        $sql = "INSERT INTO question_processing_log (ml_question_id, ml_user_id, saas_user_id, item_id, question_text, status, sent_to_whatsapp_at, ai_answered_at, human_answered_at, error_message, ia_response_text, whatsapp_notification_message_id, created_at, last_processed_at) VALUES (:qid, :ml_uid, :saas_uid, :item_id, :q_text, :status, :sent_at, :ai_at, :human_at, :err_msg, :ia_resp, :wa_msg_id, NOW(), NOW()) ON DUPLICATE KEY UPDATE ml_user_id = VALUES(ml_user_id), saas_user_id = COALESCE(VALUES(saas_user_id), saas_user_id), item_id = VALUES(item_id), question_text = COALESCE(VALUES(question_text), question_text), status = VALUES(status), sent_to_whatsapp_at = COALESCE(VALUES(sent_to_whatsapp_at), sent_to_whatsapp_at), ai_answered_at = COALESCE(VALUES(ai_answered_at), ai_answered_at), human_answered_at = COALESCE(VALUES(human_answered_at), human_answered_at), error_message = VALUES(error_message), ia_response_text = COALESCE(VALUES(ia_response_text), ia_response_text), whatsapp_notification_message_id = COALESCE(VALUES(whatsapp_notification_message_id), whatsapp_notification_message_id), last_processed_at = NOW()";
        $stmt = $pdo->prepare($sql); if (!$stmt) { logMessage("[upsertQuestionLog QID: $questionId] ERRO CRÍTICO: Falha preparar query."); return false; }
        $paramsToBind = [ ':qid' => $questionId, ':ml_uid' => $mlUserId, ':saas_uid' => $saasUserId, ':item_id' => $itemId, ':q_text' => $questionText, ':status' => $status, ':sent_at' => $sentAtTimestamp, ':ai_at' => $aiAnsweredTimestamp, ':human_at' => $humanAnsweredTimestamp, ':err_msg' => $truncatedErrorMessage, ':ia_resp' => $iaResponseText, ':wa_msg_id' => $whatsappMsgId ];
        $success = $stmt->execute($paramsToBind);
        if (!$success) { $errorInfo = $stmt->errorInfo(); logMessage("[upsertQuestionLog QID: $questionId] ERRO executar upsert status '$status'. Info: " . ($errorInfo[2] ?? 'N/A')); }
        return $success;
    } catch (\PDOException $e) { logMessage("[upsertQuestionLog QID: $questionId] ERRO DB upsert: " . $e->getMessage()); return false; }
}
?>



<?php
/**
 * Arquivo: includes/evolution_api.php
 * Versão: v1.0
 * Descrição: Funções para interagir com a Evolution API V2 (WhatsApp).
 */

require_once __DIR__ . '/log_helper.php';
require_once __DIR__ . '/curl_helper.php';
// Constantes EVOLUTION_* devem estar definidas em config.php

/**
 * Envia uma notificação de texto simples via API Evolution V2.
 * (Payload v32.7 com texto na raiz)
 * @param string $targetJid O JID do destinatário.
 * @param string $messageText O texto da mensagem a ser enviada.
 * @return string|null O ID da mensagem enviada em caso de sucesso, ou null.
 */
function sendWhatsAppNotification(string $targetJid, string $messageText): ?string
{
    $functionVersion = "v32.7";
    if (!defined('EVOLUTION_API_URL') || !defined('EVOLUTION_INSTANCE_NAME') || !defined('EVOLUTION_API_KEY') || empty(EVOLUTION_API_KEY)) { logMessage("[sendWhatsAppNotification $functionVersion] ERRO FATAL: Configurações da Evolution API incompletas."); return null; }
    if (empty($targetJid) || empty(trim($messageText))) { logMessage("[sendWhatsAppNotification $functionVersion] ERRO: JID ('$targetJid') ou texto da mensagem vazio."); return null; }
    $url = rtrim(EVOLUTION_API_URL, '/') . '/message/sendText/' . EVOLUTION_INSTANCE_NAME; $headers = ['Content-Type: application/json', 'apikey: ' . EVOLUTION_API_KEY];
    $postData = [ 'number' => $targetJid, 'options' => [ 'delay' => 1200, 'presence' => 'composing' ], 'text' => $messageText ];
    $logPreview = mb_substr($messageText, 0, 70) . (mb_strlen($messageText) > 70 ? '...' : ''); logMessage("[sendWhatsAppNotification $functionVersion] Enviando texto para JID: $targetJid. Preview: '$logPreview'. Payload: " . json_encode($postData));
    $result = makeCurlRequest($url, 'POST', $headers, $postData, true);
    $messageId = null; if ($result['is_json'] && isset($result['response'])) { $messageId = $result['response']['key']['id'] ?? $result['response']['messageSend']['key']['id'] ?? $result['response']['id'] ?? null; }
    if (($result['httpCode'] == 200 || $result['httpCode'] == 201) && $messageId) { logMessage("[sendWhatsAppNotification $functionVersion] SUCESSO envio para JID: $targetJid. Message ID: $messageId"); return $messageId; }
    else { $apiErrorMsg = $result['is_json'] ? json_encode($result['response']) : mb_substr($result['response'] ?? '', 0, 200); logMessage("[sendWhatsAppNotification $functionVersion] ERRO envio para JID: $targetJid. HTTP: {$result['httpCode']}. cURL Error: ".($result['error'] ?? 'N/A').". API Response: $apiErrorMsg."); if ($messageId) { logMessage("[sendWhatsAppNotification $functionVersion] (AVISO: ID msg '$messageId' extraído apesar do erro HTTP {$result['httpCode']}.)"); } return null; }
}

/**
 * Envia uma notificação com IMAGEM (via URL) e legenda via API Evolution V2.
 * (Payload v32.5 com caption na raiz)
 * @param string $targetJid O JID do destinatário.
 * @param string $imageUrl A URL pública da imagem.
 * @param string $captionText O texto da legenda.
 * @return string|null O ID da mensagem enviada em caso de sucesso, ou null.
 */
function sendWhatsAppImageNotification(string $targetJid, string $imageUrl, string $captionText): ?string
{
    $functionVersion = "v32.5";
    if (!defined('EVOLUTION_API_URL') || !defined('EVOLUTION_INSTANCE_NAME') || !defined('EVOLUTION_API_KEY') || empty(EVOLUTION_API_KEY)) { logMessage("[sendWhatsAppImageNotification $functionVersion] ERRO FATAL: Configurações da Evolution API incompletas."); return null; }
    if (empty($targetJid) || empty(trim($captionText)) || empty(trim($imageUrl))) { logMessage("[sendWhatsAppImageNotification $functionVersion] ERRO: JID, URL Imagem ou legenda vazios."); return null; }
    if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) { logMessage("[sendWhatsAppImageNotification $functionVersion] ERRO: URL imagem inválida: '$imageUrl'"); return null; }
    $url = rtrim(EVOLUTION_API_URL, '/') . '/message/sendMedia/' . EVOLUTION_INSTANCE_NAME; $headers = ['Content-Type: application/json', 'apikey: ' . EVOLUTION_API_KEY];
    $imagePathInfo = pathinfo(parse_url($imageUrl, PHP_URL_PATH) ?: ''); $imageExtension = strtolower($imagePathInfo['extension'] ?? 'jpg'); $fileName = "imagem_anuncio." . $imageExtension;
    logMessage("[sendWhatsAppImageNotification $functionVersion] Nome arquivo: '$fileName' para URL: $imageUrl");
    $postData = [ 'number' => $targetJid, 'options' => [ 'delay' => 1500, 'presence' => 'upload_photo' ], 'mediatype' => 'image', 'media' => $imageUrl, 'caption' => $captionText, 'fileName' => $fileName ];
    $logCaptionPreview = mb_substr($captionText, 0, 70) . (mb_strlen($captionText) > 70 ? '...' : ''); logMessage("[sendWhatsAppImageNotification $functionVersion] Enviando Imagem+Legenda JID: $targetJid. Caption Preview: '$logCaptionPreview'. Payload: " . json_encode($postData));
    $result = makeCurlRequest($url, 'POST', $headers, $postData, true);
    $messageId = null; if ($result['is_json'] && isset($result['response'])) { $messageId = $result['response']['key']['id'] ?? $result['response']['messageSend']['key']['id'] ?? $result['response']['id'] ?? null; }
    if (($result['httpCode'] == 200 || $result['httpCode'] == 201) && $messageId) { logMessage("[sendWhatsAppImageNotification $functionVersion] SUCESSO envio Img+Legenda JID: $targetJid. Message ID: $messageId"); return $messageId; }
    else { $apiErrorMsg = $result['is_json'] ? json_encode($result['response']) : mb_substr($result['response'] ?? '', 0, 200); logMessage("[sendWhatsAppImageNotification $functionVersion] ERRO envio Img+Legenda JID: $targetJid. HTTP: {$result['httpCode']}. cURL Error: ".($result['error'] ?? 'N/A').". API Response: $apiErrorMsg."); if ($messageId) { logMessage("[sendWhatsAppImageNotification $functionVersion] (AVISO: ID msg '$messageId' extraído apesar do erro HTTP {$result['httpCode']}.)"); } return null; }
}
?>




<?php
/**
 * Arquivo: includes/gemini_api.php
 * Versão: v1.0
 * Descrição: Funções para interagir com a API Google Gemini.
 */

require_once __DIR__ . '/log_helper.php';
require_once __DIR__ . '/curl_helper.php';
// Constantes GOOGLE_API_KEY e GEMINI_API_ENDPOINT devem estar em config.php

/**
 * Envia um prompt estruturado para a API do Google Gemini.
 * @param array<string, mixed> $promptData O array contendo a estrutura do prompt.
 * @param bool $enableGrounding Se true, habilita Google Search Retrieval.
 * @return array<string, mixed> O resultado da chamada à API Gemini.
 */
function callGeminiAPI(array $promptData, bool $enableGrounding = false): array
{
     if (!defined('GOOGLE_API_KEY') || empty(GOOGLE_API_KEY)) { logMessage("[callGeminiAPI] ERRO FATAL: Constante GOOGLE_API_KEY não definida."); return ['httpCode' => 0, 'error' => 'Chave API Google não configurada.', 'response' => null, 'is_json' => false]; }
     if (!defined('GEMINI_API_ENDPOINT') || empty(GEMINI_API_ENDPOINT)) { logMessage("[callGeminiAPI] ERRO FATAL: Constante GEMINI_API_ENDPOINT não definida."); return ['httpCode' => 0, 'error' => 'Endpoint Gemini não configurado.', 'response' => null, 'is_json' => false]; }
     $url = GEMINI_API_ENDPOINT . '?key=' . GOOGLE_API_KEY; $headers = ['Content-Type: application/json'];
     if ($enableGrounding) { $promptData['tools'] = [ [ 'googleSearchRetrieval' => new \stdClass() ] ]; logMessage("[callGeminiAPI] Grounding HABILITADO."); } else { logMessage("[callGeminiAPI] Grounding DESABILITADO."); }
     $promptPreview = '[Prompt Omitido]'; if (isset($promptData['contents'][0]['parts'][0]['text'])) { $fullPrompt = $promptData['contents'][0]['parts'][0]['text']; $promptPreview = mb_substr($fullPrompt, 0, 100) . (mb_strlen($fullPrompt) > 100 ? '...' : ''); }
     logMessage("[callGeminiAPI] Enviando prompt. Preview: '$promptPreview'");
     $result = makeCurlRequest($url, 'POST', $headers, $promptData, true);
     $responsePreview = '[Resposta Omitida]';
     if ($result['is_json'] && isset($result['response'])) { $apiResponse = $result['response']; if (isset($apiResponse['candidates'][0]['content']['parts'])) { $parts = $apiResponse['candidates'][0]['content']['parts']; $fullResponse = ''; if (is_array($parts)) { $allPartsText = []; foreach ($parts as $part) { if (isset($part['text'])) $allPartsText[] = $part['text']; } $fullResponse = implode("\n", $allPartsText); } elseif (isset($parts['text'])) { $fullResponse = $parts['text']; } $responsePreview = mb_substr($fullResponse, 0, 100) . (mb_strlen($fullResponse) > 100 ? '...' : ''); } elseif (isset($apiResponse['promptFeedback']['blockReason'])) { $responsePreview = '[RESPOSTA BLOQUEADA] Razão: ' . $apiResponse['promptFeedback']['blockReason']; } else { $responsePreview = '[JSON Recebido, estrutura inesperada] ' . json_encode($apiResponse); } } elseif (!$result['is_json'] && is_string($result['response'])) { $responsePreview = '[Resposta não JSON] ' . mb_substr($result['response'], 0, 100) . '...'; } elseif ($result['error']) { $responsePreview = "[Erro cURL/Função: {$result['error']}]"; }
     logMessage("[callGeminiAPI] Resultado: HTTP {$result['httpCode']}. Preview Resposta: '$responsePreview'. Erro cURL: " . ($result['error'] ?? 'Nenhum'));
     return $result;
}

/**
 * Interpreta a intenção do usuário a partir de sua resposta no WhatsApp usando a API Gemini.
 * (Prompt formatado como string única - v32.3)
 * @param string $userReplyText O texto completo da resposta do usuário.
 * @param string $originalQuestionText O texto da pergunta original do ML.
 * @return array<string, string|null> Retorna array com 'intent' e 'cleaned_text'.
 */
function interpretUserIntent(string $userReplyText, string $originalQuestionText): array
{
    $functionVersion = "v30.6";
    logMessage("[interpretUserIntent $functionVersion] Iniciando. User Reply Preview: '" . mb_substr($userReplyText, 0, 50) . "...'. Original Q Preview: '" . mb_substr($originalQuestionText, 0, 30) . "...'");
    $defaultResult = ['intent' => 'INVALID_FORMAT', 'cleaned_text' => null];
    $trimmedUserReply = trim($userReplyText);

    if (empty($trimmedUserReply)) { logMessage("[interpretUserIntent $functionVersion] Aviso: Texto da resposta vazio. Retornando INVALID_FORMAT."); return $defaultResult; }
    if (empty(trim($originalQuestionText))) { logMessage("[interpretUserIntent $functionVersion] Aviso: Texto da pergunta original vazio. Retornando INVALID_FORMAT."); return $defaultResult; }

    $timeoutMinutes = defined('AI_FALLBACK_TIMEOUT_MINUTES') ? AI_FALLBACK_TIMEOUT_MINUTES : 10;

    $promptText = "Você é um assistente especialista em analisar respostas de usuários do WhatsApp, enviadas em réplica a uma notificação sobre uma pergunta do Mercado Livre. Sua tarefa é interpretar a intenção mais provável do usuário e extrair o texto da resposta manual, se aplicável. Use o contexto da pergunta original e da notificação enviada para tomar sua decisão. Seja flexível com pequenas variações de formato.\n\n";
    $promptText .= "Contexto Crucial:\n\n";
    $promptText .= "1. Pergunta Original do Cliente no Mercado Livre:\n```\n{$originalQuestionText}\n```\n\n";
    $promptText .= "2. Texto da Notificação Enviada ao Usuário no WhatsApp (a qual ele está respondendo):\n```\n(Início da Notificação)\n🔔 *Nova pergunta:*\n\n```{$originalQuestionText}```\n\n1️⃣ *Para dar sua própria resposta:*\n_Responda a esta mensagem com o texto da sua resposta._\n\n2️⃣ *Para responder agora com a IA:*\n_Responda a esta mensagem apenas com o número `2`._\n\n⚠️ Se você não responder em até *{$timeoutMinutes} minutos*, a IA responderá automaticamente.\n\n_(Id da pergunta: [ID_PERGUNTA] | Item: [ID_ITEM])_\n(Fim da Notificação)\n```\n\n";
    $promptText .= "3. Resposta do Usuário no WhatsApp:\n```\n{$userReplyText}\n```\n\n";
    $promptText .= "Instruções de Classificação (Siga esta ordem):\n\n";
    $promptText .= "PASSO 1: Verifique se é um GATILHO CLARO para a IA (`TRIGGER_AI`).\n";
    $promptText .= "Classifique como `TRIGGER_AI` se a resposta do usuário (ignorando maiúsculas/minúsculas, espaços extras e pontuações simples) indicar claramente a intenção de acionar a IA. Exemplos:\n- Exatamente o número \"2\".\n- Frases como: \"usa a ia\", \"ia\", \"robô\", \"responda você\", \"responde você\", \"responda por mim\", \"responde por mim\", \"responder com ia\", \"pode responder\", \"deixa com a ia\", \"usa inteligencia artificial\".\nSe for `TRIGGER_AI`, o `cleaned_text` deve ser `null`. Prossiga para o formato de saída.\n\n";
    $promptText .= "PASSO 2: Verifique se é um FORMATO CLARAMENTE INVÁLIDO (`INVALID_FORMAT`).\n";
    $promptText .= "Classifique como `INVALID_FORMAT` se a resposta for:\n- Vazia ou contendo apenas espaços/pontuações.\n- Uma saudação isolada, agradecimento ou confirmação genérica sem conteúdo de resposta (ex: \"ok\", \"entendi\", \"blz\", \"👍\", \"obrigado\", \"recebido\").\n- Uma pergunta completamente diferente ou não relacionada ao contexto da pergunta original do cliente ML.\n- Uma tentativa de comando que não seja claramente \"2\" ou uma intenção de resposta manual (ex: \"1\", \"3\", \"?\").\nSe for `INVALID_FORMAT`, o `cleaned_text` deve ser `null`. Prossiga para o formato de saída.\n\n";
    $promptText .= "PASSO 3: Caso contrário, considere como RESPOSTA MANUAL (`MANUAL_ANSWER`).\n";
    $promptText .= "Se a resposta NÃO for um gatilho claro para IA (Passo 1) e NÃO for um formato claramente inválido (Passo 2), assuma que a intenção mais provável é fornecer uma resposta manual para a pergunta original do cliente ML.\n- Use o contexto da \"Pergunta Original do Cliente\" para avaliar se a resposta do usuário faz sentido, mesmo que curta (ex: \"sim\", \"não temos\", \"cor preta\", \"é bivolt\").\n- Seja flexível. Respostas diretas e curtas são comuns e devem ser classificadas como `MANUAL_ANSWER` se fizerem sentido no contexto.\nExtração: O `cleaned_text` deve ser o texto completo da resposta do usuário (`{$userReplyText}`), após remover espaços em branco extras no início e no fim (trim). Prossiga para o formato de saída.\n\n";
    $promptText .= "FORMATO OBRIGATÓRIO DA SUA RESPOSTA (APENAS JSON VÁLIDO):\n\n";
    $promptText .= "Retorne **estritamente** um objeto JSON com a seguinte estrutura. Não inclua nenhuma explicação ou texto fora do JSON.\n\n```json\n{\"intent\": \"SUA_CLASSIFICACAO\", \"cleaned_text\": \"TEXTO_EXTRAIDO_OU_NULL\"}\n```\n\n";
    $promptText .= "- Substitua `SUA_CLASSIFICACAO` por uma das três strings: `\"MANUAL_ANSWER\"`, `\"TRIGGER_AI\"`, ou `\"INVALID_FORMAT\"`.\n";
    $promptText .= "- Substitua `TEXTO_EXTRAIDO_OU_NULL` pelo texto completo da resposta do usuário (após `trim()`, se `intent` for `MANUAL_ANSWER`) ou pelo valor JSON `null` (literalmente `null`, não a string `\"null\"`) se `intent` for `TRIGGER_AI` ou `INVALID_FORMAT`.\n\n";
    $promptText .= "Exemplos com a Lógica Atualizada:\n\n";
    $promptText .= "Pergunta Original: \"Tem tamanho P?\"\nResposta Usuário: \" Temos sim, pode comprar! \"\nResultado JSON: `{\"intent\": \"MANUAL_ANSWER\", \"cleaned_text\": \"Temos sim, pode comprar!\"}`\n\n";
    $promptText .= "Pergunta Original: \"Tem tamanho P?\"\nResposta Usuário: \"sim\"\nResultado JSON: `{\"intent\": \"MANUAL_ANSWER\", \"cleaned_text\": \"sim\"}`\n\n";
    $promptText .= "Pergunta Original: \"É original?\"\nResposta Usuário: \"   2   \"\nResultado JSON: `{\"intent\": \"TRIGGER_AI\", \"cleaned_text\": null}`\n\n";
    $promptText .= "Pergunta Original: \"Serve para o modelo 2023?\"\nResposta Usuário: \"responde com ia por favor\"\nResultado JSON: `{\"intent\": \"TRIGGER_AI\", \"cleaned_text\": null}`\n\n";
    $promptText .= "Pergunta Original: \"Qual a voltagem?\"\nResposta Usuário: \"responda por mim\"\nResultado JSON: `{\"intent\": \"TRIGGER_AI\", \"cleaned_text\": null}`\n\n";
    $promptText .= "Pergunta Original: \"Tem azul?\"\nResposta Usuário: \"Ok, obrigado\"\nResultado JSON: `{\"intent\": \"INVALID_FORMAT\", \"cleaned_text\": null}`\n\n";
    $promptText .= "Pergunta Original: \"Faz por 100?\"\nResposta Usuário: \"Qual o minimo?\" (Considerado pergunta não relacionada)\nResultado JSON: `{\"intent\": \"INVALID_FORMAT\", \"cleaned_text\": null}`\n\n";
    $promptText .= "Pergunta Original: \"É bivolt?\"\nResposta Usuário: \"nao\"\nResultado JSON: `{\"intent\": \"MANUAL_ANSWER\", \"cleaned_text\": \"nao\"}`\n\n";
    $promptText .= "Pergunta Original: \"Tem garantia?\"\nResposta Usuário: \"1 ano\"\nResultado JSON: `{\"intent\": \"MANUAL_ANSWER\", \"cleaned_text\": \"1 ano\"}`\n\n";
    $promptText .= "Pergunta Original: \"Qual a cor?\"\nResposta Usuário: \"Oi, tem preta?\" (Considerado pergunta não relacionada)\nResultado JSON: `{\"intent\": \"INVALID_FORMAT\", \"cleaned_text\": null}`\n\n";
    $promptText .= "Sua Tarefa:\n\n";
    $promptText .= "Analise a \"Resposta do Usuário no WhatsApp\" fornecida (`{$userReplyText}`) no contexto da \"Pergunta Original do Cliente\" (`{$originalQuestionText}`) e da \"Notificação Enviada\". Retorne APENAS o objeto JSON formatado estritamente conforme as instruções e exemplos.";

    $geminiDataIntent = [ 'contents' => [['role' => 'user', 'parts' => [['text' => $promptText]]]], 'generationConfig' => [ 'temperature' => 0.25, 'maxOutputTokens' => 200 ] ];

    logMessage("[interpretUserIntent $functionVersion] Chamando API Gemini para interpretação...");
    $geminiResult = callGeminiAPI($geminiDataIntent, false);

    if ($geminiResult['httpCode'] == 200 && $geminiResult['is_json'] && isset($geminiResult['response']['candidates'][0]['content']['parts'][0]['text'])) {
        $responseText = $geminiResult['response']['candidates'][0]['content']['parts'][0]['text'];
        logMessage("[interpretUserIntent $functionVersion] Resposta Bruta da IA: $responseText");
        $cleanedResponseText = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/s', '', $responseText);
        $decodedJson = json_decode($cleanedResponseText, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($decodedJson['intent']) && is_string($decodedJson['intent'])) {
             $validIntents = ['MANUAL_ANSWER', 'TRIGGER_AI', 'INVALID_FORMAT'];
             if (in_array($decodedJson['intent'], $validIntents)) {
                 $cleanedText = isset($decodedJson['cleaned_text']) && is_string($decodedJson['cleaned_text']) ? trim($decodedJson['cleaned_text']) : null;
                 if ($decodedJson['intent'] === 'MANUAL_ANSWER' && ($cleanedText === null || $cleanedText === '')) {
                     logMessage("[interpretUserIntent $functionVersion] AVISO: Intenção 'MANUAL_ANSWER' mas texto limpo resultante é vazio. Resposta original: '$userReplyText'. Retornando INVALID_FORMAT.");
                     return $defaultResult;
                 }
                 logMessage("[interpretUserIntent $functionVersion] Interpretação bem-sucedida. Intenção: {$decodedJson['intent']}" . ($cleanedText !== null ? ", Texto Limpo: '$cleanedText'" : ""));
                 return ['intent' => $decodedJson['intent'], 'cleaned_text' => $cleanedText ];
             } else { logMessage("[interpretUserIntent $functionVersion] ERRO: Intenção desconhecida retornada pela IA: '{$decodedJson['intent']}'. Resposta JSON: $cleanedResponseText"); return $defaultResult; }
        } else { $jsonError = json_last_error_msg(); logMessage("[interpretUserIntent $functionVersion] ERRO: JSON inválido ou chave 'intent' ausente na resposta da IA. Erro JSON: {$jsonError}. Resposta limpa: $cleanedResponseText"); return $defaultResult; }
    } else { $errorDetail = $geminiResult['error'] ?? json_encode($geminiResult['response'] ?? 'N/A'); logMessage("[interpretUserIntent $functionVersion] ERRO: Falha na chamada ou resposta inválida da API Gemini. HTTP: {$geminiResult['httpCode']}. Detalhe: " . $errorDetail); return $defaultResult; }
}
?>




<?php
/**
 * Arquivo: includes/helpers.php
 * Versão: v1.0
 * Descrição: Funções auxiliares (helpers) reutilizáveis na aplicação.
 */

if (!function_exists('getSubscriptionStatusClass')) {
    /**
     * Retorna as classes CSS do Tailwind para a tag de status da assinatura.
     * Utilizada no dashboard.php e super_admin.php.
     *
     * @param string|null $status O status da assinatura (ex: 'ACTIVE', 'PENDING', 'OVERDUE', 'CANCELED').
     *                            Trata NULL como 'PENDING' para exibição.
     * @return string As classes CSS correspondentes.
     */
    function getSubscriptionStatusClass(?string $status): string {
         // Classe base para todas as tags de status
         $base = "inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium";

         // Normaliza o status para maiúsculas e trata null como PENDING por padrão
         $normalizedStatus = strtoupper($status ?? 'PENDING');

         // Mapeia o status normalizado para as classes CSS
         switch ($normalizedStatus) {
             case 'ACTIVE':
                 // Verde para Ativo
                 return "$base bg-green-100 text-green-800 dark:bg-green-700 dark:text-green-100";
             case 'PENDING':
                 // Azul para Pendente
                 return "$base bg-blue-100 text-blue-800 dark:bg-blue-700 dark:text-blue-100";
             case 'OVERDUE':
                 // Amarelo para Vencido
                 return "$base bg-yellow-100 text-yellow-800 dark:bg-yellow-700 dark:text-yellow-100";
             case 'INACTIVE': // Status local vindo do admin
             case 'CANCELED': // Status vindo do Asaas ou local
             case 'EXPIRED':  // Status vindo do Asaas
                 // Vermelho para Inativo, Cancelado ou Expirado
                 return "$base bg-red-100 text-red-800 dark:bg-red-700 dark:text-red-100";
             default:
                 // Cinza para status desconhecidos ou não mapeados
                 return "$base bg-gray-100 text-gray-800 dark:bg-gray-600 dark:text-gray-100";
         }
    }
}


if (!function_exists('getStatusTagClasses')) {
    /**
     * Retorna as classes CSS do Tailwind para a tag de status de processamento de pergunta.
     * Utilizada no dashboard.php e super_admin.php.
     *
     * @param string $status O status do log (ex: 'PENDING_WHATSAPP', 'AI_ANSWERED', 'ERROR').
     * @return string As classes CSS correspondentes.
     */
    function getStatusTagClasses(string $status): string {
        // Classe base para todas as tags
        $base = "inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium";

        // Mapeia o status (convertido para maiúsculas para segurança) para classes CSS
        switch (strtoupper($status)) {
            case 'PENDING_WHATSAPP': // Aguardando envio ou falha no envio inicial
                return "$base bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200";
            case 'AWAITING_TEXT_REPLY': // Enviado para WhatsApp, aguardando resposta ou timeout
                return "$base bg-yellow-100 text-yellow-800 dark:bg-yellow-700 dark:text-yellow-100";
            case 'AI_TRIGGERED_BY_TEXT': // Usuário respondeu '2'
            case 'AI_PROCESSING':        // IA está processando (timeout ou comando '2')
                return "$base bg-purple-100 text-purple-800 dark:bg-purple-700 dark:text-purple-100";
            case 'AI_ANSWERED': // IA respondeu com sucesso no ML
                return "$base bg-green-100 text-green-800 dark:bg-green-700 dark:text-green-100";
            case 'HUMAN_ANSWERED_VIA_WHATSAPP': // Humano respondeu via WhatsApp e foi postado no ML
            case 'HUMAN_ANSWERED_ON_ML':        // Pergunta já estava respondida no ML quando verificada
                return "$base bg-blue-100 text-blue-800 dark:bg-blue-700 dark:text-blue-100";
            case 'AI_FAILED': // IA tentou responder mas falhou (erro API, validação, etc.)
            case 'ERROR':     // Erro genérico no processamento (conexão, DB, etc.)
            case 'DELETED':   // Pergunta foi deletada no ML
            case 'CLOSED_UNANSWERED': // Pergunta foi fechada sem resposta no ML
            case 'UNDER_REVIEW':      // Pergunta sob moderação no ML
            case 'BANNED':            // Pergunta banida no ML
                 return "$base bg-red-100 text-red-800 dark:bg-red-700 dark:text-red-100"; // Vermelho para erros e status finais negativos
            default:
                // Captura status desconhecidos retornados pela API ML que podem ter prefixo
                 if (strpos($status, 'UNKNOWN_ML_STATUS_') === 0) {
                     return "$base bg-red-100 text-red-800 dark:bg-red-700 dark:text-red-100";
                 }
                 // Status padrão ou não mapeado explicitamente
                 logMessage("WARN: Status de log não mapeado encontrado em getStatusTagClasses: '$status'");
                 return "$base bg-gray-100 text-gray-800 dark:bg-gray-600 dark:text-gray-100"; // Cinza como fallback
        }
    }
}

// --- Outras Funções Helper ---
// Adicione outras funções auxiliares globais aqui conforme a necessidade do projeto.
// Exemplo: função para formatar CPF/CNPJ, validar datas, etc.

/*
 Exemplo (não implementado):
 if (!function_exists('formatCpfCnpj')) {
     function formatCpfCnpj(string $value): string {
         // Lógica para formatar CPF/CNPJ
         return $formattedValue;
     }
 }
*/

?>



<?php
/**
 * Arquivo: includes/log_helper.php
 * Versão: v1.0
 * Descrição: Helper para logging centralizado.
 */

// Inclui config.php para a constante LOG_FILE, se necessário,
// mas é mais seguro que LOG_FILE seja definida antes de chamar logMessage.
// require_once __DIR__ . '/../config.php'; // Cuidado com caminhos relativos

/**
 * Registra uma mensagem no arquivo de log especificado em `config.php`.
 * Inclui timestamp e PID (Process ID) para facilitar o rastreamento.
 * Tenta criar o diretório de log se não existir e verifica permissões de escrita.
 * Usa `LOCK_EX` para tentar evitar condições de corrida em escritas concorrentes.
 *
 * @param string $message A mensagem a ser registrada no log.
 * @return void
 */
function logMessage(string $message): void
{
    try {
        // Garante que a constante LOG_FILE está definida
        if (!defined('LOG_FILE')) {
             // Se não definida, tenta usar o log de erro padrão do PHP
             error_log("FATAL: Constante LOG_FILE não definida! Mensagem original: " . $message);
             return;
        }
        $logFilePath = LOG_FILE;
        $logDir = dirname($logFilePath);

        // Tenta criar o diretório de log recursivamente se não existir
        if ($logDir && !is_dir($logDir)) {
            // @ suprime erros caso a criação falhe (ex: permissão), a verificação de escrita tratará disso
            @mkdir($logDir, 0775, true);
        }

        // Formata a mensagem de log com timestamp e PID
        $timestamp = date('Y-m-d H:i:s');
        $pid = getmypid() ?: 'N/A'; // Obtém o PID do processo atual
        $logLine = "[$timestamp PID:$pid] $message\n";

        // Verifica se o diretório é gravável e se o arquivo é gravável (ou não existe)
        $canWrite = $logDir && is_writable($logDir) && (!file_exists($logFilePath) || is_writable($logFilePath));

        if ($canWrite) {
             // Tenta escrever no arquivo de log com bloqueio exclusivo
             $logSuccess = @file_put_contents(
                 $logFilePath,
                 $logLine,
                 FILE_APPEND | LOCK_EX // Adiciona ao final do arquivo e tenta bloquear
             );
             // Se a escrita falhar mesmo com permissões (raro, mas possível)
             if ($logSuccess === false) {
                  error_log("Falha ao escrever no log '$logFilePath' (file_put_contents retornou false) - Mensagem: $logLine");
             }
        } else {
             // Loga um aviso crítico se não houver permissão de escrita
             $permErrorMsg = "AVISO CRÍTICO de Log: Não é possível escrever no arquivo de log '$logFilePath'. Verifique permissões do diretório '$logDir' e do arquivo (se existir).";
             error_log($permErrorMsg);
             // Loga a mensagem original no log de erros do PHP como fallback
             error_log("Mensagem original (falha log): $logLine");
        }

    } catch (\Exception $e) {
        // Captura exceções inesperadas dentro da própria função de log
        $logPathMsg = defined('LOG_FILE') ? LOG_FILE : 'N/A';
        error_log("Exceção CRÍTICA na função logMessage() para '$logPathMsg': " . $e->getMessage() . " | Mensagem original: " . $message);
    }
}
?>



<?php
/**
 * Arquivo: includes/ml_api.php
 * Versão: v1.0
 * Descrição: Funções para interagir com a API do Mercado Livre.
 */

require_once __DIR__ . '/log_helper.php';
require_once __DIR__ . '/curl_helper.php';
// As constantes (ML_TOKEN_URL, ML_APP_ID, etc.) devem ser definidas em config.php
// e config.php deve ser incluído *antes* de incluir este arquivo nos scripts principais.

/**
 * Renova o Access Token do Mercado Livre usando o Refresh Token.
 * @param string $refreshToken O Refresh Token válido (descriptografado).
 * @return array<string, mixed> O resultado da chamada à API de token.
 */
function refreshMercadoLibreToken(string $refreshToken): array
{
    if (!defined('ML_TOKEN_URL') || !defined('ML_APP_ID') || !defined('ML_SECRET_KEY')) {
        logMessage("[refreshMercadoLibreToken] ERRO: Constantes ML_TOKEN_URL, ML_APP_ID ou ML_SECRET_KEY não definidas.");
        return ['httpCode' => 0, 'error' => 'Configuração ML incompleta.', 'response' => null, 'is_json' => false];
    }
    $url = ML_TOKEN_URL;
    $headers = ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'];
    $postData = [ 'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken, 'client_id' => ML_APP_ID, 'client_secret' => ML_SECRET_KEY ];
    logMessage("[refreshMercadoLibreToken] Enviando requisição de refresh para API ML...");
    $result = makeCurlRequest($url, 'POST', $headers, $postData, false);
    logMessage("[refreshMercadoLibreToken] Resultado do refresh: HTTP {$result['httpCode']}. Erro cURL: " . ($result['error'] ?? 'Nenhum'));
    return $result;
}

/**
 * Busca perguntas não respondidas para um vendedor específico no Mercado Livre,
 * suportando filtro de data e paginação (limit/offset).
 * @param int|string $sellerId O ID do vendedor no Mercado Livre.
 * @param string $accessToken O Access Token válido (descriptografado).
 * @param string|null $dateFrom Data inicial (formato ISO 8601) para buscar perguntas a partir desta data. Se null, não filtra.
 * @param int $limit O número máximo de perguntas a serem retornadas por chamada (Padrão: 50).
 * @param int $offset O deslocamento (índice inicial) para a paginação (Padrão: 0).
 * @return array<string, mixed> O resultado da chamada à API.
 */
function getMercadoLibreQuestions($sellerId, string $accessToken, ?string $dateFrom = null, int $limit = 50, int $offset = 0): array
{
     if (!defined('ML_API_BASE_URL')) {
        logMessage("[getMercadoLibreQuestions] ERRO: Constante ML_API_BASE_URL não definida.");
        return ['httpCode' => 0, 'error' => 'Configuração ML incompleta.', 'response' => null, 'is_json' => false];
    }
    if ($limit <= 0 || $limit > 50) { logMessage("[getMercadoLibreQuestions] Aviso: Limite inválido ($limit) ajustado para 50."); $limit = 50; }
    if ($offset < 0) { logMessage("[getMercadoLibreQuestions] Aviso: Offset negativo ($offset) ajustado para 0."); $offset = 0; }
    $queryParams = [ 'seller_id' => $sellerId, 'status' => 'UNANSWERED', 'sort' => 'date_created_desc', 'limit' => $limit, 'offset' => $offset ];
    $dateFilterLog = 'N/A';
    if ($dateFrom !== null && !empty($dateFrom)) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{3})?([+-]\d{2}:\d{2}|Z)$/', $dateFrom)) {
             $queryParams['date_created_from'] = $dateFrom; $dateFilterLog = $dateFrom;
        } else { logMessage("[getMercadoLibreQuestions] AVISO: Formato data inválido date_created_from: '$dateFrom'. Ignorando filtro."); }
    }
    $url = ML_API_BASE_URL . '/questions/search?' . http_build_query($queryParams);
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $accessToken];
    $tokenPreview = 'Bearer ' . substr($accessToken, 0, 8) . '...' . substr($accessToken, -4);
    logMessage("[getMercadoLibreQuestions] Buscando perguntas: ML ID: $sellerId, Limit: $limit, Offset: $offset, DateFrom: $dateFilterLog (Token: $tokenPreview)");
    $result = makeCurlRequest($url, 'GET', $headers);
    $questionCount = ($result['httpCode'] == 200 && isset($result['response']['questions'])) ? count($result['response']['questions']) : 0;
    $totalApi = $result['httpCode'] == 200 && isset($result['response']['total']) ? $result['response']['total'] : 'N/A';
    logMessage("[getMercadoLibreQuestions] Resultado busca para $sellerId (Offset $offset): HTTP {$result['httpCode']}, Perguntas retornadas: {$questionCount} (Total API: {$totalApi}), Erro cURL: " . ($result['error'] ?? 'Nenhum'));
    return $result;
}

/**
 * Obtém detalhes de um item (anúncio) específico no Mercado Livre.
 * @param string $itemId O ID do item (formato MLBxxxxxxxxx).
 * @param string $accessToken O Access Token válido (descriptografado).
 * @return array<string, mixed> O resultado da chamada à API.
 */
function getMercadoLibreItemDetails(string $itemId, string $accessToken): array
{
    if (!defined('ML_API_BASE_URL')) { logMessage("[getMercadoLibreItemDetails] ERRO: Constante ML_API_BASE_URL não definida."); return ['httpCode' => 0, 'error' => 'Configuração ML incompleta.', 'response' => null, 'is_json' => false]; }
    $url = ML_API_BASE_URL . '/items/' . $itemId . '?include_attributes=all';
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $accessToken];
    logMessage("[getMercadoLibreItemDetails] Buscando detalhes do item ML: $itemId");
    $result = makeCurlRequest($url, 'GET', $headers);
    logMessage("[getMercadoLibreItemDetails] Resultado detalhes item $itemId: HTTP {$result['httpCode']}. Erro cURL: " . ($result['error'] ?? 'Nenhum'));
    return $result;
}

/**
 * Verifica o status atual de uma pergunta específica no Mercado Livre.
 * @param int $questionId O ID da pergunta.
 * @param string $accessToken O Access Token válido (descriptografado).
 * @return array<string, mixed> O resultado da chamada à API.
 */
function getMercadoLibreQuestionStatus(int $questionId, string $accessToken): array
{
    if (!defined('ML_API_BASE_URL')) { logMessage("[getMercadoLibreQuestionStatus] ERRO: Constante ML_API_BASE_URL não definida."); return ['httpCode' => 0, 'error' => 'Configuração ML incompleta.', 'response' => null, 'is_json' => false]; }
    $url = ML_API_BASE_URL . '/questions/' . $questionId;
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $accessToken];
    logMessage("[getMercadoLibreQuestionStatus] Verificando status ML da QID: $questionId");
    $result = makeCurlRequest($url, 'GET', $headers);
    $status = $result['is_json'] && isset($result['response']['status']) ? $result['response']['status'] : 'ERRO_API/NAO_JSON';
    logMessage("[getMercadoLibreQuestionStatus] Status ML retornado para QID $questionId: '$status' (HTTP: {$result['httpCode']}). Erro cURL: " . ($result['error'] ?? 'Nenhum'));
    if ($result['httpCode'] !== 200) { logMessage("[getMercadoLibreQuestionStatus] AVISO: Falha ao buscar status ML da QID $questionId. Code: {$result['httpCode']}. Response: " . json_encode($result['response'])); }
    return $result;
}

/**
 * Posta uma resposta para uma pergunta no Mercado Livre.
 * @param int $questionId O ID da pergunta a ser respondida.
 * @param string $responseText O texto da resposta.
 * @param string $accessToken O Access Token válido (descriptografado).
 * @return array<string, mixed> O resultado da chamada à API.
 */
function postMercadoLibreAnswer(int $questionId, string $responseText, string $accessToken): array
{
     if (!defined('ML_API_BASE_URL')) { logMessage("[postMercadoLibreAnswer] ERRO: Constante ML_API_BASE_URL não definida."); return ['httpCode' => 0, 'error' => 'Configuração ML incompleta.', 'response' => null, 'is_json' => false]; }
    $url = ML_API_BASE_URL . '/answers';
    $headers = ['Authorization: Bearer ' . $accessToken];
    $postData = ['question_id' => $questionId, 'text' => $responseText];
    $logTextPreview = mb_substr($responseText, 0, 50) . (mb_strlen($responseText) > 50 ? '...' : '');
    logMessage("[postMercadoLibreAnswer] Enviando resposta para QID: $questionId. Texto Preview: '$logTextPreview'");
    $result = makeCurlRequest($url, 'POST', $headers, $postData, true);
    logMessage("[postMercadoLibreAnswer] Resultado do POST para QID $questionId: HTTP {$result['httpCode']}. Erro cURL: " . ($result['error'] ?? 'Nenhum') . ". Response: " . json_encode($result['response']));
    return $result;
}
?>



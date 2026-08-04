<?php
/**
 * Webhook Callback - Pagar.me
 *
 * Recebe notificações da Pagar.me para:
 *   - Confirmar pagamentos que ficaram em análise antifraude
 *     (status "processing" no momento da captura)
 *   - Registrar falhas e estornos no log de transações
 *
 * Configure esta URL no painel da Pagar.me em:
 *   Configurações > Webhooks > Nova assinatura
 *   URL: https://SEUDOMINIO.com/modules/gateways/callback/pagarme.php
 *   Eventos: order.paid, order.payment_failed,
 *            charge.paid, charge.payment_failed, charge.refunded
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = 'pagarme';
$gatewayParams     = getGatewayVariables($gatewayModuleName);

// Módulo precisa estar ativado no WHMCS
if (!$gatewayParams['type']) {
    http_response_code(404);
    die('Módulo de pagamento não encontrado ou não ativado.');
}

$rawBody = file_get_contents('php://input');
$data    = json_decode($rawBody, true);

if (!$data || empty($data['type'])) {
    http_response_code(400);
    die('Payload inválido.');
}

$eventType = $data['type'];
$eventData = isset($data['data']) ? $data['data'] : null;

logTransaction($gatewayParams['name'], $rawBody, 'Webhook recebido: ' . $eventType);

if (!$eventData) {
    http_response_code(400);
    die('Payload sem dados do pedido.');
}

// Dependendo do evento, o payload pode ser um "order" (com charges dentro)
// ou diretamente um "charge" (que traz o order aninhado).
if (isset($eventData['charges'][0])) {
    $charge = $eventData['charges'][0];
    $order  = $eventData;
} else {
    $charge = $eventData;
    $order  = isset($eventData['order']) ? $eventData['order'] : $eventData;
}

// O ID da fatura foi gravado em metadata no momento da criação do pedido
$invoiceId = null;
if (isset($order['metadata']['whmcs_invoice_id'])) {
    $invoiceId = (int) $order['metadata']['whmcs_invoice_id'];
} elseif (isset($eventData['metadata']['whmcs_invoice_id'])) {
    $invoiceId = (int) $eventData['metadata']['whmcs_invoice_id'];
}

$transId = isset($charge['id']) ? $charge['id'] : null;

if (!$invoiceId || !$transId) {
    http_response_code(200);
    die('Evento ignorado (sem fatura ou transação relacionada).');
}

// Valida se a fatura existe e pertence a este gateway
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

switch ($eventType) {
    case 'order.paid':
    case 'charge.paid':
        // Impede o registro duplicado da mesma transação
        checkCbTransID($transId);

        $amount = isset($charge['amount']) ? $charge['amount'] / 100 : 0;

        addInvoicePayment(
            $invoiceId,
            $transId,
            $amount,
            0,
            $gatewayModuleName
        );

        logTransaction($gatewayParams['name'], $rawBody, 'Pagamento confirmado');
        break;

    case 'order.payment_failed':
    case 'charge.payment_failed':
        logTransaction($gatewayParams['name'], $rawBody, 'Pagamento recusado');
        break;

    case 'charge.refunded':
        logTransaction($gatewayParams['name'], $rawBody, 'Pagamento estornado');
        break;

    default:
        logTransaction($gatewayParams['name'], $rawBody, 'Evento não tratado: ' . $eventType);
        break;
}

http_response_code(200);
echo 'OK';

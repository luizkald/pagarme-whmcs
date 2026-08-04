<?php
/**
 * WHMCS Merchant Gateway Module - Pagar.me (Cartão de Crédito)
 *
 * API utilizada: Pagar.me Core API v5 (https://api.pagar.me/core/v5)
 * Documentação: https://docs.pagar.me/reference/criar-pedido
 *
 * Recursos suportados:
 *   - Cobrança de cartão de crédito digitado no checkout do WHMCS
 *   - Tokenização do cartão para cobranças futuras (recorrência / cron)
 *   - Remoção do cartão salvo
 *   - Estorno de cobranças
 *   - Confirmação assíncrona via webhook (antifraude)
 *
 * Instalação: ver README.md do pacote.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/pagarme/pagarmeapi.php';
require_once __DIR__ . '/pagarme/installments.php';

// =========================================================================
// Metadados e configuração
// =========================================================================

/**
 * Metadados do módulo
 */
function pagarme_MetaData()
{
    return array(
        'DisplayName' => 'Pagar.me - Cartão de Crédito',
        'APIVersion'  => '1.1',
    );
}

/**
 * Campos de configuração exibidos em Setup > Payments > Payment Gateways
 */
function pagarme_config()
{
    return array(
        'FriendlyName' => array(
            'Type'  => 'System',
            'Value' => 'Pagar.me - Cartão de Crédito',
        ),
        'secretKeyLive' => array(
            'FriendlyName' => 'Secret Key (Produção)',
            'Type'         => 'password',
            'Size'         => '60',
            'Description'  => 'Secret Key de produção (Painel Pagar.me > Conta > Chaves de API). Ex: sk_xxxxxxxxx',
        ),
        'secretKeyTest' => array(
            'FriendlyName' => 'Secret Key (Sandbox)',
            'Type'         => 'password',
            'Size'         => '60',
            'Description'  => 'Secret Key de testes. Ex: sk_test_xxxxxxxxx',
        ),
        'testMode' => array(
            'FriendlyName' => 'Modo de Testes',
            'Type'         => 'yesno',
            'Description'  => 'Marque para processar as transações usando a Secret Key de Sandbox',
        ),
        'statementDescriptor' => array(
            'FriendlyName' => 'Descritor na Fatura',
            'Type'         => 'text',
            'Size'         => '13',
            'Description'  => 'Texto exibido na fatura do cartão do cliente (máximo 13 caracteres)',
        ),
        'enableInstallments' => array(
            'FriendlyName' => 'Parcelamento com juros por ciclo',
            'Type'         => 'yesno',
            'Description'  => 'Permite ao cliente parcelar a fatura. O teto de parcelas e a faixa '
                . 'sem juros dependem do ciclo de cobrança do plano: mensal só à vista; trimestral '
                . 'até 3x (todas com juros); semestral até 6x (1x-3x sem juros, 4x-6x com juros); '
                . 'anual/bienal até 12x (1x-5x sem juros, 6x-12x com juros); trienal até 12x (1x-6x '
                . 'sem juros, 7x-12x com juros). Acima da faixa sem juros, o juros (taxa da '
                . 'MDR da Pagar.me) é repassado ao comprador. Detalhes em '
                . 'docs/parcelamento-com-juros.md.',
        ),
        'cpfCustomField' => array(
            'FriendlyName' => 'Nome do Campo Personalizado (CPF/CNPJ)',
            'Type'         => 'text',
            'Size'         => '30',
            'Default'      => 'CPF/CNPJ',
            'Description'  => 'Nome exato do Custom Client Field (Setup > Custom Client Fields) '
                . 'onde o CPF/CNPJ do cliente é armazenado',
        ),
    );
}

// =========================================================================
// Cobrança
// =========================================================================

/**
 * Processa a cobrança de uma fatura.
 *
 * Dois cenários são tratados:
 *   1. $params['gatewayid'] preenchido -> cobra um cartão já tokenizado
 *      (usado pelo cron de cobrança automática / recorrência)
 *   2. Caso contrário -> cobra os dados de cartão digitados no checkout
 *
 * @param array $params Parâmetros enviados pelo WHMCS
 * @return array Resultado no formato esperado pelo WHMCS
 */
function pagarme_capture($params)
{
    $secretKey = pagarme_getSecretKey($params);

    if (empty($secretKey)) {
        return array(
            'status'  => 'declined',
            'rawdata' => 'Secret Key não configurada no módulo de pagamento.',
        );
    }

    // Valor em centavos (a Pagar.me trabalha sempre em centavos)
    $amountCents = (int) round($params['amount'] * 100);

    // Fatura de valor zero (ex: plano gratuito): não há nada a cobrar.
    // O WHMCS normalmente já marca faturas de R$ 0,00 como pagas antes de
    // chamar o gateway, mas esta guarda evita enviar um pedido inválido
    // (amount = 0) para a API caso o capture seja acionado mesmo assim.
    if ($amountCents <= 0) {
        return array(
            'status'  => 'success',
            'rawdata' => 'Fatura de valor zero - nenhuma cobrança foi necessária.',
        );
    }

    $api        = new PagarmeApi($secretKey);
    $descriptor = pagarme_getDescriptor($params);

    $metadata = array(
        'whmcs_invoice_id' => (string) $params['invoiceid'],
    );

    // ---------------------------------------------------------------
    // Parcelamento (Caminho B): teto por ciclo + juros ao comprador
    // ---------------------------------------------------------------
    $maxInstallments  = pagarme_maxInstallmentsForInvoice($params['invoiceid']);
    $freeInstallments = pagarme_freeInstallmentsForInvoice($params['invoiceid']);

    // Seleção obsoleta: o cliente escolheu um parcelamento que não pode mais ser
    // honrado (expirou, ou a fatura mudou de valor depois da escolha). Cobrar à
    // vista aqui seria cobrar num plano diferente do que ele aceitou - recusamos
    // e pedimos que refaça a escolha.
    if (empty($_REQUEST['pagarme_installments']) && function_exists('pagarme_readStoredInstallments')) {
        $stored = pagarme_readStoredInstallments($params['invoiceid']);
        if ($stored['status'] === 'expired' || $stored['status'] === 'base_changed') {
            pagarme_log($params, array(
                'status'       => $stored['status'],
                'installments' => $stored['installments'],
            ), 'capture: seleção de parcelamento obsoleta');
            return array(
                'status'  => 'declined',
                'rawdata' => 'A seleção de parcelamento expirou ou os valores da fatura mudaram. '
                    . 'Refaça a escolha de parcelas antes de pagar.',
            );
        }
    }

    $installments = pagarme_resolveInstallments($params, $maxInstallments);

    // Diagnóstico: registra o que foi detectado/lido para o parcelamento
    pagarme_log($params, array(
        'maxInstallments'    => $maxInstallments,
        'freeInstallments'   => $freeInstallments,
        'installments'       => $installments,
        'enableInstallments' => isset($params['enableInstallments']) ? $params['enableInstallments'] : '(vazio)',
        'req_pagarme'        => isset($_REQUEST['pagarme_installments']) ? $_REQUEST['pagarme_installments'] : '(ausente)',
        'selecao_persistida' => isset($stored['status']) ? $stored['status'] : '(nao consultada)',
    ), 'capture: diagnóstico de parcelamento');

    // Bandeira do cartão (para a tabela de taxas). Cartão digitado: pelo BIN.
    // Cartão salvo (token): usa a bandeira gravada no token, se houver.
    if (!empty($params['gatewayid'])) {
        $tokenInfo = pagarme_parseToken($params['gatewayid']);
        $brand     = ($tokenInfo && !empty($tokenInfo['brand'])) ? $tokenInfo['brand'] : 'outras';
    } else {
        $brand = pagarme_detectBrand(isset($params['cardnum']) ? $params['cardnum'] : '');
    }

    // Base de cálculo: o que o cliente deve SEM o juros de uma tentativa
    // anterior. Usar $params['amount'] aqui faria o juros incidir sobre o juros
    // já lançado na fatura a cada retentativa.
    $baseAmount   = pagarme_invoiceBaseAmount($params['invoiceid']);
    $customerRate = pagarme_customerRate($brand, $installments, $freeInstallments);
    $chargeAmount = $params['amount'];

    if ($customerRate > 0) {
        $feeAmount = pagarme_feeForInstallments($baseAmount, $brand, $installments, $freeInstallments);

        // Reconciliação: adiciona o juros como item na fatura, para que o
        // total cobrado seja igual ao total da fatura no WHMCS.
        $newTotal = pagarme_applyInstallmentFee($params['invoiceid'], $feeAmount, $installments);

        if ($newTotal !== null) {
            $chargeAmount = $newTotal;
            pagarme_log($params, array(
                'installments' => $installments,
                'brand'        => $brand,
                'rate'         => $customerRate,
                'base'         => $baseAmount,
                'fee'          => $feeAmount,
                'new_total'    => $newTotal,
            ), 'capture: juros de parcelamento aplicado');
        } else {
            // Falha ao reconciliar: não cobramos o cliente por um valor que
            // não bate com a fatura. Melhor recusar e registrar.
            pagarme_log($params, array(
                'installments' => $installments,
                'fee'          => $feeAmount,
            ), 'capture: falha ao aplicar juros na fatura');
            return array(
                'status'  => 'declined',
                'rawdata' => 'Não foi possível aplicar a taxa de parcelamento na fatura.',
            );
        }
    } elseif (pagarme_clearInstallmentFee($params['invoiceid'])) {
        // Esta tentativa caiu numa faixa SEM juros, mas a fatura ainda carregava
        // o item de taxa de uma tentativa anterior (ex: falhou em 8x, refez em
        // 4x). Sem remover, a fatura ficaria inflada para sempre.
        $chargeAmount = pagarme_invoiceBaseAmount($params['invoiceid']);
        pagarme_log($params, array(
            'installments' => $installments,
            'new_total'    => $chargeAmount,
        ), 'capture: taxa de parcelamento anterior removida (faixa sem juros)');
    }

    $amountCents = (int) round($chargeAmount * 100);

    $items = array(
        array(
            // 'code' é obrigatório na API v5 da Pagar.me: identifica o item
            // no sistema de origem. Usamos o ID da fatura do WHMCS.
            'code'        => 'INV' . $params['invoiceid'],
            'amount'      => $amountCents,
            'description' => pagarme_getDescription($params),
            'quantity'    => 1,
        ),
    );

    if (!empty($params['gatewayid'])) {
        // --- Cenário 1: cobrança de cartão salvo (token) ---
        $token = pagarme_parseToken($params['gatewayid']);

        if (!$token) {
            return array(
                'status'  => 'declined',
                'rawdata' => 'Token de cartão salvo inválido ou corrompido.',
            );
        }

        $payload = array(
            'customer_id' => $token['customer_id'],
            'items'       => $items,
            'payments'    => array(
                array(
                    'payment_method' => 'credit_card',
                    'credit_card'    => array(
                        'installments'         => $installments,
                        'statement_descriptor' => $descriptor,
                        'card_id'              => $token['card_id'],
                        'card'                 => array(
                            'billing_address' => pagarme_buildAddress($params),
                        ),
                    ),
                ),
            ),
            'metadata' => $metadata,
        );
    } else {
        // --- Cenário 2: cartão digitado no checkout ---
        $document = pagarme_getCustomerDocument($params);

        if (empty($document)) {
            return array(
                'status'  => 'declined',
                'rawdata' => 'CPF/CNPJ do cliente não encontrado. Cadastre um Custom Client Field '
                    . 'com o CPF/CNPJ e informe o nome dele nas configurações do gateway.',
            );
        }

        $holderName = pagarme_getHolderName($params);
        $cardExpiry = (string) $params['cardexp']; // formato MMYY

        $payload = array(
            'items'    => $items,
            'customer' => pagarme_buildCustomerPayload($params, $document, $holderName),
            'payments' => array(
                array(
                    'payment_method' => 'credit_card',
                    'credit_card'    => array(
                        'installments'         => $installments,
                        'statement_descriptor' => $descriptor,
                        'card' => array(
                            'number'      => preg_replace('/\D/', '', $params['cardnum']),
                            'holder_name' => $holderName,
                            'exp_month'   => (int) substr($cardExpiry, 0, 2),
                            'exp_year'    => (int) ('20' . substr($cardExpiry, 2, 2)),
                            'cvv'         => pagarme_getCvv($params),
                            'billing_address' => pagarme_buildAddress($params),
                        ),
                    ),
                ),
            ),
            'metadata' => $metadata,
        );
    }

    $response = $api->createOrder($payload);

    if ($response === false) {
        pagarme_log($params, $api->getLastError(), 'capture: falha na comunicação/validação');
        return array(
            'status'  => 'declined',
            'rawdata' => $api->getLastError(),
        );
    }

    $charge = isset($response['charges'][0]) ? $response['charges'][0] : null;

    if (!$charge || empty($charge['status'])) {
        pagarme_log($params, $response, 'capture: resposta sem cobrança válida');
        return array(
            'status'  => 'declined',
            'rawdata' => $response,
        );
    }

    switch ($charge['status']) {
        case 'paid':
            pagarme_log($params, array(
                'charge_id'    => $charge['id'],
                'order_id'     => $response['id'],
                'installments' => $installments,
                'amount'       => $chargeAmount,
            ), 'capture: pago');

            // Se houve juros de parcelamento, o total cobrado é maior que o
            // $params['amount'] original. Já adicionamos o item de juros à
            // fatura (total = amount + juros). Aqui aplicamos SOMENTE a parcela
            // do juros, deixando o saldo restante igual ao $params['amount'] -
            // que é exatamente o que o WHMCS aplica em seguida ao receber o
            // 'success'. Assim a fatura fecha em zero sem duplicar pagamento,
            // funcione o WHMCS com $params['amount'] ou relendo o saldo.
            if ($chargeAmount > $params['amount']) {
                pagarme_applyInterestPortion(
                    $params['invoiceid'],
                    $charge['id'],
                    $chargeAmount - $params['amount']
                );
            }

            // A escolha já foi cobrada; o registro não deve sobreviver para uma
            // eventual fatura futura de mesmo id em ambiente restaurado.
            if (function_exists('pagarme_clearStoredInstallments')) {
                pagarme_clearStoredInstallments($params['invoiceid']);
            }

            return array(
                'status'  => 'success',
                'transid' => $charge['id'],
                'orderid' => $response['id'],
                'rawdata' => $response,
            );

        case 'processing':
        case 'pending':
            // Em análise antifraude. A confirmação chega depois via webhook
            // (order.paid / order.payment_failed), tratado em callback/pagarme.php
            pagarme_log($params, array('charge_id' => $charge['id']), 'capture: em análise (pending)');
            return array(
                'status'  => 'pending',
                'transid' => $charge['id'],
                'rawdata' => $response,
            );

        default:
            // Extrai um motivo legível da recusa quando disponível
            $motivo = pagarme_extractDeclineReason($charge);
            pagarme_log($params, $response, 'capture: recusado (' . $charge['status'] . ') ' . $motivo);
            return array(
                'status'  => 'declined',
                'rawdata' => $response,
            );
    }
}

/**
 * Extrai uma mensagem legível de recusa a partir da última transação da cobrança.
 *
 * @param array $charge
 * @return string
 */
function pagarme_extractDeclineReason($charge)
{
    if (empty($charge['last_transaction'])) {
        return '';
    }

    $lt = $charge['last_transaction'];

    foreach (array('acquirer_message', 'gateway_response', 'status') as $key) {
        if (!empty($lt[$key])) {
            if (is_array($lt[$key])) {
                return json_encode($lt[$key]);
            }
            return (string) $lt[$key];
        }
    }

    return '';
}

/**
 * Estorno (reembolso) de uma cobrança já paga
 *
 * @param array $params
 * @return array
 */
function pagarme_refund($params)
{
    $secretKey = pagarme_getSecretKey($params);

    if (empty($secretKey)) {
        return array(
            'status'  => 'declined',
            'rawdata' => 'Secret Key não configurada no módulo de pagamento.',
        );
    }

    if (empty($params['transid'])) {
        return array(
            'status'  => 'declined',
            'rawdata' => 'Transação de origem não informada para o estorno.',
        );
    }

    $api      = new PagarmeApi($secretKey);
    $response = $api->cancelCharge($params['transid']);

    if ($response === false) {
        return array(
            'status'  => 'declined',
            'rawdata' => $api->getLastError(),
        );
    }

    return array(
        'status'  => 'success',
        'transid' => $params['transid'],
        'rawdata' => $response,
    );
}

// =========================================================================
// Tokenização (cartão salvo para cobranças futuras)
// =========================================================================

/**
 * Salva um cartão na Pagar.me para uso em cobranças futuras, sem que o
 * cliente precise redigitar os dados a cada fatura.
 *
 * Chamada pelo WHMCS quando o cliente adiciona ou atualiza um cartão
 * (ex.: Área do Cliente > Detalhes de Pagamento).
 *
 * @param array $params
 * @return array
 */
function pagarme_storeremote($params)
{
    $secretKey = pagarme_getSecretKey($params);

    if (empty($secretKey)) {
        return array(
            'status'  => 'declined',
            'rawdata' => 'Secret Key não configurada no módulo de pagamento.',
        );
    }

    // O WHMCS informa a operação desejada em $params['action']:
    // create (novo cartão), update (substituir) ou delete (remover).
    $action = isset($params['action']) ? $params['action'] : 'create';

    if ($action === 'delete') {
        return pagarme_removeremote($params);
    }

    $document = pagarme_getCustomerDocument($params);

    if (empty($document)) {
        $reason = 'CPF/CNPJ do cliente não encontrado. Cadastre um Custom Client Field '
            . 'com o CPF/CNPJ e informe o nome dele nas configurações do gateway, '
            . 'ou preencha o Tax ID do cliente.';
        pagarme_log($params, array(
            'clientid'  => isset($params['clientdetails']['userid']) ? $params['clientdetails']['userid'] : null,
            'fieldName' => isset($params['cpfCustomField']) ? $params['cpfCustomField'] : null,
        ), 'storeremote: ' . $reason);
        return array(
            'status'  => 'declined',
            'rawdata' => $reason,
        );
    }

    $api        = new PagarmeApi($secretKey);
    $holderName = pagarme_getHolderName($params);

    // 1. Cria (ou recria) o cliente na Pagar.me
    $customer = $api->createCustomer(
        pagarme_buildCustomerPayload($params, $document, $holderName)
    );

    if ($customer === false || empty($customer['id'])) {
        $err = $api->getLastError() ?: 'Não foi possível criar o cliente na Pagar.me.';
        pagarme_log($params, $err, 'storeremote: falha ao criar cliente');
        return array(
            'status'  => 'declined',
            'rawdata' => $err,
        );
    }

    // 2. Salva o cartão vinculado a esse cliente
    $cardExpiry = (string) $params['cardexp']; // formato MMYY
    $cvv        = pagarme_getCvv($params);

    if ($cvv === '') {
        $reason = 'CVV não disponível nesta operação. A Pagar.me exige o CVV para salvar '
            . 'um cartão, mas o WHMCS não o fornece em atualizações automáticas. '
            . 'Peça ao cliente para cadastrar o cartão novamente informando o código de segurança.';
        pagarme_log($params, array('action' => $action), 'storeremote: CVV indisponível');
        return array(
            'status'  => 'declined',
            'rawdata' => $reason,
        );
    }

    $card = $api->createCard($customer['id'], array(
        'number'      => preg_replace('/\D/', '', $params['cardnum']),
        'holder_name' => $holderName,
        'exp_month'   => (int) substr($cardExpiry, 0, 2),
        'exp_year'    => (int) ('20' . substr($cardExpiry, 2, 2)),
        'cvv'         => $cvv,
    ));

    if ($card === false || empty($card['id'])) {
        $err = $api->getLastError() ?: 'Não foi possível salvar o cartão na Pagar.me.';
        pagarme_log($params, $err, 'storeremote: falha ao salvar cartão');
        return array(
            'status'  => 'declined',
            'rawdata' => $err,
        );
    }

    pagarme_log($params, array(
        'customer_id'      => $customer['id'],
        'card_id'          => $card['id'],
        'brand'            => isset($card['brand']) ? $card['brand'] : '',
        'last_four_digits' => isset($card['last_four_digits']) ? $card['last_four_digits'] : '',
    ), 'storeremote: cartão salvo com sucesso');

    return array(
        'status'    => 'success',
        'gatewayid' => pagarme_buildToken($customer['id'], $card['id'], isset($card['brand']) ? $card['brand'] : ''),
        'cardType'  => isset($card['brand']) ? $card['brand'] : '',
        'lastFour'  => isset($card['last_four_digits']) ? $card['last_four_digits'] : '',
        'expDate'   => $cardExpiry,
        'rawdata'   => $card,
    );
}

/**
 * Remove um cartão salvo, quando o cliente exclui ou substitui o cartão
 * previamente tokenizado.
 *
 * @param array $params
 * @return array
 */
function pagarme_removeremote($params)
{
    $secretKey = pagarme_getSecretKey($params);
    $token     = pagarme_parseToken(isset($params['gatewayid']) ? $params['gatewayid'] : '');

    if (!$token) {
        // Nada a remover na Pagar.me: token inexistente ou já inválido
        return array('status' => 'success');
    }

    if (empty($secretKey)) {
        return array(
            'status'  => 'declined',
            'rawdata' => 'Secret Key não configurada no módulo de pagamento.',
        );
    }

    $api      = new PagarmeApi($secretKey);
    $response = $api->deleteCard($token['customer_id'], $token['card_id']);

    if ($response === false) {
        return array(
            'status'  => 'declined',
            'rawdata' => $api->getLastError(),
        );
    }

    return array(
        'status'  => 'success',
        'rawdata' => $response,
    );
}

// =========================================================================
// Funções auxiliares
// =========================================================================

/**
 * Retorna a Secret Key conforme o modo (produção ou sandbox)
 *
 * @param array $params
 * @return string
 */
function pagarme_getSecretKey($params)
{
    $isTestMode = isset($params['testMode']) && $params['testMode'] == 'on';

    return $isTestMode
        ? (isset($params['secretKeyTest']) ? $params['secretKeyTest'] : '')
        : (isset($params['secretKeyLive']) ? $params['secretKeyLive'] : '');
}

/**
 * Descritor exibido na fatura do cartão (máximo 13 caracteres)
 *
 * @param array $params
 * @return string
 */
function pagarme_getDescriptor($params)
{
    $descriptor = !empty($params['statementDescriptor']) ? $params['statementDescriptor'] : 'LOJA';

    return substr($descriptor, 0, 13);
}

/**
 * Descrição do item cobrado
 *
 * @param array $params
 * @return string
 */
function pagarme_getDescription($params)
{
    if (!empty($params['description'])) {
        return substr($params['description'], 0, 255);
    }

    return 'Fatura #' . $params['invoiceid'];
}

/**
 * Nome do portador, montado a partir dos dados do cliente
 *
 * @param array $params
 * @return string
 */
function pagarme_getHolderName($params)
{
    return trim(
        $params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname']
    );
}

/**
 * Monta o objeto "customer" usado tanto na cobrança avulsa quanto no
 * cadastro de cartão para cobranças futuras.
 *
 * @param array  $params
 * @param string $document   CPF/CNPJ apenas com dígitos
 * @param string $holderName
 * @return array
 */
function pagarme_buildCustomerPayload($params, $document, $holderName)
{
    $documentType = strlen($document) > 11 ? 'company' : 'individual';

    return array(
        'name'     => $holderName,
        'email'    => $params['clientdetails']['email'],
        'type'     => $documentType,
        'document' => $document,
        'phones'   => array(
            'mobile_phone' => pagarme_parsePhone($params['clientdetails']['phonenumber']),
        ),
        'address' => pagarme_buildAddress($params),
    );
}

/**
 * Monta o objeto de endereço no formato da Pagar.me a partir dos dados do
 * cliente. Usado tanto no cadastro do cliente quanto no billing_address da
 * cobrança (a Pagar.me exige billing_address ao cobrar um cartão salvo).
 *
 * @param array $params
 * @return array
 */
function pagarme_buildAddress($params)
{
    $cd = isset($params['clientdetails']) ? $params['clientdetails'] : array();

    $line1 = trim(
        (isset($cd['address1']) ? $cd['address1'] : '') . ' ' .
        (isset($cd['address2']) ? $cd['address2'] : '')
    );

    return array(
        'line_1'   => $line1 !== '' ? $line1 : 'Não informado',
        'zip_code' => preg_replace('/\D/', '', isset($cd['postcode']) ? $cd['postcode'] : ''),
        'city'     => isset($cd['city']) ? $cd['city'] : '',
        'state'    => isset($cd['state']) ? $cd['state'] : '',
        'country'  => 'BR',
    );
}

/**
 * Determina em quantas parcelas a cobrança deve ser feita.
 *
 * Regras:
 *   - Parcelamento precisa estar habilitado na configuração.
 *   - O teto de parcelas depende do ciclo de cobrança da fatura (ver
 *     pagarme_maxInstallmentsForMonths): mensal 1x, trimestral 3x,
 *     semestral 6x, anual ou superior 12x.
 *   - O cliente escolhe entre 1x e o teto via seletor no checkout (campo
 *     "pagarme_installments" no request).
 *   - Qualquer valor inválido ou fora do intervalo cai para 1x (à vista).
 *
 * Acima da faixa sem juros do ciclo (ver pagarme_freeInstallmentsForMonths),
 * o juros é repassado ao comprador em pagarme_capture().
 *
 * @param array $params
 * @return int
 */
function pagarme_resolveInstallments($params, $maxInstallments = null)
{
    // Parcelamento desabilitado -> à vista
    if (empty($params['enableInstallments']) || $params['enableInstallments'] != 'on') {
        return 1;
    }

    // Teto por ciclo da fatura (mensal 1x, trimestral 3x, semestral 6x, anual+ 12x)
    if ($maxInstallments === null) {
        $maxInstallments = pagarme_maxInstallmentsForInvoice(
            isset($params['invoiceid']) ? $params['invoiceid'] : 0
        );
    }

    // Ciclo que só permite à vista
    if ($maxInstallments <= 1) {
        return 1;
    }

    // Valor escolhido pelo cliente.
    $selected = pagarme_readSelectedInstallments(
        isset($params['invoiceid']) ? $params['invoiceid'] : 0
    );

    if ($selected < 1) {
        $selected = 1;
    }
    if ($selected > $maxInstallments) {
        $selected = $maxInstallments;
    }

    return $selected;
}

/**
 * Lê o número de parcelas escolhido pelo cliente.
 *
 * Ordem de prioridade:
 *   1. $_REQUEST['pagarme_installments'] - área do cliente do WHMCS, onde o
 *      seletor injetado pelo hook posta o campo junto do formulário.
 *   2. Registro persistido via SetPagarmeInstallments - checkouts headless,
 *      onde a escolha chega numa requisição e a cobrança em outra.
 *   3. 1x.
 *
 * O fallback por cookie foi removido: não funciona em chamada de API e podia
 * aplicar a escolha de uma fatura a outra.
 *
 * NÃO ler campos de outros gateways (ex: 'lknc_installment' da Cielo, que usa
 * outro modelo de juros); misturar causaria divergência entre exibido e cobrado.
 *
 * @param int $invoiceId
 * @return int
 */
function pagarme_readSelectedInstallments($invoiceId = 0)
{
    if (isset($_REQUEST['pagarme_installments']) && $_REQUEST['pagarme_installments'] !== '') {
        return (int) $_REQUEST['pagarme_installments'];
    }

    if ($invoiceId > 0 && function_exists('pagarme_readStoredInstallments')) {
        $stored = pagarme_readStoredInstallments($invoiceId);
        if ($stored['status'] === 'valid') {
            return (int) $stored['installments'];
        }
    }

    return 1;
}

/**
 * Verifica se TODOS os itens da fatura pertencem a serviços de ciclo anual
 * ou maior (Annually, Biennially, Triennially).
 *
 * Faturas sem itens de serviço identificáveis, ou com qualquer item de ciclo
 * menor que anual, retornam false (parcelamento não liberado).
 *
 * @param array $params
 * @return bool
 */
function pagarme_isInvoiceAnnual($params)
{
    if (!function_exists('localAPI') || empty($params['invoiceid'])) {
        return false;
    }

    $ciclosAnuais = array('Annually', 'Biennially', 'Triennially');

    $invoice = localAPI('GetInvoice', array('invoiceid' => $params['invoiceid']));

    if (empty($invoice['items']['item'])) {
        return false;
    }

    $items = $invoice['items']['item'];
    // A API pode retornar um único item como array associativo simples
    if (isset($items['id'])) {
        $items = array($items);
    }

    $encontrouServico = false;

    foreach ($items as $item) {
        // Só nos interessam itens ligados a um serviço/produto (type "Hosting")
        if (empty($item['type']) || strtolower($item['type']) !== 'hosting') {
            continue;
        }
        if (empty($item['relid'])) {
            continue;
        }

        $service = localAPI('GetClientsProducts', array('serviceid' => $item['relid']));

        if (empty($service['products']['product'][0]['billingcycle'])) {
            return false;
        }

        $cycle = $service['products']['product'][0]['billingcycle'];
        if (!in_array($cycle, $ciclosAnuais, true)) {
            return false; // achou um item não-anual -> bloqueia parcelamento
        }

        $encontrouServico = true;
    }

    return $encontrouServico;
}

/**
 * Obtém o CVV do cartão.
 *
 * O WHMCS não inclui o CVV nos parâmetros passados para storeremote (por
 * conformidade PCI-DSS, o CVV não pode ser armazenado), mas a Pagar.me exige
 * o CVV para criar um cartão salvo. Por isso buscamos também no POST da
 * requisição atual, onde o valor existe em memória enquanto o cliente submete
 * o formulário.
 *
 * O CVV é usado apenas nesta requisição e nunca é armazenado ou logado.
 *
 * @param array $params
 * @return string CVV apenas com dígitos, ou string vazia se indisponível
 */
function pagarme_getCvv($params)
{
    // 1) Parâmetro do módulo (presente no capture)
    if (!empty($params['cccvv'])) {
        return preg_replace('/\D/', '', $params['cccvv']);
    }

    // 2) POST do formulário atual (necessário no storeremote)
    $chaves = array('cccvv', 'cvv', 'ccv', 'cardcvv', 'card_cvv');

    foreach ($chaves as $chave) {
        if (!empty($_POST[$chave])) {
            return preg_replace('/\D/', '', $_POST[$chave]);
        }
        if (!empty($_REQUEST[$chave])) {
            return preg_replace('/\D/', '', $_REQUEST[$chave]);
        }
    }

    return '';
}

/**
 * Registra uma entrada no Gateway Log do WHMCS de forma segura.
 *
 * NUNCA registra número de cartão ou CVV - apenas respostas da Pagar.me
 * (que trazem no máximo os 4 últimos dígitos) e mensagens de erro.
 *
 * @param array        $params Parâmetros do gateway (para descobrir o nome)
 * @param mixed        $data   Dados a registrar (resposta da API, texto, etc.)
 * @param string       $result Rótulo do resultado (ex: "storeremote: sucesso")
 * @return void
 */
function pagarme_log($params, $data, $result)
{
    if (!function_exists('logTransaction')) {
        return;
    }

    $name = 'pagarme';
    if (!empty($params['name'])) {
        $name = $params['name'];
    } elseif (!empty($params['paymentmethod'])) {
        $name = $params['paymentmethod'];
    }

    try {
        logTransaction($name, $data, $result);
    } catch (\Exception $e) {
        // Log é auxiliar; nunca deve interromper o fluxo de pagamento
    }
}

/**
 * Busca o CPF/CNPJ do cliente no Custom Client Field configurado.
 *
 * Consulta direto as tabelas de custom fields via Capsule, o que é
 * independente da versão do WHMCS (a resposta da API GetClientsDetails
 * varia entre string e array conforme a versão).
 *
 * @param array $params
 * @return string|null Documento apenas com dígitos, ou null se não encontrado
 */
function pagarme_getCustomerDocument($params)
{
    $fieldName = !empty($params['cpfCustomField']) ? trim($params['cpfCustomField']) : 'CPF/CNPJ';

    $clientId = null;
    if (!empty($params['clientdetails']['userid'])) {
        $clientId = (int) $params['clientdetails']['userid'];
    } elseif (!empty($params['userid'])) {
        $clientId = (int) $params['userid'];
    }

    if (!$clientId) {
        return null;
    }

    // 1) Campo personalizado, via banco (Capsule) - mais confiável
    $value = pagarme_lookupCustomFieldDb($clientId, $fieldName);

    // 2) Campo personalizado, via API (fallback, tolera array OU string)
    if ($value === null) {
        $value = pagarme_lookupCustomFieldApi($clientId, $fieldName);
    }

    // 3) Fallback: campo nativo Tax ID / VAT do cliente
    //    (muitos WHMCS no Brasil guardam o CPF/CNPJ ali)
    if ($value === null && !empty($params['clientdetails']['tax_id'])) {
        $value = $params['clientdetails']['tax_id'];
    }
    if ($value === null && class_exists('\WHMCS\Database\Capsule')) {
        try {
            $taxId = \WHMCS\Database\Capsule::table('tblclients')
                ->where('id', $clientId)
                ->value('tax_id');
            if (!empty($taxId)) {
                $value = $taxId;
            }
        } catch (\Exception $e) {
            // ignora e segue
        }
    }

    if ($value === null) {
        return null;
    }

    $digits = preg_replace('/\D/', '', $value);
    return $digits !== '' ? $digits : null;
}

/**
 * Busca o valor de um campo personalizado de cliente direto no banco.
 *
 * @param int    $clientId
 * @param string $fieldName
 * @return string|null
 */
function pagarme_lookupCustomFieldDb($clientId, $fieldName)
{
    if (!class_exists('\WHMCS\Database\Capsule')) {
        return null;
    }

    try {
        $query = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->join(
                'tblcustomfields',
                'tblcustomfields.id',
                '=',
                'tblcustomfieldsvalues.fieldid'
            )
            ->where('tblcustomfields.type', 'client')
            ->where('tblcustomfieldsvalues.relid', $clientId);

        // Correspondência exata; se falhar, tenta parcial (LIKE)
        $value = (clone $query)
            ->where('tblcustomfields.fieldname', $fieldName)
            ->value('tblcustomfieldsvalues.value');

        if ($value === null) {
            $value = (clone $query)
                ->where('tblcustomfields.fieldname', 'like', '%' . $fieldName . '%')
                ->value('tblcustomfieldsvalues.value');
        }

        return !empty($value) ? $value : null;
    } catch (\Exception $e) {
        return null;
    }
}

/**
 * Busca o valor de um campo personalizado de cliente via API GetClientsDetails,
 * tolerando resposta em array (WHMCS novo) ou string (WHMCS antigo).
 *
 * @param int    $clientId
 * @param string $fieldName
 * @return string|null
 */
function pagarme_lookupCustomFieldApi($clientId, $fieldName)
{
    if (!function_exists('localAPI')) {
        return null;
    }

    $result = localAPI('GetClientsDetails', array(
        'clientid' => $clientId,
        'stats'    => false,
    ));

    if (empty($result['customfields'])) {
        return null;
    }

    $custom = $result['customfields'];

    // Formato mais novo: array de itens (['id'=>, 'value'=>] etc.)
    if (is_array($custom)) {
        foreach ($custom as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = '';
            foreach (array('translated_fieldname', 'fieldname', 'name') as $key) {
                if (!empty($field[$key])) {
                    $name = $field[$key];
                    break;
                }
            }

            if ($name !== '' && stripos($name, $fieldName) !== false && !empty($field['value'])) {
                return $field['value'];
            }
        }

        return null;
    }

    // Formato antigo: string com linhas "Campo|Valor"
    foreach (explode("\n", (string) $custom) as $line) {
        if (stripos($line, $fieldName) !== false) {
            $parts = explode('|', $line, 2);
            if (isset($parts[1]) && trim($parts[1]) !== '') {
                return $parts[1];
            }
        }
    }

    return null;
}

/**
 * Converte um telefone em formato livre para o objeto exigido pela Pagar.me
 *
 * @param string $phoneNumber
 * @return array
 */
function pagarme_parsePhone($phoneNumber)
{
    $digits = preg_replace('/\D/', '', (string) $phoneNumber);
    $digits = ltrim($digits, '0');

    // Remove o DDI 55 caso já esteja incluso no número
    if (substr($digits, 0, 2) === '55' && strlen($digits) > 11) {
        $digits = substr($digits, 2);
    }

    $areaCode = substr($digits, 0, 2);
    $number   = substr($digits, 2);

    return array(
        'country_code' => '55',
        'area_code'    => $areaCode !== '' ? $areaCode : '11',
        'number'       => $number !== '' ? $number : '900000000',
    );
}

/**
 * Codifica customer_id + card_id em um único token, armazenado pelo WHMCS
 * no campo "gatewayid" para uso em cobranças futuras.
 *
 * @param string $customerId
 * @param string $cardId
 * @return string
 */
function pagarme_buildToken($customerId, $cardId, $brand = '')
{
    // Formato: customer_id|card_id|brand (brand opcional, para a tabela de taxas)
    return $customerId . '|' . $cardId . '|' . $brand;
}

/**
 * Decodifica o token salvo pelo WHMCS de volta em customer_id + card_id + brand.
 * Tolera tokens antigos no formato customer_id|card_id (sem bandeira).
 *
 * @param string $token
 * @return array|null
 */
function pagarme_parseToken($token)
{
    if (empty($token) || strpos($token, '|') === false) {
        return null;
    }

    $parts      = explode('|', $token);
    $customerId = isset($parts[0]) ? $parts[0] : '';
    $cardId     = isset($parts[1]) ? $parts[1] : '';
    $brand      = isset($parts[2]) ? $parts[2] : '';

    if (empty($customerId) || empty($cardId)) {
        return null;
    }

    return array(
        'customer_id' => $customerId,
        'card_id'     => $cardId,
        'brand'       => $brand,
    );
}

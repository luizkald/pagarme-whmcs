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
        'maxInstallments' => array(
            'FriendlyName' => 'Parcelas Máximas',
            'Type'         => 'dropdown',
            'Options'      => '1,2,3,4,5,6,7,8,9,10,11,12',
            'Description'  => 'Número máximo de parcelas. Por padrão o módulo cobra à vista (1x); '
                . 'oferecer a escolha ao cliente requer customização do template de checkout.',
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

    $items = array(
        array(
            'amount'      => $amountCents,
            'description' => pagarme_getDescription($params),
            'quantity'    => 1,
        ),
    );

    $metadata = array(
        'whmcs_invoice_id' => (string) $params['invoiceid'],
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
                        'installments'         => 1,
                        'statement_descriptor' => $descriptor,
                        'card_id'              => $token['card_id'],
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
                        'installments'         => 1,
                        'statement_descriptor' => $descriptor,
                        'card' => array(
                            'number'      => preg_replace('/\D/', '', $params['cardnum']),
                            'holder_name' => $holderName,
                            'exp_month'   => (int) substr($cardExpiry, 0, 2),
                            'exp_year'    => (int) ('20' . substr($cardExpiry, 2, 2)),
                            'cvv'         => $params['cvv'],
                        ),
                    ),
                ),
            ),
            'metadata' => $metadata,
        );
    }

    $response = $api->createOrder($payload);

    if ($response === false) {
        return array(
            'status'  => 'declined',
            'rawdata' => $api->getLastError(),
        );
    }

    $charge = isset($response['charges'][0]) ? $response['charges'][0] : null;

    if (!$charge || empty($charge['status'])) {
        return array(
            'status'  => 'declined',
            'rawdata' => $response,
        );
    }

    switch ($charge['status']) {
        case 'paid':
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
            return array(
                'status'  => 'pending',
                'transid' => $charge['id'],
                'rawdata' => $response,
            );

        default:
            return array(
                'status'  => 'declined',
                'rawdata' => $response,
            );
    }
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

    $document = pagarme_getCustomerDocument($params);

    if (empty($document)) {
        return array(
            'status'  => 'declined',
            'rawdata' => 'CPF/CNPJ do cliente não encontrado. Cadastre um Custom Client Field '
                . 'com o CPF/CNPJ e informe o nome dele nas configurações do gateway.',
        );
    }

    $api        = new PagarmeApi($secretKey);
    $holderName = pagarme_getHolderName($params);

    // 1. Cria (ou recria) o cliente na Pagar.me
    $customer = $api->createCustomer(
        pagarme_buildCustomerPayload($params, $document, $holderName)
    );

    if ($customer === false || empty($customer['id'])) {
        return array(
            'status'  => 'declined',
            'rawdata' => $api->getLastError() ?: 'Não foi possível criar o cliente na Pagar.me.',
        );
    }

    // 2. Salva o cartão vinculado a esse cliente
    $cardExpiry = (string) $params['cardexp']; // formato MMYY

    $card = $api->createCard($customer['id'], array(
        'number'      => preg_replace('/\D/', '', $params['cardnum']),
        'holder_name' => $holderName,
        'exp_month'   => (int) substr($cardExpiry, 0, 2),
        'exp_year'    => (int) ('20' . substr($cardExpiry, 2, 2)),
        'cvv'         => $params['cvv'],
    ));

    if ($card === false || empty($card['id'])) {
        return array(
            'status'  => 'declined',
            'rawdata' => $api->getLastError() ?: 'Não foi possível salvar o cartão na Pagar.me.',
        );
    }

    return array(
        'status'    => 'success',
        'gatewayid' => pagarme_buildToken($customer['id'], $card['id']),
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
        'address' => array(
            'line_1'   => trim(
                $params['clientdetails']['address1'] . ' ' . $params['clientdetails']['address2']
            ),
            'zip_code' => preg_replace('/\D/', '', $params['clientdetails']['postcode']),
            'city'     => $params['clientdetails']['city'],
            'state'    => $params['clientdetails']['state'],
            'country'  => 'BR',
        ),
    );
}

/**
 * Busca o CPF/CNPJ do cliente no Custom Client Field configurado
 *
 * @param array $params
 * @return string|null Documento apenas com dígitos, ou null se não encontrado
 */
function pagarme_getCustomerDocument($params)
{
    if (!function_exists('localAPI')) {
        return null;
    }

    $fieldName = !empty($params['cpfCustomField']) ? $params['cpfCustomField'] : 'CPF/CNPJ';

    $clientId = null;
    if (!empty($params['clientdetails']['userid'])) {
        $clientId = $params['clientdetails']['userid'];
    } elseif (!empty($params['userid'])) {
        $clientId = $params['userid'];
    }

    if (!$clientId) {
        return null;
    }

    $result = localAPI('GetClientsDetails', array(
        'clientid' => $clientId,
        'stats'    => false,
    ));

    if (empty($result['customfields'])) {
        return null;
    }

    // O retorno vem como string, uma linha por campo, no formato "Campo|Valor"
    foreach (explode("\n", $result['customfields']) as $line) {
        if (stripos($line, $fieldName) !== false) {
            $parts = explode('|', $line, 2);
            if (isset($parts[1])) {
                $digits = preg_replace('/\D/', '', $parts[1]);
                return $digits !== '' ? $digits : null;
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
function pagarme_buildToken($customerId, $cardId)
{
    return $customerId . '|' . $cardId;
}

/**
 * Decodifica o token salvo pelo WHMCS de volta em customer_id + card_id
 *
 * @param string $token
 * @return array|null
 */
function pagarme_parseToken($token)
{
    if (empty($token) || strpos($token, '|') === false) {
        return null;
    }

    list($customerId, $cardId) = explode('|', $token, 2);

    if (empty($customerId) || empty($cardId)) {
        return null;
    }

    return array(
        'customer_id' => $customerId,
        'card_id'     => $cardId,
    );
}

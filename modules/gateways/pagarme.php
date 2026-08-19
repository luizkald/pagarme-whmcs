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
        'axiomToken' => array(
            'FriendlyName' => 'Axiom - Token de Ingestão',
            'Type'         => 'password',
            'Size'         => '60',
            'Description'  => 'Opcional. Token de ingest do Axiom (axiom.co > Settings > API '
                . 'Tokens). Quando preenchido, erros reais de pagamento/cadastro de cartão '
                . '(nunca dado de cartão ou pessoal) são enviados também para o Axiom, além do '
                . 'Gateway Log de sempre. Em branco, o comportamento é idêntico a hoje.',
        ),
        'axiomDataset' => array(
            'FriendlyName' => 'Axiom - Dataset',
            'Type'         => 'text',
            'Size'         => '40',
            'Default'      => 'pagarme-whmcs',
            'Description'  => 'Nome do dataset no Axiom para onde os erros são enviados. Só tem '
                . 'efeito se o Token de Ingestão acima estiver preenchido.',
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
 *   1. $params['gatewayid'] preenchido -> cobra um cartão já tokenizado.
 *      Usado tanto pelo cron de cobrança automática / recorrência quanto por
 *      um cliente pagando fatura de pedido novo com cartão salvo - o WHMCS
 *      não distingue os dois casos aqui. Exige CVV só no segundo caso (ver
 *      pagarme_isNewOrderInvoice()); cron nunca tem CVV disponível.
 *   2. Caso contrário -> cobra os dados de cartão digitados no checkout
 *
 * @param array $params Parâmetros enviados pelo WHMCS
 * @return array Resultado no formato esperado pelo WHMCS
 */
function pagarme_capture($params)
{
    $secretKey = pagarme_getSecretKey($params);

    if (empty($secretKey)) {
        // Erro de configuração do lojista, nunca do cliente - mensagem
        // gravada para o app é genérica de propósito, sem citar "Secret Key"
        // (detalhe interno que não ajuda quem está pagando).
        pagarme_storeLastError(
            pagarme_clientIdFromParams($params),
            'technical_error',
            'O serviço de pagamento está temporariamente indisponível. Tente novamente em alguns instantes.',
            120,
            $params
        );
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
            $installmentsStaleMsg = 'A seleção de parcelamento expirou ou os valores da fatura '
                . 'mudaram. Refaça a escolha de parcelas antes de pagar.';
            pagarme_storeLastError(pagarme_clientIdFromParams($params), 'stale_installments', $installmentsStaleMsg, 120, $params);
            return array(
                'status'  => 'declined',
                'rawdata' => $installmentsStaleMsg,
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
    $baseAmount = pagarme_invoiceBaseAmount($params['invoiceid']);

    // Modo de cálculo (simples/composto + margem): usa o que estava
    // PERSISTIDO no momento em que o cliente viu o preço (via
    // pagarme_readStoredInstallments() acima), não a configuração ao vivo do
    // admin. Sem isso, um admin trocando o modo entre a escolha do cliente e
    // esta captura cobraria um valor diferente do que foi exibido - mesmo
    // risco que expected_total já protege para o total em si.
    $modeOverride = null;
    if (isset($stored) && isset($stored['status']) && $stored['status'] === 'valid') {
        $modeOverride = array(
            'formula'              => $stored['formula'],
            'compound_rate_source' => $stored['compound_rate_source'],
            'compound_custom_rate' => $stored['compound_custom_rate'],
            'margin_enabled'       => $stored['margin_enabled'],
            'margin'               => $stored['margin'],
        );
    }

    $installmentResult = pagarme_installmentTotal($baseAmount, $brand, $installments, $freeInstallments, $modeOverride);
    $customerRate = $installmentResult['rate'];
    $chargeAmount = $params['amount'];

    if ($installmentResult['fee_amount'] > 0) {
        $feeAmount = $installmentResult['fee_amount'];

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
            $reconcileFailMsg = 'Não foi possível processar o parcelamento desta fatura. Tente '
                . 'novamente em alguns instantes.';
            pagarme_storeLastError(pagarme_clientIdFromParams($params), 'technical_error', $reconcileFailMsg, 120, $params);
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
            $tokenInvalidMsg = 'Não foi possível usar este cartão salvo. Cadastre o cartão novamente.';
            pagarme_storeLastError(pagarme_clientIdFromParams($params), 'saved_card_invalid', $tokenInvalidMsg, 120, $params);
            return array(
                'status'  => 'declined',
                'rawdata' => 'Token de cartão salvo inválido ou corrompido.',
            );
        }

        // CVV em cartão salvo: exigido só em pedido novo (o cliente está na
        // tela, digitou o CVV, e os apps já bloqueiam o submit sem ele - ver
        // /pay-card nos dois apps). Renovação/recorrência via cron nunca tem
        // CVV disponível (sem sessão de navegador) - por isso não é exigido
        // ali, mesmo comportamento de sempre. pagarme_isNewOrderInvoice()
        // é o sinal que distingue os dois: WHMCS não manda contexto
        // cron-vs-manual para o módulo, então usamos o status do serviço
        // ligado à fatura (Pending = pedido novo).
        //
        // Exceção: cartão tokenizado com CVV confirmado há pouco tempo
        // (pagarme_recentlyTokenizedWithCvv) - fluxo "cadastrar cartão novo +
        // pagar na hora". O CVV já foi validado no storeremote; os apps não
        // reenviam de propósito (mesma lógica de não pedir a mesma senha duas
        // vezes na mesma operação - ver newCardAuthorization/JWT nos apps).
        // Sem esta exceção, esse fluxo pedia CVV duas vezes seguidas e
        // recusava a captura mesmo o cliente tendo acabado de confirmá-lo.
        $savedCardCvv = pagarme_getCvv($params);
        $cvvRecentlyConfirmed = pagarme_recentlyTokenizedWithCvv($token);

        if (empty($savedCardCvv) && !$cvvRecentlyConfirmed && pagarme_isNewOrderInvoice($params)) {
            pagarme_log($params, array(
                'gatewayid' => $params['gatewayid'],
            ), 'capture: CVV ausente para cartão salvo em pedido novo');

            $cvvRequiredMsg = 'Informe o código de segurança (CVV) do cartão para concluir este pagamento.';
            pagarme_storeLastError(pagarme_clientIdFromParams($params), 'cvv_required', $cvvRequiredMsg, 120, $params);
            return array(
                'status'  => 'declined',
                'rawdata' => 'CVV obrigatório para pagar com cartão salvo neste pedido.',
            );
        }

        $savedCard = array(
            'billing_address' => pagarme_buildAddress($params),
        );
        if (!empty($savedCardCvv)) {
            $savedCard['cvv'] = $savedCardCvv;
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
                        'card'                 => $savedCard,
                    ),
                ),
            ),
            'metadata' => $metadata,
        );
    } else {
        // --- Cenário 2: cartão digitado no checkout ---
        $document = pagarme_getCustomerDocument($params);

        if (empty($document)) {
            // Mensagem interna (rawdata/Gateway Log) é acionável para o admin
            // configurar o módulo. A gravada para o cliente é diferente: ele
            // não pode resolver "Custom Client Field ausente" - só pode
            // completar o próprio cadastro.
            $documentMissingMsg = 'CPF/CNPJ não encontrado no seu cadastro. Atualize seus dados '
                . 'cadastrais antes de tentar novamente.';
            pagarme_storeLastError(pagarme_clientIdFromParams($params), 'missing_document', $documentMissingMsg, 120, $params);
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
        pagarme_classifyAndStore(
            array('apiError' => $api->getLastErrorDetails()),
            pagarme_clientIdFromParams($params),
            $params
        );
        return array(
            'status'  => 'declined',
            'rawdata' => $api->getLastError(),
        );
    }

    $charge = isset($response['charges'][0]) ? $response['charges'][0] : null;

    if (!$charge || empty($charge['status'])) {
        pagarme_log($params, $response, 'capture: resposta sem cobrança válida');
        pagarme_classifyAndStore(array(), pagarme_clientIdFromParams($params), $params);
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

            // Taxa de transação (MDR real, tabela cheia à vista/2-6x/7-12x -
            // ver pagarme_transactionFeeAmount) sobre o valor bruto realmente
            // processado, usando a taxa REAL da FAIXA de parcelas escolhida
            // (ex: 12x usa a taxa real de 7-12x) - não fixa em 1x. Campo
            // interno do WHMCS (tblaccounts.fees), nunca cobrado do cliente,
            // mas reflete o custo MDR real que a Pagar.me cobra da loja para
            // aquela faixa de parcelas. Revertido em 14/08/2026 de "sempre
            // 1x" (decisão anterior no mesmo dia) de volta para a taxa da
            // faixa real, por decisão de negócio. É sobre esse total que a
            // Pagar.me também desconta o MDR, então rateamos entre as duas
            // parcelas do pagamento (base + juros de parcelamento, se houver)
            // na mesma proporção, para que a soma das duas bata com o valor
            // real.
            $transactionFee     = pagarme_transactionFeeAmount($chargeAmount, $brand, $installments);
            $baseTransactionFee = $transactionFee;
            $interestFee        = 0.0;

            // Se houve juros de parcelamento, o total cobrado é maior que o
            // $params['amount'] original. Já adicionamos o item de juros à
            // fatura (total = amount + juros). Aqui aplicamos SOMENTE a parcela
            // do juros, deixando o saldo restante igual ao $params['amount'] -
            // que é exatamente o que o WHMCS aplica em seguida ao receber o
            // 'success'. Assim a fatura fecha em zero sem duplicar pagamento,
            // funcione o WHMCS com $params['amount'] ou relendo o saldo.
            if ($chargeAmount > $params['amount']) {
                if ($transactionFee > 0) {
                    $baseTransactionFee = round($transactionFee * ($params['amount'] / $chargeAmount), 2);
                    $interestFee        = round($transactionFee - $baseTransactionFee, 2);
                }
                pagarme_applyInterestPortion(
                    $params['invoiceid'],
                    $charge['id'],
                    $chargeAmount - $params['amount'],
                    $interestFee
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
                'fee'     => $baseTransactionFee,
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
            pagarme_classifyAndStore(array('charge' => $charge), pagarme_clientIdFromParams($params), $params);
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
 * Tabela de códigos de retorno EMV/adquirente -> motivo seguro de exibir ao
 * cliente. Fonte: central de ajuda da Pagar.me (motivos de recusa de uma
 * transação). Cobre os códigos mais comuns em produção; qualquer código
 * ausente cai no fallback genérico de pagarme_classifyDeclineReason().
 *
 * Cada entrada: [code => motivo curto e seguro, action => o que o cliente
 * pode fazer]. Nunca inclui o código numérico cru nem texto da adquirente na
 * mensagem final - só a tradução.
 *
 * @return array<string, array{code:string, message:string}>
 */
function pagarme_emvReasonMap()
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $contateBanco = 'Pagamento recusado pelo banco emissor do cartão. Entre em contato com seu '
        . 'banco ou tente outro cartão.';
    $cvvInvalido = 'Código de segurança (CVV) inválido ou não informado. Verifique os números '
        . 'no verso do cartão.';
    $cartaoVencido = 'Cartão vencido ou data de validade incorreta. Verifique os dados do cartão.';
    $saldoInsuficiente = 'Tente outro cartão ou '
        . 'entre em contato com seu banco.';
    $cartaoRestrito = 'Cartão bloqueado ou com restrição. Entre em contato com seu banco ou tente '
        . 'outro cartão.';
    $suspeitaFraude = 'A transação foi recusada por segurança. Tente outro cartão ou entre em '
        . 'contato com seu banco.';
    $timeout = 'O banco emissor não respondeu a tempo. Tente novamente em alguns instantes.';
    $erroTecnico = 'Não foi possível processar o pagamento no momento. Tente novamente.';

    $map = array(
        // Recusa genérica / banco
        '1000' => array('code' => 'not_authorized', 'message' => $contateBanco),
        '1007' => array('code' => 'not_authorized', 'message' => $contateBanco),
        '1008' => array('code' => 'not_authorized', 'message' => $contateBanco),
        '2000' => array('code' => 'not_authorized', 'message' => $contateBanco),
        '5093' => array('code' => 'not_authorized', 'message' => $contateBanco),

        // Cartão vencido
        '1001' => array('code' => 'card_expired', 'message' => $cartaoVencido),
        '2001' => array('code' => 'card_expired', 'message' => $cartaoVencido),

        // Suspeita de fraude / segurança
        '1002' => array('code' => 'security_declined', 'message' => $suspeitaFraude),
        '1022' => array('code' => 'security_declined', 'message' => $suspeitaFraude),
        '1024' => array('code' => 'security_declined', 'message' => $suspeitaFraude),
        '1029' => array('code' => 'security_declined', 'message' => $suspeitaFraude),
        '2002' => array('code' => 'security_declined', 'message' => $suspeitaFraude),
        '2010' => array('code' => 'security_declined', 'message' => $suspeitaFraude),

        // Cartão com restrição / bloqueado
        '1004' => array('code' => 'card_restricted', 'message' => $cartaoRestrito),
        '1025' => array('code' => 'card_restricted', 'message' => $cartaoRestrito),
        '1032' => array('code' => 'card_restricted', 'message' => $cartaoRestrito),
        '1035' => array('code' => 'card_restricted', 'message' => $cartaoRestrito),
        '1040' => array('code' => 'card_restricted', 'message' => $cartaoRestrito),
        '2004' => array('code' => 'card_restricted', 'message' => $cartaoRestrito),
        '2007' => array('code' => 'card_restricted', 'message' => $cartaoRestrito),
        '2008' => array('code' => 'card_restricted', 'message' => $cartaoRestrito),
        '2009' => array('code' => 'card_restricted', 'message' => $cartaoRestrito),

        // Saldo/limite insuficiente
        '1016' => array('code' => 'insufficient_funds', 'message' => $saldoInsuficiente),

        // CVV / código de segurança
        '1045' => array('code' => 'cvv_invalid', 'message' => $cvvInvalido),
        '5025' => array('code' => 'cvv_invalid', 'message' => $cvvInvalido),
        '9124' => array('code' => 'cvv_invalid', 'message' => $cvvInvalido),

        // Senha (cartão de débito com senha, fora do escopo de crédito, mas
        // pode aparecer em cartões múltiplos função)
        '1006' => array('code' => 'not_authorized', 'message' => $contateBanco),
        '1017' => array('code' => 'not_authorized', 'message' => $contateBanco),

        // Timeout / indisponibilidade do banco - vale tentar de novo
        '9107' => array('code' => 'issuer_unavailable', 'message' => $timeout),
        '9109' => array('code' => 'issuer_unavailable', 'message' => $timeout),
        '9110' => array('code' => 'issuer_unavailable', 'message' => $timeout),
        '9111' => array('code' => 'issuer_unavailable', 'message' => $timeout),
        '9112' => array('code' => 'issuer_unavailable', 'message' => $timeout),

        // Erros técnicos do lado da adquirente/loja - não é problema do cartão
        '5000' => array('code' => 'technical_error', 'message' => $erroTecnico),
        '9100' => array('code' => 'technical_error', 'message' => $erroTecnico),
        '9999' => array('code' => 'technical_error', 'message' => $erroTecnico),
    );

    return $map;
}

/**
 * Classifica o motivo de uma recusa/erro em um par (code, message) SEGURO
 * para mostrar ao cliente - nunca o JSON cru da Pagar.me, nunca dados que a
 * API ecoou de volta (endereço, nome, telefone), nunca detalhe de
 * infraestrutura (chave de API, status HTTP interno).
 *
 * Aceita duas formas de entrada:
 *   - array com 'charge' => resposta de charge da Pagar.me (capture)
 *   - array com 'apiError' => PagarmeApi::getLastErrorDetails() (create
 *     customer/card, ou qualquer chamada que devolveu 4xx)
 *
 * Motivo não reconhecido cai num fallback genérico seguro - a mensagem NUNCA
 * fica vazia nem expõe o dado bruto por trás.
 *
 * @param array $context
 * @return array{code:string, message:string}
 */
function pagarme_classifyDeclineReason($context)
{
    $generic = array(
        'code'    => 'declined',
        'message' => 'Não foi possível processar o pagamento. Tente novamente ou use outro cartão.',
    );

    // --- Caminho 1: recusa de cobrança (charge.last_transaction) ---
    if (!empty($context['charge'])) {
        $charge = $context['charge'];
        $lt     = isset($charge['last_transaction']) ? $charge['last_transaction'] : array();

        $emvCode = null;
        foreach (array('acquirer_return_code', 'gateway_response') as $key) {
            if (!empty($lt[$key])) {
                $value = $lt[$key];
                if (is_array($value) && !empty($value['code'])) {
                    $emvCode = (string) $value['code'];
                } elseif (!is_array($value)) {
                    $emvCode = (string) $value;
                }
                if ($emvCode !== null) {
                    break;
                }
            }
        }

        if ($emvCode !== null) {
            $map = pagarme_emvReasonMap();
            if (isset($map[$emvCode])) {
                return $map[$emvCode];
            }
        }

        // Antifraude: recusado antes de chegar ao adquirente/emissor.
        if (!empty($charge['status']) && $charge['status'] === 'failed'
            && !empty($lt['status']) && stripos((string) $lt['status'], 'refus') !== false
        ) {
            return array(
                'code'    => 'security_declined',
                'message' => 'A transação foi recusada por segurança. Tente outro cartão ou entre '
                    . 'em contato com seu banco.',
            );
        }

        return $generic;
    }

    // --- Caminho 2: erro de validação/API (create customer, create card) ---
    if (!empty($context['apiError'])) {
        $apiError = $context['apiError'];
        $errors   = isset($apiError['errors']) && is_array($apiError['errors']) ? $apiError['errors'] : array();

        // Campos específicos que o CLIENTE consegue agir sobre. Nunca inclui
        // o valor que ele digitou (a Pagar.me ecoa isso em 'request', que
        // este classificador nunca lê) - só o NOME do campo problemático,
        // traduzido para uma instrução segura.
        $fieldMessages = array(
            'card.exp_month'    => 'Data de validade do cartão inválida. Verifique o mês e o ano no cartão.',
            'card.exp_year'     => 'Data de validade do cartão inválida. Verifique o mês e o ano no cartão.',
            'card'              => 'Dados do cartão inválidos. Verifique o número, validade e código de segurança.',
            'card.number'       => 'Número do cartão inválido. Verifique os dígitos e tente novamente.',
            'card.cvv'          => 'Código de segurança (CVV) inválido. Verifique os números no verso do cartão.',
            'card.holder_name'  => 'Nome do titular do cartão inválido ou ausente.',
            'card.billing_address.zip_code' => 'CEP de cobrança inválido. Verifique o endereço cadastrado.',
            'customer.name'     => 'Nome do titular ausente ou inválido no cadastro. Atualize seus '
                . 'dados cadastrais antes de tentar novamente.',
            'customer.email'    => 'E-mail cadastrado inválido. Atualize seus dados cadastrais.',
            'customer.document' => 'CPF/CNPJ inválido ou ausente no cadastro. Atualize seus dados '
                . 'cadastrais antes de tentar novamente.',
            'customer.address.zip_code' => 'CEP inválido no cadastro. Atualize seus dados cadastrais.',
        );

        foreach ($fieldMessages as $field => $message) {
            if (isset($errors[$field])) {
                return array('code' => 'invalid_field', 'message' => $message);
            }
        }

        // Campo não mapeado, mas a API disse claramente que é um problema de
        // validação (4xx com 'errors'): ainda assim não repassamos o texto
        // cru (pode ecoar dado digitado) - fallback genérico de validação.
        if (!empty($errors)) {
            return array(
                'code'    => 'invalid_field',
                'message' => 'Alguns dados informados são inválidos. Verifique o cartão e seus '
                    . 'dados cadastrais e tente novamente.',
            );
        }

        $httpCode = isset($apiError['httpCode']) ? (int) $apiError['httpCode'] : 0;
        if ($httpCode === 404) {
            return array(
                'code'    => 'technical_error',
                'message' => 'Não foi possível localizar seu cadastro de pagamento. Tente novamente '
                    . 'ou cadastre o cartão de novo.',
            );
        }
        if ($httpCode >= 500 || $httpCode === 0) {
            return array(
                'code'    => 'technical_error',
                'message' => 'O serviço de pagamento está temporariamente indisponível. Tente '
                    . 'novamente em alguns instantes.',
            );
        }

        return $generic;
    }

    return $generic;
}

// =========================================================================
// Motivo do último erro (para os apps mostrarem algo além de "recusado")
// =========================================================================
//
// Armazenamento (pagarme_ensureLastErrorTable/pagarme_storeLastError) mora
// em modules/gateways/pagarme/installments.php, não aqui - mesmo motivo que
// mod_pagarme_installments mora lá: é o arquivo que as custom API actions em
// includes/api/ já carregam sem exigir WHMCS definida (pagarme.php tem
// `if (!defined('WHMCS')) die(...)` no topo, installments.php não).

/**
 * Classifica e já grava de uma vez - atalho usado nos pontos de decline de
 * pagarme_capture()/pagarme_storeremote(). Sempre retorna o par (code,
 * message) classificado, mesmo se a gravação falhar.
 *
 * $params (opcional): repassado para pagarme_storeLastError() para também
 * notificar o Axiom, quando configurado - ver pagarme_sendToAxiom().
 *
 * @param array      $context  Ver pagarme_classifyDeclineReason()
 * @param int|string $clientId
 * @param array|null $params
 * @return array{code:string, message:string}
 */
function pagarme_classifyAndStore($context, $clientId, $params = null)
{
    $reason = pagarme_classifyDeclineReason($context);
    pagarme_storeLastError($clientId, $reason['code'], $reason['message'], 120, $params);
    return $reason;
}

/**
 * Extrai o clientid de $params, para os pontos de decline que só têm uma
 * mensagem própria (não vieram de charge/apiError da Pagar.me) e por isso
 * gravam o motivo diretamente, sem passar por pagarme_classifyDeclineReason().
 *
 * @param array $params
 * @return int
 */
function pagarme_clientIdFromParams($params)
{
    return isset($params['clientdetails']['userid']) ? (int) $params['clientdetails']['userid'] : 0;
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
        pagarme_storeLastError(
            pagarme_clientIdFromParams($params),
            'technical_error',
            'O serviço de pagamento está temporariamente indisponível. Tente novamente em alguns instantes.',
            120,
            $params
        );
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
        pagarme_storeLastError(
            pagarme_clientIdFromParams($params),
            'missing_document',
            'CPF/CNPJ não encontrado no seu cadastro. Atualize seus dados cadastrais antes de '
                . 'salvar um cartão.',
            120,
            $params
        );
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
        pagarme_classifyAndStore(
            array('apiError' => $api->getLastErrorDetails()),
            pagarme_clientIdFromParams($params),
            $params
        );
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
        pagarme_storeLastError(
            pagarme_clientIdFromParams($params),
            'cvv_required',
            'Informe o código de segurança (CVV) para salvar este cartão.',
            120,
            $params
        );
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
        pagarme_classifyAndStore(
            array('apiError' => $api->getLastErrorDetails()),
            pagarme_clientIdFromParams($params),
            $params
        );
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
        // CVV já foi confirmado agora (linha 971 acima recusa antes de chegar
        // aqui se estivesse ausente) - carimba o momento para
        // pagarme_recentlyTokenizedWithCvv() dispensar CVV numa captura
        // imediatamente seguinte (fluxo "cadastrar cartão novo + pagar na
        // hora"), sem pedir CVV duas vezes pela mesma operação do cliente.
        'gatewayid' => pagarme_buildToken(
            $customer['id'],
            $card['id'],
            isset($card['brand']) ? $card['brand'] : '',
            time()
        ),
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
 * Verifica se a fatura cobra ao menos um serviço ainda "Pending" - ou seja,
 * é a fatura de um PEDIDO NOVO (o serviço só vira "Active" quando esta
 * fatura é paga), não uma renovação/recorrência de um serviço já ativo.
 *
 * Usado para exigir CVV em cartão salvo só em pedido novo: o WHMCS não
 * distingue "cron de recorrência" de "cliente pagando fatura nova" dentro de
 * pagarme_capture() - os dois chegam com $params['gatewayid'] preenchido do
 * mesmo jeito. O status do serviço ligado à fatura é o sinal nativo do
 * WHMCS que separa os dois casos: cron/renovação sempre incide sobre
 * serviço já Active; pedido novo sempre nasce Pending até esta fatura
 * quitar. Mesmo padrão de leitura de itens/serviço de pagarme_isInvoiceAnnual().
 *
 * Fatura sem nenhum item de serviço identificável (ex: só domínio avulso,
 * ou taxa administrativa) é tratada como pedido novo por padrão - mais
 * seguro exigir CVV à toa do que deixar de exigir num caso real de compra.
 *
 * @param array $params
 * @return bool
 */
function pagarme_isNewOrderInvoice($params)
{
    if (!function_exists('localAPI') || empty($params['invoiceid'])) {
        return true;
    }

    $invoice = localAPI('GetInvoice', array('invoiceid' => $params['invoiceid']));

    if (empty($invoice['items']['item'])) {
        return true;
    }

    $items = $invoice['items']['item'];
    // A API pode retornar um único item como array associativo simples
    if (isset($items['id'])) {
        $items = array($items);
    }

    // Só itens de Hosting/Addon têm relid apontando para tblhosting - um
    // item de Domínio tem relid de tbldomains (outra tabela, outro espaço de
    // ids) e GetClientsProducts não serve pra ele. Mesma restrição de
    // pagarme_isInvoiceAnnual()/pagarme_minMonthsForInvoice().
    $foundHostingItem = false;

    foreach ($items as $item) {
        if (empty($item['type']) || strtolower($item['type']) !== 'hosting') {
            continue;
        }
        if (empty($item['relid'])) {
            continue;
        }

        $foundHostingItem = true;

        $service = localAPI('GetClientsProducts', array('serviceid' => $item['relid']));
        $status  = isset($service['products']['product'][0]['status'])
            ? $service['products']['product'][0]['status']
            : null;

        // Qualquer serviço ainda Pending nesta fatura já basta para tratar
        // como pedido novo, mesmo numa fatura com múltiplos itens.
        if ($status === 'Pending') {
            return true;
        }
    }

    // Nenhum item de Hosting identificável (ex: só domínio avulso, ou
    // add-on/taxa sem relid) - não dá pra confirmar que é renovação de
    // serviço já ativo, então trata como pedido novo (mesma cautela do
    // caso de fatura sem itens, acima).
    if (!$foundHostingItem) {
        return true;
    }

    return false;
}

/**
 * Obtém o CVV do cartão.
 *
 * O WHMCS não inclui o CVV nos parâmetros passados para storeremote nem para
 * capture de cartão salvo (por conformidade PCI-DSS, o CVV não pode ser
 * armazenado), mas a Pagar.me aceita/exige o CVV nesses dois casos. Por isso
 * buscamos também no POST/REQUEST da requisição atual, onde o valor existe em
 * memória enquanto o cliente submete o formulário (storeremote) ou enquanto
 * os apps chamam CapturePayment com `cvv` no corpo (capture de cartão salvo -
 * ver pagarme_isNewOrderInvoice()).
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
 * $tokenizedAt (timestamp Unix, opcional): quando o cartão foi tokenizado
 * com CVV confirmado por pagarme_storeremote(). Usado por
 * pagarme_capture() para dispensar a exigência de CVV numa janela curta
 * logo após o cadastro (ver pagarme_recentlyTokenizedWithCvv()) - sem isso,
 * o fluxo "cadastrar cartão novo + pagar na hora" pede CVV duas vezes: uma
 * no cadastro (storeremote), outra na captura, mesmo sendo a mesma operação
 * do cliente. Ausente/vazio para chamadas que não vêm de um cadastro
 * (ex: pagarme_removeremote() nunca precisa disso).
 *
 * @param string $customerId
 * @param string $cardId
 * @param string $brand
 * @param int|null $tokenizedAt
 * @return string
 */
function pagarme_buildToken($customerId, $cardId, $brand = '', $tokenizedAt = null)
{
    // Formato: customer_id|card_id|brand|tokenizedAt (brand e tokenizedAt
    // opcionais - tokens antigos sem eles continuam válidos, ver parseToken).
    $timestamp = $tokenizedAt !== null ? (string) $tokenizedAt : '';
    return $customerId . '|' . $cardId . '|' . $brand . '|' . $timestamp;
}

/**
 * Decodifica o token salvo pelo WHMCS de volta em customer_id + card_id +
 * brand + tokenized_at. Tolera tokens antigos nos formatos
 * customer_id|card_id (sem bandeira) e customer_id|card_id|brand (sem
 * timestamp, gerados antes desta mudança).
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
    $tokenizedAt = isset($parts[3]) && $parts[3] !== '' ? (int) $parts[3] : null;

    if (empty($customerId) || empty($cardId)) {
        return null;
    }

    return array(
        'customer_id'  => $customerId,
        'card_id'      => $cardId,
        'brand'        => $brand,
        'tokenized_at' => $tokenizedAt,
    );
}

/**
 * Se o cartão foi tokenizado com CVV confirmado nos últimos N minutos, a
 * captura seguinte não precisa exigir CVV de novo - é a mesma operação do
 * cliente (cadastrar cartão novo + pagar na hora), só em duas chamadas
 * separadas. Passada essa janela, volta a exigir CVV normalmente (cartão
 * salvo de sessão anterior).
 *
 * Janela curta o bastante para não virar uma forma de reusar CVV velho
 * indefinidamente, longa o bastante para cobrir o tempo real entre
 * storeremote e capture no fluxo dos apps (parcelamento, troca de gateway,
 * confirmação do usuário).
 *
 * @param array|null $token Retorno de pagarme_parseToken()
 * @param int $windowSeconds
 * @return bool
 */
function pagarme_recentlyTokenizedWithCvv($token, $windowSeconds = 600)
{
    if (empty($token) || empty($token['tokenized_at'])) {
        return false;
    }

    return (time() - (int) $token['tokenized_at']) <= $windowSeconds;
}

<?php
/**
 * Lógica de parcelamento com juros da Pagar.me (Caminho B).
 *
 * Espelha o modelo do módulo Cielo:
 *   - Máximo de parcelas por ciclo de cobrança
 *   - Faixa sem juros conforme o ciclo
 *   - Juros (taxa da adquirente + margem da loja) repassado ao comprador
 *     acima da faixa sem juros
 *   - Reconciliação: o juros é adicionado como item na fatura, para que o
 *     total cobrado seja igual ao total da fatura (contabilidade do WHMCS)
 *
 * Regras de faixa sem juros (definidas com a Stay):
 *   - Anual ou superior : 1x a 5x sem juros; 6x a 12x com juros
 *   - Trimestral/Semestral : apenas 1x sem juros; 2x+ com juros
 *   - Mensal : somente 1x
 *
 * IMPORTANTE: as tabelas em inc/pagarme_credit_card_taxes.json são um CLONE
 * das taxas da Cielo, usadas como placeholder. Substituir pelas taxas reais
 * da conta Pagar.me quando disponíveis.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Máximo de parcelas permitido para um número de meses do ciclo.
 *
 * @param int $months
 * @return int
 */
function pagarme_maxInstallmentsForMonths($months)
{
    if ($months <= 1) {
        return 1;
    }
    if ($months <= 3) {
        return 3;
    }
    if ($months <= 6) {
        return 6;
    }
    return 12; // anual ou superior
}

/**
 * Converte um billingcycle do WHMCS em número de meses.
 *
 * @param string $cycle
 * @return int 0 se desconhecido
 */
function pagarme_cycleToMonths($cycle)
{
    $map = array(
        'monthly'       => 1,
        'quarterly'     => 3,
        'semiannually'  => 6,
        'semi-annually' => 6,
        'annually'      => 12,
        'biennially'    => 24,
        'triennially'   => 36,
    );
    $key = strtolower(trim($cycle));
    return isset($map[$key]) ? $map[$key] : 0;
}

/**
 * Determina o máximo de parcelas para uma fatura, consultando o ciclo de
 * cobrança de cada serviço vinculado. O MENOR período manda (item mais
 * restritivo determina o teto), espelhando a regra do módulo Cielo.
 *
 * @param int $invoiceId
 * @return int Máximo de parcelas (1 se não detectar)
 */
function pagarme_maxInstallmentsForInvoice($invoiceId)
{
    if (!function_exists('localAPI') || !$invoiceId) {
        return 1;
    }

    $invoice = localAPI('GetInvoice', array('invoiceid' => $invoiceId));
    if (empty($invoice['items']['item'])) {
        return 1;
    }

    $items = $invoice['items']['item'];
    if (isset($items['id'])) {
        $items = array($items);
    }

    $minMonths   = null;
    $foundCycle  = false;

    foreach ($items as $item) {
        $relid = isset($item['relid']) ? (int) $item['relid'] : 0;
        $type  = isset($item['type']) ? $item['type'] : '';

        if ($relid <= 0) {
            continue;
        }

        $table = ($type === 'Addon') ? 'tblhostingaddons' : 'tblhosting';

        try {
            $service = \WHMCS\Database\Capsule::table($table)->where('id', $relid)->first();
        } catch (\Exception $e) {
            continue;
        }

        if (!$service || empty($service->billingcycle)) {
            continue;
        }

        $months = pagarme_cycleToMonths($service->billingcycle);
        if ($months > 0) {
            $foundCycle = true;
            if ($minMonths === null || $months < $minMonths) {
                $minMonths = $months;
            }
        }
    }

    if (!$foundCycle) {
        return 1;
    }

    return pagarme_maxInstallmentsForMonths($minMonths);
}

/**
 * Número de parcelas sem juros para um determinado teto de parcelas.
 *
 * Regra: anual+ (teto 12) => 5 parcelas sem juros; demais => apenas 1x.
 *
 * @param int $maxInstallments
 * @return int
 */
function pagarme_freeInstallments($maxInstallments)
{
    return ($maxInstallments >= 12) ? 5 : 1;
}

/**
 * Carrega uma tabela JSON do diretório inc/ do módulo.
 *
 * @param string $filename
 * @return array
 */
function pagarme_loadTable($filename)
{
    $path = __DIR__ . '/inc/' . $filename;
    if (!is_readable($path)) {
        return array();
    }
    $decoded = json_decode(file_get_contents($path), true);
    return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : array();
}

/**
 * Normaliza o nome da bandeira para as chaves das tabelas de taxa.
 *
 * @param string $brand
 * @return string
 */
function pagarme_normalizeBrand($brand)
{
    $b = strtolower(trim($brand));
    $map = array(
        'visa'        => 'visa',
        'master'      => 'mastercard',
        'mastercard'  => 'mastercard',
        'amex'        => 'amex',
        'american'    => 'amex',
        'elo'         => 'elo',
        'hipercard'   => 'hipercard',
        'hiper'       => 'hipercard',
        'diners'      => 'diners',
        'banescard'   => 'banescard',
    );
    foreach ($map as $needle => $normalized) {
        if (strpos($b, $needle) !== false) {
            return $normalized;
        }
    }
    return 'outras';
}

/**
 * Detecta a bandeira a partir do número do cartão (BIN).
 *
 * @param string $number
 * @return string
 */
function pagarme_detectBrand($number)
{
    $n = preg_replace('/\D/', '', (string) $number);
    if ($n === '') {
        return 'outras';
    }
    if (preg_match('/^4/', $n)) {
        return 'visa';
    }
    if (preg_match('/^(5[1-5]|2[2-7])/', $n)) {
        return 'mastercard';
    }
    if (preg_match('/^3[47]/', $n)) {
        return 'amex';
    }
    if (preg_match('/^(606282|3841)/', $n)) {
        return 'hipercard';
    }
    if (preg_match('/^(636368|438935|504175|451416|636297|5067|4576|4011|506699|6363)/', $n)) {
        return 'elo';
    }
    return 'outras';
}

/**
 * Calcula a taxa (%) repassada ao comprador para um dado número de parcelas.
 *
 * customer = 0 dentro da faixa sem juros; acima dela, taxa da adquirente +
 * margem da loja.
 *
 * @param string $brand           Bandeira normalizada
 * @param int    $installments    Número de parcelas escolhido
 * @param int    $maxInstallments Teto de parcelas do ciclo
 * @return float Percentual a acrescentar (ex: 3.09 = 3,09%)
 */
function pagarme_customerRate($brand, $installments, $maxInstallments)
{
    $free = pagarme_freeInstallments($maxInstallments);
    if ($installments <= $free) {
        return 0.0;
    }

    $fees    = pagarme_loadTable('pagarme_credit_card_taxes.json');
    $margins = pagarme_loadTable('stay_margins.json');

    $brand = pagarme_normalizeBrand($brand);

    $feeTable = array();
    if (isset($fees[$brand]['credito'])) {
        $feeTable = $fees[$brand]['credito'];
    } elseif (isset($fees['outras']['credito'])) {
        $feeTable = $fees['outras']['credito'];
    }

    $marginTable = isset($margins['credito']) ? $margins['credito'] : array();

    $n   = (string) $installments;
    $fee = isset($feeTable[$n]) ? (float) $feeTable[$n] : 0.0;
    $mar = isset($marginTable[$n]) ? (float) $marginTable[$n] : 0.0;

    return $fee + $mar;
}

/**
 * Aplica à fatura SOMENTE a parcela do juros de parcelamento, com um transid
 * distinto (sufixo _fee) e idempotência via checkCbTransID.
 *
 * Isso é necessário porque, no fluxo de merchant gateway, o WHMCS aplica
 * automaticamente o valor original ($params['amount']) ao receber 'success'.
 * Ao pré-aplicar aqui apenas o juros, o saldo restante da fatura fica igual ao
 * valor original, e a aplicação automática do WHMCS fecha a fatura em zero -
 * sem duplicar pagamento.
 *
 * @param int    $invoiceId
 * @param string $chargeId
 * @param float  $interestAmount Valor do juros em reais
 * @return void
 */
function pagarme_applyInterestPortion($invoiceId, $chargeId, $interestAmount)
{
    if ($interestAmount <= 0) {
        return;
    }

    // Garante que as funções do WHMCS estejam disponíveis
    if (!function_exists('addInvoicePayment') || !function_exists('checkCbTransID')) {
        $base = dirname(dirname(__DIR__)); // .../modules -> raiz do WHMCS
        $gwFile  = $base . '/gatewayfunctions.php';
        $invFile = $base . '/invoicefunctions.php';
        // Em instalações padrão ficam em /includes
        if (!is_readable($gwFile)) {
            $gwFile  = dirname($base) . '/includes/gatewayfunctions.php';
            $invFile = dirname($base) . '/includes/invoicefunctions.php';
        }
        if (is_readable($gwFile)) {
            require_once $gwFile;
        }
        if (is_readable($invFile)) {
            require_once $invFile;
        }
    }

    if (!function_exists('addInvoicePayment')) {
        return; // não foi possível carregar; evita erro fatal
    }

    $feeTransId = $chargeId . '_fee';

    // Idempotência: se este transid de juros já foi lançado, não repete
    if (function_exists('checkCbTransID')) {
        // checkCbTransID encerra o script se já existir; por isso checamos o
        // registro diretamente antes, de forma segura.
        try {
            $exists = \WHMCS\Database\Capsule::table('tblaccounts')
                ->where('transid', $feeTransId)
                ->exists();
            if ($exists) {
                return;
            }
        } catch (\Exception $e) {
            // Se a checagem falhar, seguimos com cautela
        }
    }

    try {
        addInvoicePayment($invoiceId, $feeTransId, $interestAmount, 0, 'pagarme');
    } catch (\Exception $e) {
        // Não interrompe o fluxo de pagamento
    }
}

/**
 * Adiciona (ou reaproveita) o item de "Taxa de parcelamento" na fatura, de
 * forma idempotente, e retorna o novo total da fatura em reais.
 *
 * Idempotência: se já existir um item de taxa de parcelamento nesta fatura
 * (identificado por marcador na descrição), ele é atualizado em vez de criar
 * um novo - evita duplicar o valor em novas tentativas de pagamento.
 *
 * @param int   $invoiceId
 * @param float $feeAmount   Valor do juros em reais
 * @param int   $installments
 * @return float|null Novo total da fatura, ou null em caso de erro
 */
function pagarme_applyInstallmentFee($invoiceId, $feeAmount, $installments)
{
    if (!class_exists('\WHMCS\Database\Capsule') || $feeAmount <= 0) {
        return null;
    }

    $marker      = '[PARCELAMENTO]';
    $description = $marker . ' Taxa de parcelamento (' . $installments . 'x)';

    try {
        $db = \WHMCS\Database\Capsule::connection();

        // Procura item de taxa já existente nesta fatura
        $existing = \WHMCS\Database\Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->where('description', 'like', $marker . '%')
            ->first();

        if ($existing) {
            // Ajusta o valor do item existente
            \WHMCS\Database\Capsule::table('tblinvoiceitems')
                ->where('id', $existing->id)
                ->update(array(
                    'description' => $description,
                    'amount'      => number_format($feeAmount, 2, '.', ''),
                ));
        } else {
            \WHMCS\Database\Capsule::table('tblinvoiceitems')->insert(array(
                'invoiceid'   => $invoiceId,
                'userid'      => 0,
                'type'        => '',
                'relid'       => 0,
                'description' => $description,
                'amount'      => number_format($feeAmount, 2, '.', ''),
                'taxed'       => 0,
                'duedate'     => date('Y-m-d'),
                'paymentmethod' => '',
                'notes'       => '',
            ));
        }

        // Recalcula o total da fatura a partir dos itens
        $subtotal = \WHMCS\Database\Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->sum('amount');

        $invoice = \WHMCS\Database\Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        $credit  = $invoice ? (float) $invoice->credit : 0.0;
        $tax     = $invoice ? (float) $invoice->tax : 0.0;
        $tax2    = $invoice ? (float) $invoice->tax2 : 0.0;

        $newTotal = (float) $subtotal + $tax + $tax2 - $credit;

        \WHMCS\Database\Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->update(array('total' => number_format($newTotal, 2, '.', '')));

        return $newTotal;
    } catch (\Exception $e) {
        return null;
    }
}

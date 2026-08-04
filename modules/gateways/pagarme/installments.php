<?php
/**
 * Lógica de parcelamento com juros da Pagar.me (Caminho B).
 *
 * Espelha o modelo do módulo Cielo:
 *   - Máximo de parcelas por ciclo de cobrança
 *   - Faixa sem juros conforme o ciclo
 *   - Juros (taxa MDR da Pagar.me) repassado ao comprador acima da faixa
 *     sem juros
 *   - Reconciliação: o juros é adicionado como item na fatura, para que o
 *     total cobrado seja igual ao total da fatura (contabilidade do WHMCS)
 *
 * Regras de faixa sem juros por ciclo (definidas com a Stay):
 *   - Mensal      : somente 1x (à vista)
 *   - Trimestral  : até 3x, todas com juros (só 1x é sem juros)
 *   - Semestral   : 1x a 3x sem juros; 4x a 6x com juros
 *   - Anual       : 1x a 5x sem juros; 6x a 12x com juros
 *   - Bienal      : 1x a 5x sem juros; 6x a 12x com juros
 *   - Trienal     : 1x a 6x sem juros; 7x a 12x com juros
 *
 * Taxas: inc/pagarme_credit_card_taxes.json contém as MDR reais da proposta
 * comercial Stone/Pagar.me, conferidas em 03/08/2026 (Mastercard 1,87/2,29/3,82;
 * Visa 1,97/2,29/3,82; Elo e Amex 2,40/2,65/3,82, nas faixas 1x, 2-6x e 7-12x).
 * O rótulo de "placeholder da Cielo" que constava aqui era resíduo do início do
 * projeto e estava errado.
 *
 * A margem da loja NÃO entra no cálculo — ver pagarme_customerRate().
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
 * Determina o ciclo (em meses) que rege o parcelamento de uma fatura,
 * consultando o ciclo de cobrança de cada serviço vinculado. O MENOR período
 * manda (item mais restritivo determina o teto), espelhando a regra do
 * módulo Cielo.
 *
 * @param int $invoiceId
 * @return int Meses do ciclo mais restritivo (0 se não detectar)
 */
function pagarme_minMonthsForInvoice($invoiceId)
{
    if (!function_exists('localAPI') || !$invoiceId) {
        return 0;
    }

    $invoice = localAPI('GetInvoice', array('invoiceid' => $invoiceId));
    if (empty($invoice['items']['item'])) {
        return 0;
    }

    $items = $invoice['items']['item'];
    if (isset($items['id'])) {
        $items = array($items);
    }

    $minMonths = null;

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
            if ($minMonths === null || $months < $minMonths) {
                $minMonths = $months;
            }
        }
    }

    return $minMonths === null ? 0 : $minMonths;
}

/**
 * Determina o máximo de parcelas para uma fatura.
 *
 * @param int $invoiceId
 * @return int Máximo de parcelas (1 se não detectar)
 */
function pagarme_maxInstallmentsForInvoice($invoiceId)
{
    return pagarme_maxInstallmentsForMonths(pagarme_minMonthsForInvoice($invoiceId));
}

/**
 * Número de parcelas sem juros conforme o ciclo (em meses).
 *
 * Regra (definida com a Stay):
 *   - Mensal (1)          : 1x (só à vista)
 *   - Trimestral (3)      : 1x (2x-3x já têm juros)
 *   - Semestral (6)       : 3x
 *   - Anual (12)          : 5x
 *   - Bienal (24)         : 5x
 *   - Trienal (36 ou mais): 6x
 *
 * @param int $months
 * @return int
 */
function pagarme_freeInstallmentsForMonths($months)
{
    if ($months <= 1) {
        return 1;
    }
    if ($months <= 3) {
        return 1;
    }
    if ($months <= 6) {
        return 3;
    }
    if ($months <= 24) {
        return 5;
    }
    return 6; // trienal ou superior
}

/**
 * Número de parcelas sem juros para uma fatura, conforme o ciclo mais
 * restritivo entre os serviços vinculados.
 *
 * @param int $invoiceId
 * @return int
 */
function pagarme_freeInstallmentsForInvoice($invoiceId)
{
    return pagarme_freeInstallmentsForMonths(pagarme_minMonthsForInvoice($invoiceId));
}

/**
 * Carrega uma tabela JSON do diretório inc/ do módulo.
 *
 * O resultado fica em cache estático: pagarme_buildInstallmentOptions() chama
 * pagarme_customerRate() até 12 vezes em sequência, e sem cache isso seriam 24
 * leituras de disco para montar uma única lista de parcelas.
 *
 * @param string $filename
 * @return array
 */
function pagarme_loadTable($filename)
{
    static $cache = array();

    if (array_key_exists($filename, $cache)) {
        return $cache[$filename];
    }

    $path = __DIR__ . '/inc/' . $filename;
    if (!is_readable($path)) {
        return $cache[$filename] = array();
    }
    $decoded = json_decode(file_get_contents($path), true);
    return $cache[$filename] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
        ? $decoded
        : array();
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
 * Zero dentro da faixa sem juros; acima dela, a taxa MDR da Pagar.me para
 * aquela bandeira e número de parcelas.
 *
 * NÃO soma margem da loja. A margem de `stay_margins.json` existia para cobrir
 * o custo de antecipar recebíveis, e a Stay não antecipa: recebe parcela a
 * parcela, então o custo real do parcelamento é o próprio MDR. O arquivo segue
 * no repositório apenas como histórico e não é mais lido.
 *
 * @param string $brand            Bandeira normalizada
 * @param int    $installments     Número de parcelas escolhido
 * @param int    $freeInstallments Parcelas sem juros do ciclo (ver
 *                                 pagarme_freeInstallmentsForMonths)
 * @return float Percentual a acrescentar (ex: 3.82 = 3,82%)
 */
function pagarme_customerRate($brand, $installments, $freeInstallments)
{
    if ($installments <= $freeInstallments) {
        return 0.0;
    }

    $fees  = pagarme_loadTable('pagarme_credit_card_taxes.json');
    $brand = pagarme_normalizeBrand($brand);

    $feeTable = array();
    if (isset($fees[$brand]['credito'])) {
        $feeTable = $fees[$brand]['credito'];
    } elseif (isset($fees['outras']['credito'])) {
        // Bandeiras fora da proposta comercial (Hipercard, Diners...) caem na
        // faixa mais cara entre as acordadas.
        $feeTable = $fees['outras']['credito'];
    }

    $n = (string) $installments;

    return isset($feeTable[$n]) ? (float) $feeTable[$n] : 0.0;
}

/**
 * Marcador usado na descrição do item de juros na fatura. Único ponto onde
 * essa string é definida - tudo que procura/insere/remove o item usa daqui.
 */
if (!defined('PAGARME_FEE_MARKER')) {
    define('PAGARME_FEE_MARKER', '[PARCELAMENTO]');
}

/**
 * Valor do item de taxa de parcelamento já existente na fatura (0 se não há).
 *
 * @param int $invoiceId
 * @return float
 */
function pagarme_existingFeeItemAmount($invoiceId)
{
    if (!class_exists('\WHMCS\Database\Capsule') || !$invoiceId) {
        return 0.0;
    }

    try {
        $sum = \WHMCS\Database\Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->where('description', 'like', PAGARME_FEE_MARKER . '%')
            ->sum('amount');
        return round((float) $sum, 2);
    } catch (\Exception $e) {
        return 0.0;
    }
}

/**
 * Base de cálculo do parcelamento: o que o cliente realmente deve, SEM o juros
 * de uma tentativa anterior.
 *
 * Esta é a única definição de "base" do módulo. Antes, o juros era calculado
 * sobre $params['amount'], que já vinha inflado pelo item de taxa aplicado em
 * uma tentativa anterior - o juros incidia sobre juros a cada retentativa.
 *
 * @param int $invoiceId
 * @return float
 */
function pagarme_invoiceBaseAmount($invoiceId)
{
    if (!class_exists('\WHMCS\Database\Capsule') || !$invoiceId) {
        return 0.0;
    }

    try {
        $invoice = \WHMCS\Database\Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->first();
        if (!$invoice) {
            return 0.0;
        }

        // Pagamentos já aplicados (inclui a parcela de juros de tentativas
        // anteriores, lançada com transid <charge>_fee).
        $paid = \WHMCS\Database\Capsule::table('tblaccounts')
            ->where('invoiceid', $invoiceId)
            ->sum(\WHMCS\Database\Capsule::raw('amountin - amountout'));

        $base = (float) $invoice->total
            - (float) $paid
            - pagarme_existingFeeItemAmount($invoiceId);

        return ($base > 0) ? round($base, 2) : 0.0;
    } catch (\Exception $e) {
        return 0.0;
    }
}

/**
 * Valor do juros, em reais, para um número de parcelas sobre uma base.
 *
 * Único ponto de arredondamento do juros no módulo.
 *
 * @param float  $baseAmount
 * @param string $brand
 * @param int    $installments
 * @param int    $freeInstallments
 * @return float
 */
function pagarme_feeForInstallments($baseAmount, $brand, $installments, $freeInstallments)
{
    $rate = pagarme_customerRate($brand, $installments, $freeInstallments);
    if ($rate <= 0) {
        return 0.0;
    }
    return round(((float) $baseAmount) * ($rate / 100), 2);
}

/**
 * Monta a lista de opções de parcelamento para um ciclo e uma base.
 *
 * PURA: não toca banco nem localAPI. É o núcleo testável do parcelamento e o
 * que a API pública expõe.
 *
 * 'total' é autoritativo (é o que será cobrado). 'installment_amount' é apenas
 * exibição - quem divide de fato a cobrança é a Pagar.me, que fica com eventual
 * diferença de centavo na última parcela.
 *
 * @param int    $months     Meses do ciclo (ver pagarme_cycleToMonths)
 * @param float  $baseAmount Base de cálculo em reais
 * @param string $brand      Bandeira (será normalizada)
 * @return array
 */
function pagarme_buildInstallmentOptions($months, $baseAmount, $brand)
{
    $max   = pagarme_maxInstallmentsForMonths($months);
    $free  = pagarme_freeInstallmentsForMonths($months);
    $brand = pagarme_normalizeBrand($brand);
    $base  = round((float) $baseAmount, 2);

    $options = array();
    for ($n = 1; $n <= $max; $n++) {
        $rate  = pagarme_customerRate($brand, $n, $free);
        $fee   = pagarme_feeForInstallments($base, $brand, $n, $free);
        $total = round($base + $fee, 2);

        $options[] = array(
            'installments'       => $n,
            'interest_free'      => ($rate <= 0),
            'rate'               => round((float) $rate, 4),
            'fee_amount'         => $fee,
            'installment_amount' => round($total / $n, 2),
            'total'              => $total,
        );
    }

    return array(
        'cycle_months'      => (int) $months,
        'base_amount'       => $base,
        'brand'             => $brand,
        'max_installments'  => $max,
        'free_installments' => $free,
        'options'           => $options,
    );
}

/**
 * Opções de parcelamento de uma fatura (modo autoritativo).
 *
 * @param int    $invoiceId
 * @param string $brand
 * @return array
 */
function pagarme_installmentOptionsForInvoice($invoiceId, $brand = 'outras')
{
    return pagarme_buildInstallmentOptions(
        pagarme_minMonthsForInvoice($invoiceId),
        pagarme_invoiceBaseAmount($invoiceId),
        $brand
    );
}

/**
 * Remove o item de taxa de parcelamento da fatura e recalcula o total.
 *
 * Necessário quando uma nova tentativa cai numa faixa SEM juros: o item da
 * tentativa anterior precisa sair, senão a fatura fica permanentemente inflada.
 *
 * @param int $invoiceId
 * @return bool true se algo foi removido
 */
function pagarme_clearInstallmentFee($invoiceId)
{
    if (!class_exists('\WHMCS\Database\Capsule') || !$invoiceId) {
        return false;
    }

    try {
        $removed = \WHMCS\Database\Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->where('description', 'like', PAGARME_FEE_MARKER . '%')
            ->delete();

        if (!$removed) {
            return false;
        }

        pagarme_recalculateInvoiceTotal($invoiceId);
        return true;
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * Recalcula tblinvoices.total a partir dos itens + impostos - crédito.
 *
 * @param int $invoiceId
 * @return float|null Novo total, ou null em caso de erro
 */
function pagarme_recalculateInvoiceTotal($invoiceId)
{
    try {
        $subtotal = \WHMCS\Database\Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->sum('amount');

        $invoice = \WHMCS\Database\Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if (!$invoice) {
            return null;
        }

        $newTotal = (float) $subtotal
            + (float) $invoice->tax
            + (float) $invoice->tax2
            - (float) $invoice->credit;

        \WHMCS\Database\Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->update(array('total' => number_format($newTotal, 2, '.', '')));

        return $newTotal;
    } catch (\Exception $e) {
        return null;
    }
}

// =========================================================================
// Persistência da parcela escolhida
//
// Nos checkouts headless não existe formulário do WHMCS: a escolha do cliente
// chega por uma chamada de API (SetPagarmeInstallments) e precisa sobreviver
// até a captura, que é OUTRA requisição. Guardar em $_REQUEST/cookie não serve
// e falha em silêncio - o cliente escolheria 8x e seria cobrado 1x.
// =========================================================================

/**
 * Garante a existência da tabela de seleções. Módulos de gateway do WHMCS não
 * têm hook de ativação, então criamos sob demanda.
 *
 * @return bool
 */
function pagarme_ensureInstallmentsTable()
{
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    try {
        $schema = \WHMCS\Database\Capsule::schema();
        if (!$schema->hasTable('mod_pagarme_installments')) {
            $schema->create('mod_pagarme_installments', function ($table) {
                $table->integer('invoiceid')->primary();
                $table->integer('installments');
                $table->decimal('base_amount', 12, 2);
                $table->dateTime('expires_at');
            });
        }
        return $checked = true;
    } catch (\Exception $e) {
        return $checked = false;
    }
}

/**
 * Grava a parcela escolhida para uma fatura.
 *
 * @param int   $invoiceId
 * @param int   $installments
 * @param float $baseAmount   Base usada no cálculo, para detectar fatura alterada
 * @param int   $ttlSeconds
 * @return bool
 */
function pagarme_storeSelectedInstallments($invoiceId, $installments, $baseAmount, $ttlSeconds = 1800)
{
    if (!pagarme_ensureInstallmentsTable()) {
        return false;
    }

    $row = array(
        'installments' => (int) $installments,
        'base_amount'  => number_format((float) $baseAmount, 2, '.', ''),
        'expires_at'   => date('Y-m-d H:i:s', time() + (int) $ttlSeconds),
    );

    try {
        $table = \WHMCS\Database\Capsule::table('mod_pagarme_installments');
        if ($table->where('invoiceid', $invoiceId)->exists()) {
            $table->where('invoiceid', $invoiceId)->update($row);
        } else {
            $row['invoiceid'] = (int) $invoiceId;
            $table->insert($row);
        }
        return true;
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * Lê a parcela escolhida para uma fatura, validando prazo e base.
 *
 * Distinguir "não há escolha" de "há uma escolha que não posso honrar" é
 * essencial: no primeiro caso 1x é a resposta correta; no segundo, cobrar 1x
 * significaria cobrar o cliente num plano diferente do que ele viu e aceitou.
 * Por isso o retorno traz sempre um 'status'.
 *
 * @param int $invoiceId
 * @return array status: none|valid|expired|base_changed|unavailable
 */
function pagarme_readStoredInstallments($invoiceId)
{
    $empty = array('installments' => null, 'base_amount' => null);

    if (!pagarme_ensureInstallmentsTable()) {
        return array_merge($empty, array('status' => 'unavailable'));
    }

    try {
        $row = \WHMCS\Database\Capsule::table('mod_pagarme_installments')
            ->where('invoiceid', $invoiceId)
            ->first();
    } catch (\Exception $e) {
        return array_merge($empty, array('status' => 'unavailable'));
    }

    if (!$row) {
        return array_merge($empty, array('status' => 'none'));
    }

    $found = array(
        'installments' => (int) $row->installments,
        'base_amount'  => (float) $row->base_amount,
    );

    if (strtotime($row->expires_at) < time()) {
        return array_merge($found, array('status' => 'expired'));
    }

    // A fatura mudou de valor depois da escolha: o parcelamento precisa ser
    // refeito, senão exibido e cobrado divergem.
    $currentBase = pagarme_invoiceBaseAmount($invoiceId);
    if (abs($currentBase - $found['base_amount']) > 0.01) {
        return array_merge($found, array('status' => 'base_changed'));
    }

    return array_merge($found, array('status' => 'valid'));
}

/**
 * Remove a seleção de uma fatura (após capturar com sucesso).
 *
 * @param int $invoiceId
 * @return void
 */
function pagarme_clearStoredInstallments($invoiceId)
{
    if (!pagarme_ensureInstallmentsTable()) {
        return;
    }
    try {
        \WHMCS\Database\Capsule::table('mod_pagarme_installments')
            ->where('invoiceid', $invoiceId)
            ->delete();
    } catch (\Exception $e) {
        // silencioso: não impede o pagamento já concluído
    }
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

    $marker      = PAGARME_FEE_MARKER;
    $description = $marker . ' Taxa de parcelamento (' . $installments . 'x)';

    try {
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

        return pagarme_recalculateInvoiceTotal($invoiceId);
    } catch (\Exception $e) {
        return null;
    }
}

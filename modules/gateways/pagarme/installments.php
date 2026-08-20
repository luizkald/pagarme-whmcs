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

        // Só itens de Hosting/Addon têm relid apontando para tblhosting/
        // tblhostingaddons. Um item de Domínio (type "Domain"/"DomainRegister"/
        // "DomainTransfer") tem relid de tbldomains, outra tabela, outro
        // espaço de ids. Sem esta lista, o fallback "senão é tblhosting"
        // reaproveitava esse relid como id de tblhosting: numa conta nova,
        // onde os dois ids começam próximos de 1, isso colidia com uma linha
        // de hospedagem não relacionada e, se o ciclo dela fosse menor,
        // vencia o MIN() abaixo. Foi assim que uma fatura anual com domínio
        // junto caiu para "1 mês" e o parcelamento sumiu da tela.
        $type = strtolower($type);
        if ($type === 'addon') {
            $table = 'tblhostingaddons';
        } elseif ($type === 'hosting') {
            $table = 'tblhosting';
        } else {
            continue;
        }

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
 * Taxa MDR real da Pagar.me (%) para uma bandeira e número de parcelas,
 * conforme a tabela da proposta comercial (à vista / 2-6x / 7-12x).
 *
 * Diferente de pagarme_customerRate(): esta NÃO zera dentro da faixa sem
 * juros. É o custo real que a Pagar.me cobra da loja em QUALQUER cobrança de
 * cartão, usado tanto para calcular o juros repassado ao cliente (acima da
 * faixa sem juros) quanto para registrar a taxa de transação real (sempre,
 * ver pagarme_transactionFeeAmount()).
 *
 * @param string $brand        Bandeira (será normalizada)
 * @param int    $installments Número de parcelas
 * @return float Percentual (ex: 3.82 = 3,82%)
 */
function pagarme_mdrRate($brand, $installments)
{
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
 * Se uma promoção "sem juros" está ativa para a bandeira, na data de
 * referência (hoje, por padrão).
 *
 * Configurada em inc/pagarme_promotions.json pelo addon de admin
 * (modules/addons/pagarme_fee_rates/) — este módulo só lê. `active` é um
 * interruptor manual independente das datas: permite desligar antes do fim
 * sem apagar o período configurado. `start`/`end` ausentes (null) tornam a
 * promoção válida indefinidamente enquanto `active` for true.
 *
 * Comparação por string 'Y-m-d' (não strtotime/DateTime): nesse formato a
 * ordem lexicográfica já é a ordem cronológica, então evita qualquer
 * ambiguidade de timezone entre o servidor e quem configurou a data.
 *
 * @param string      $brand          Bandeira (será normalizada)
 * @param string|null $referenceDate  'Y-m-d'; null usa a data atual do servidor
 * @return bool
 */
function pagarme_isPromotionActive($brand, $referenceDate = null)
{
    $promotions = pagarme_loadTable('pagarme_promotions.json');
    $brand = pagarme_normalizeBrand($brand);

    if (empty($promotions[$brand]['active'])) {
        return false;
    }

    $today = $referenceDate ?: date('Y-m-d');
    $start = isset($promotions[$brand]['start']) ? $promotions[$brand]['start'] : null;
    $end   = isset($promotions[$brand]['end']) ? $promotions[$brand]['end'] : null;

    if (!empty($start) && $today < $start) {
        return false;
    }
    if (!empty($end) && $today > $end) {
        return false;
    }

    return true;
}

/**
 * Calcula a taxa (%) repassada ao comprador para um dado número de parcelas.
 *
 * Zero durante uma promoção ativa para a bandeira (pagarme_isPromotionActive()),
 * OU dentro da faixa sem juros do ciclo; acima dela, a taxa MDR da Pagar.me
 * para aquela bandeira e número de parcelas (pagarme_mdrRate()).
 *
 * Esta é a ÚNICA função que decide juros do comprador — chamada tanto por
 * pagarme_buildInstallmentOptions() (preview e exibição de fatura) quanto
 * diretamente por pagarme_capture() (cobrança real, modules/gateways/pagarme.php).
 * A checagem de promoção precisa morar aqui, e não em
 * pagarme_buildInstallmentOptions(), para cobrir os três caminhos com uma
 * única mudança.
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
    if (pagarme_isPromotionActive($brand)) {
        return 0.0;
    }

    if ($installments <= $freeInstallments) {
        return 0.0;
    }

    return pagarme_mdrRate($brand, $installments);
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
 * Total de uma compra parcelada em N vezes a uma taxa mensal fixa, pela
 * Tabela Price (Sistema Francês de Amortização): PMT = PV × [i(1+i)^n] /
 * [(1+i)^n - 1], total = PMT × n.
 *
 * PURA: sem acesso a banco/localAPI. Trata a taxa recebida ($monthlyRatePercent)
 * como uma taxa de juros MENSAL COMPOSTA — não como o percentual único que o
 * modo simples usa. É o que o modo "Composta" da aba "Modo de Cálculo" pede:
 * usar a própria taxa MDR cadastrada, mas compondo mês a mês em vez de
 * aplicá-la uma única vez.
 *
 * @param float $baseAmount          PV (valor presente)
 * @param float $monthlyRatePercent  Taxa mensal composta, em % (ex: 3.82)
 * @param int   $installments        n (número de parcelas)
 * @return float Total (PV + juros compostos), nunca abaixo do principal
 */
function pagarme_compoundTotal($baseAmount, $monthlyRatePercent, $installments)
{
    $baseAmount = (float) $baseAmount;
    $installments = (int) $installments;

    if ($installments <= 0 || $baseAmount <= 0) {
        return $baseAmount;
    }
    if ($monthlyRatePercent <= 0) {
        // Sem taxa, não há o que compor - equivalente ao modo simples com rate 0.
        return $baseAmount;
    }

    $i = $monthlyRatePercent / 100;
    $factor = pow(1 + $i, $installments);
    if ($factor <= 1) {
        // Guarda defensiva: (1+i)^n - 1 só é <= 0 se i <= 0, já descartado
        // acima, mas evita divisão por zero em qualquer cenário de ponto
        // flutuante inesperado.
        return $baseAmount;
    }

    $pmt = $baseAmount * ($i * $factor) / ($factor - 1);
    $total = round($pmt * $installments, 2);

    return max($total, $baseAmount);
}

/**
 * Carrega a configuração de modo de cálculo (fórmula simples/composta,
 * fonte da taxa composta, margem fixa opcional), configurada pelo addon de
 * admin (modules/addons/pagarme_fee_rates/). Nunca fatal: defaults seguros
 * (simples, taxa composta = MDR, sem margem) se o arquivo não existir ou
 * estiver corrompido - idêntico ao comportamento anterior a esta
 * funcionalidade.
 *
 * @return array{formula: string, compound_rate_source: string, compound_custom_rate: float, margin_enabled: bool, margin: array<string,float>}
 */
function pagarme_loadInstallmentMode()
{
    $config = pagarme_loadTable('pagarme_installment_mode.json');

    $formula = (isset($config['formula']) && $config['formula'] === 'compound') ? 'compound' : 'simple';
    $compoundRateSource = (isset($config['compound_rate_source']) && $config['compound_rate_source'] === 'custom')
        ? 'custom'
        : 'mdr';
    $compoundCustomRate = isset($config['compound_custom_rate']) ? (float) $config['compound_custom_rate'] : 0.0;
    $marginEnabled = !empty($config['margin_enabled']);
    $margin = (isset($config['margin']) && is_array($config['margin'])) ? $config['margin'] : array();

    return array(
        'formula'              => $formula,
        'compound_rate_source' => $compoundRateSource,
        'compound_custom_rate' => $compoundCustomRate,
        'margin_enabled'       => $marginEnabled,
        'margin'               => $margin,
    );
}

/**
 * Total autoritativo de uma opção de parcelamento: base + juros (simples ou
 * compostos, conforme o modo) + margem fixa opcional (por cima do total já
 * calculado, nunca somada à taxa MDR antes de compor - decisão de negócio).
 *
 * Substitui, nos 3 pontos que decidiam isso separadamente
 * (pagarme_buildInstallmentOptions() 2x, pagarme_capture() 1x), o par
 * pagarme_customerRate()+pagarme_feeForInstallments() por uma única chamada
 * que já devolve total/rate/fee_amount prontos.
 *
 * No modo composto, a taxa usada para COMPOR pode vir da tabela MDR real
 * (padrão, $mode['compound_rate_source'] === 'mdr', variando por bandeira e
 * parcela como sempre) ou de uma taxa personalizada ÚNICA
 * ($mode['compound_rate_source'] === 'custom', $mode['compound_custom_rate']
 * - um só valor, o mesmo para qualquer bandeira e qualquer número de
 * parcelas, decisão de negócio confirmada em 14/08/2026). Em QUALQUER dos
 * dois casos, $rate (a taxa MDR real, via pagarme_customerRate()) continua
 * sendo o que decide SE a parcela tem juros (faixa sem juros do ciclo,
 * promoção ativa) - a taxa personalizada nunca decide isso, só QUANTO compor
 * depois que já foi decidido que há juros. Uma taxa personalizada
 * zerada/ausente não é erro: pagarme_compoundTotal() já trata taxa 0
 * devolvendo a base sem juros.
 *
 * $modeOverride permite à captura usar o modo PERSISTIDO no momento da
 * escolha do cliente (ver mod_pagarme_installments), em vez de reler a
 * configuração ao vivo - fecha a janela em que um admin muda o modo entre a
 * escolha do cliente e a cobrança real, o que faria o exibido divergir do
 * cobrado. Sem override (preview/exibição), lê a configuração ao vivo.
 *
 * @param float       $baseAmount
 * @param string      $brand
 * @param int         $installments
 * @param int         $freeInstallments
 * @param array|null  $modeOverride  {formula, compound_rate_source, compound_custom_rate, margin_enabled, margin} ou null
 * @return array{total: float, rate: float, fee_amount: float}
 */
function pagarme_installmentTotal($baseAmount, $brand, $installments, $freeInstallments, $modeOverride = null)
{
    $base = round((float) $baseAmount, 2);
    $rate = pagarme_customerRate($brand, $installments, $freeInstallments);

    if ($rate <= 0) {
        $total = $base;
    } else {
        $mode = is_array($modeOverride) ? $modeOverride : pagarme_loadInstallmentMode();

        if (isset($mode['formula']) && $mode['formula'] === 'compound') {
            $compoundRate = $rate;
            if (isset($mode['compound_rate_source']) && $mode['compound_rate_source'] === 'custom') {
                $compoundRate = isset($mode['compound_custom_rate']) ? (float) $mode['compound_custom_rate'] : 0.0;
            }
            $total = pagarme_compoundTotal($base, $compoundRate, $installments);
        } else {
            $total = round($base + pagarme_feeForInstallments($base, $brand, $installments, $freeInstallments), 2);
        }

        if (!empty($mode['margin_enabled']) && $installments > $freeInstallments) {
            $marginTable = isset($mode['margin']) && is_array($mode['margin']) ? $mode['margin'] : array();
            $marginPct = isset($marginTable[(string) $installments]) ? (float) $marginTable[(string) $installments] : 0.0;
            if ($marginPct > 0) {
                $total = round($total * (1 + $marginPct / 100), 2);
            }
        }
    }

    return array(
        'total'      => $total,
        'rate'       => $base > 0 ? round((($total - $base) / $base) * 100, 4) : 0.0,
        'fee_amount' => round($total - $base, 2),
    );
}

/**
 * Monta a lista de opções de parcelamento para um ciclo e uma base.
 *
 * PURA: não toca banco nem localAPI. É o núcleo testável do parcelamento e o
 * que a API pública expõe.
 *
 * 'total' é autoritativo (é o que será cobrado). 'installment_amount' é apenas
 * exibição - quem divide de fato a cobrança é a Pagar.me, que fica com eventual
 * diferença de centavo na última parcela. 'rate' é o percentual EFETIVO total
 * (fee_amount/base), não a taxa mensal nominal - em modo composto, a taxa
 * mensal nominal (ex: 3,82%) some muito mais que isso ao longo de 12 parcelas,
 * e mostrar o nominal ao cliente seria enganoso.
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
    $mode  = pagarme_loadInstallmentMode();

    $options = array();
    for ($n = 1; $n <= $max; $n++) {
        $result = pagarme_installmentTotal($base, $brand, $n, $free, $mode);
        $total  = $result['total'];

        $options[] = array(
            'installments'       => $n,
            'interest_free'      => ($result['fee_amount'] <= 0),
            'rate'               => $result['rate'],
            'fee_amount'         => $result['fee_amount'],
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
                $table->string('formula', 10)->default('simple');
                $table->boolean('margin_enabled')->default(0);
                $table->text('margin_snapshot')->nullable();
                $table->string('compound_rate_source', 10)->default('mdr');
                $table->text('compound_rate_snapshot')->nullable();
            });
            return $checked = true;
        }

        // Tabela já existe (instalação anterior a esta funcionalidade) -
        // adiciona as colunas novas via migração idempotente, uma a uma.
        // Seguro rodar em toda request: hasColumn() confere antes de alterar.
        $newColumns = array(
            'formula', 'margin_enabled', 'margin_snapshot',
            'compound_rate_source', 'compound_rate_snapshot',
        );
        $missing = array_filter($newColumns, function ($col) use ($schema) {
            return !$schema->hasColumn('mod_pagarme_installments', $col);
        });

        if (!empty($missing)) {
            $schema->table('mod_pagarme_installments', function ($table) use ($missing) {
                if (in_array('formula', $missing, true)) {
                    $table->string('formula', 10)->default('simple');
                }
                if (in_array('margin_enabled', $missing, true)) {
                    $table->boolean('margin_enabled')->default(0);
                }
                if (in_array('margin_snapshot', $missing, true)) {
                    $table->text('margin_snapshot')->nullable();
                }
                if (in_array('compound_rate_source', $missing, true)) {
                    $table->string('compound_rate_source', 10)->default('mdr');
                }
                if (in_array('compound_rate_snapshot', $missing, true)) {
                    $table->text('compound_rate_snapshot')->nullable();
                }
            });
        }

        return $checked = true;
    } catch (\Exception $e) {
        return $checked = false;
    }
}

// =========================================================================
// Motivo do último erro (para os apps mostrarem algo além de "recusado")
// =========================================================================
//
// Mora aqui (não em pagarme.php) para as custom API actions em
// includes/api/ poderem usar sem carregar o módulo de gateway inteiro - o
// mesmo motivo pelo qual mod_pagarme_installments também vive aqui. A
// classificação em si (pagarme_classifyDeclineReason/pagarme_classifyAndStore)
// continua em pagarme.php, que já dá require_once neste arquivo.

/**
 * Garante que a tabela mod_pagarme_last_error existe. Guarda só (code,
 * message) já classificados por pagarme_classifyDeclineReason() - NUNCA o
 * JSON cru da Pagar.me nem dado de cartão/pessoal. Chave por clientid (não
 * por invoice): cobre tanto falha de captura quanto falha ao salvar cartão
 * (que não tem invoice nenhuma). TTL curto porque só serve para o app ler de
 * volta na mesma tentativa que acabou de falhar - ver GetPagarmeLastError em
 * includes/api/.
 *
 * @return bool
 */
function pagarme_ensureLastErrorTable()
{
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    try {
        $schema = \WHMCS\Database\Capsule::schema();
        if (!$schema->hasTable('mod_pagarme_last_error')) {
            $schema->create('mod_pagarme_last_error', function ($table) {
                $table->integer('clientid')->primary();
                $table->string('code', 40);
                $table->string('message', 500);
                $table->dateTime('expires_at');
            });
        }
        return $checked = true;
    } catch (\Exception $e) {
        return $checked = false;
    }
}

/**
 * Grava o motivo (já classificado, seguro) do erro mais recente de um
 * cliente, para o app headless poder buscar em seguida via
 * GetPagarmeLastError. Best-effort: uma falha aqui nunca deve interromper o
 * fluxo de pagamento que já retornou 'declined' para o WHMCS.
 *
 * $params (opcional): quando informado e tiver 'axiomToken' preenchido
 * (Setup > Payments > Pagar.me), também envia o mesmo motivo para o Axiom -
 * ver pagarme_sendToAxiom(). Sem $params, ou sem token configurado, só grava
 * a tabela local (comportamento idêntico a antes do Axiom existir).
 *
 * @param int|string $clientId
 * @param string     $code
 * @param string     $message
 * @param int        $ttlSeconds
 * @param array|null $params
 * @return void
 */
function pagarme_storeLastError($clientId, $code, $message, $ttlSeconds = 120, $params = null, $rawCode = null)
{
    $clientId = (int) $clientId;

    if ($clientId > 0 && pagarme_ensureLastErrorTable()) {
        try {
            \WHMCS\Database\Capsule::table('mod_pagarme_last_error')->updateOrInsert(
                array('clientid' => $clientId),
                array(
                    'code'       => substr((string) $code, 0, 40),
                    'message'    => substr((string) $message, 0, 500),
                    'expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds),
                )
            );
        } catch (\Exception $e) {
            // Best-effort - nunca interrompe o fluxo de pagamento.
        }
    }

    if (is_array($params)) {
        pagarme_sendToAxiom($params, $clientId, $code, $message, $rawCode);
    }
}

/**
 * Envia um evento de erro para o Axiom (axiom.co), quando o admin configurou
 * um token de ingestão no módulo (Setup > Payments > Pagar.me > "Axiom -
 * Token de Ingestão"). Existe porque o Gateway Log da WHMCS (destino padrão
 * de pagarme_log()) só é visível para admin dentro da WHMCS, sem alerta
 * algum - erros de pagamento passavam despercebidos até alguém abrir a tela
 * manualmente. O Axiom permite consultar/alertar esses erros de fora da
 * WHMCS (ex: um monitor mandando aviso no Discord).
 *
 * Nunca inclui dado de cartão, CPF/CNPJ ou qualquer dado pessoal bruto - só
 * o (code, message) já classificado por pagarme_classifyDeclineReason(),
 * mesmo texto seguro que vai para GetPagarmeLastError, mais um punhado de
 * IDs não-sensíveis (invoiceid, clientid, gateway) para permitir localizar
 * o caso no admin da WHMCS depois.
 *
 * Best-effort e não-bloqueante por design: timeout de conexão bem curto
 * (2s) e nenhuma exceção sobe daqui - uma falha ao notificar o Axiom NUNCA
 * pode atrasar ou derrubar uma tentativa de pagamento real. Roda de forma
 * síncrona (não há fila/worker neste módulo), então o timeout curto é a
 * única proteção contra atraso - deliberadamente mais agressivo que os 15s
 * usados nas chamadas à Pagar.me, porque isto é telemetria, não a operação
 * principal.
 *
 * @param array      $params
 * @param int|string $clientId
 * @param string     $code
 * @param string     $message
 * @param string|null $rawCode Código de retorno EMV bruto (ex: "1061"), quando
 *                             disponível - ver pagarme_extractEmvCode(). Só
 *                             para telemetria; nunca substitui $code.
 * @return void
 */
function pagarme_sendToAxiom($params, $clientId, $code, $message, $rawCode = null)
{
    $token = isset($params['axiomToken']) ? trim((string) $params['axiomToken']) : '';
    if ($token === '') {
        return;
    }

    $dataset = isset($params['axiomDataset']) && trim((string) $params['axiomDataset']) !== ''
        ? trim((string) $params['axiomDataset'])
        : 'pagarme-whmcs';

    $event = array(
        '_time'      => gmdate('Y-m-d\TH:i:s\Z'),
        'level'      => 'error',
        'code'       => (string) $code,
        'message'    => (string) $message,
        'clientid'   => (int) $clientId,
        'invoiceid'  => isset($params['invoiceid']) ? (string) $params['invoiceid'] : null,
        'gateway'    => isset($params['gatewayid']) ? 'saved_card' : 'typed_card',
        'test_mode'  => isset($params['testMode']) && $params['testMode'] == 'on',
        'source'     => 'pagarme-whmcs',
    );

    // Só inclui a chave quando há um código EMV bruto de verdade - evita
    // poluir todo evento (a maioria não tem, ex: CVV ausente, CPF ausente)
    // com um campo sempre null.
    if ($rawCode !== null && $rawCode !== '') {
        $event['raw_code'] = (string) $rawCode;
    }

    $payload = json_encode(array($event));
    if ($payload === false) {
        return;
    }

    // Formato "legacy" (não-edge) da API REST: /v1/datasets/{dataset}/ingest.
    // O formato /v1/ingest/{dataset} só existe para chamadas via edge domain
    // (não usado aqui - PHP puro fala direto com api.axiom.co).
    $url = 'https://api.axiom.co/v1/datasets/' . rawurlencode($dataset) . '/ingest';

    try {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        // Timeouts curtos de propósito - ver docblock acima.
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_exec($ch);
        curl_close($ch);
    } catch (\Throwable $e) {
        // Best-effort - telemetria nunca pode derrubar um pagamento.
    }
}

/**
 * Grava a parcela escolhida para uma fatura, junto do modo de cálculo que
 * estava ao vivo no momento da escolha (fórmula + margem + fonte/valor da
 * taxa composta personalizada). Sem isso, se o admin trocar o modo, editar a
 * margem ou editar a taxa personalizada entre a escolha do cliente e a
 * captura, o valor cobrado divergiria do que foi exibido - mesmo princípio
 * que já protege base_amount.
 *
 * @param int    $invoiceId
 * @param int    $installments
 * @param float  $baseAmount            Base usada no cálculo, para detectar fatura alterada
 * @param string $formula               'simple'|'compound', modo ao vivo no momento da escolha
 * @param bool   $marginEnabled         Se a margem estava ativa no momento da escolha
 * @param array  $marginSnapshot        Tabela de margem (parcela => %) no momento da escolha
 * @param string $compoundRateSource    'mdr'|'custom', fonte da taxa composta no momento da escolha
 * @param float  $compoundRateSnapshot  Taxa personalizada ÚNICA (mesma para toda parcela/bandeira) no momento da escolha
 * @param int    $ttlSeconds
 * @return bool
 */
function pagarme_storeSelectedInstallments(
    $invoiceId,
    $installments,
    $baseAmount,
    $formula = 'simple',
    $marginEnabled = false,
    $marginSnapshot = array(),
    $compoundRateSource = 'mdr',
    $compoundRateSnapshot = 0.0,
    $ttlSeconds = 1800
) {
    if (!pagarme_ensureInstallmentsTable()) {
        return false;
    }

    $row = array(
        'installments'            => (int) $installments,
        'base_amount'             => number_format((float) $baseAmount, 2, '.', ''),
        'expires_at'              => date('Y-m-d H:i:s', time() + (int) $ttlSeconds),
        'formula'                 => ($formula === 'compound') ? 'compound' : 'simple',
        'margin_enabled'          => $marginEnabled ? 1 : 0,
        'margin_snapshot'         => !empty($marginSnapshot) ? json_encode($marginSnapshot) : null,
        'compound_rate_source'    => ($compoundRateSource === 'custom') ? 'custom' : 'mdr',
        'compound_rate_snapshot'  => ((float) $compoundRateSnapshot > 0) ? number_format((float) $compoundRateSnapshot, 4, '.', '') : null,
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
 * Também devolve o modo de cálculo persistido (formula/margin_enabled/margin),
 * para pagarme_capture() usar como $modeOverride em pagarme_installmentTotal()
 * em vez de reler a configuração ao vivo - garante que a cobrança usa
 * exatamente o modo que estava ativo quando o cliente viu o preço.
 *
 * @param int $invoiceId
 * @return array status: none|valid|expired|base_changed|unavailable
 */
function pagarme_readStoredInstallments($invoiceId)
{
    $empty = array(
        'installments'         => null,
        'base_amount'          => null,
        'formula'              => 'simple',
        'margin_enabled'       => false,
        'margin'               => array(),
        'compound_rate_source' => 'mdr',
        'compound_custom_rate' => 0.0,
    );

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

    $marginSnapshot = array();
    if (!empty($row->margin_snapshot)) {
        $decoded = json_decode($row->margin_snapshot, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $marginSnapshot = $decoded;
        }
    }

    $compoundRateSnapshot = !empty($row->compound_rate_snapshot) ? (float) $row->compound_rate_snapshot : 0.0;

    $found = array(
        'installments'         => (int) $row->installments,
        'base_amount'          => (float) $row->base_amount,
        'formula'              => (isset($row->formula) && $row->formula === 'compound') ? 'compound' : 'simple',
        'margin_enabled'       => !empty($row->margin_enabled),
        'margin'               => $marginSnapshot,
        'compound_rate_source' => (isset($row->compound_rate_source) && $row->compound_rate_source === 'custom') ? 'custom' : 'mdr',
        'compound_custom_rate' => $compoundRateSnapshot,
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
 * @param float  $feeAmount      Fatia da taxa de transação (MDR) atribuída a
 *                                esta parcela do pagamento (0 se nenhuma)
 * @return void
 */
function pagarme_applyInterestPortion($invoiceId, $chargeId, $interestAmount, $feeAmount = 0.0)
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
        addInvoicePayment($invoiceId, $feeTransId, $interestAmount, $feeAmount, 'pagarme');
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

// =========================================================================
// Taxa de transação (MDR real)
//
// Distinta do juros de parcelamento acima: aquele é repassado ao cliente
// SOMENTE fora da faixa sem juros. Esta é o custo real que a Pagar.me cobra
// da loja em QUALQUER cobrança de cartão (inclusive 1x à vista e dentro da
// faixa sem juros), usando a tabela cheia (pagarme_mdrRate: à vista / 2-6x /
// 7-12x). Vai para o campo nativo do WHMCS ('fee' no retorno de _capture,
// que popula tblaccounts.fees - a coluna "Taxas da Transação" da fatura,
// visível no admin) - NÃO soma a tblinvoices.total nem é cobrada do cliente.
// =========================================================================

/**
 * Valor da taxa de transação (MDR real), em reais, para um valor bruto
 * processado pela Pagar.me.
 *
 * @param float  $chargeAmount Valor bruto processado pela Pagar.me
 * @param string $brand        Bandeira do cartão
 * @param int    $installments Parcelas efetivamente cobradas
 * @return float
 */
function pagarme_transactionFeeAmount($chargeAmount, $brand, $installments)
{
    $rate = pagarme_mdrRate($brand, $installments);
    if ($rate <= 0) {
        return 0.0;
    }
    return round(((float) $chargeAmount) * ($rate / 100), 2);
}

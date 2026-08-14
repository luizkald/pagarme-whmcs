<?php
/**
 * Addon WHMCS: edição das taxas MDR da Pagar.me pelo admin.
 *
 * Página única (visualizar + editar) para o financeiro ajustar
 * modules/gateways/pagarme/inc/pagarme_credit_card_taxes.json sem precisar de
 * acesso a servidor/repositório. Controle de acesso é o ACL nativo de Addon
 * Modules por Admin Role (Configuration > System Settings > Admin Roles >
 * aba Addon Modules) — ver README para o passo a passo de ativação.
 *
 * Deliberadamente isolado do módulo de gateway: não dá require em
 * modules/gateways/pagarme/installments.php. Lê o JSON com uma função
 * própria e pequena, para não acoplar a ~40 outras funções do gateway nem
 * arriscar redeclaração de função caso os dois arquivos carreguem no mesmo
 * request. O preço é ~8 linhas de leitura JSON duplicadas — aceitável pelo
 * isolamento que compra.
 *
 * O path do JSON é calculado por travessia relativa a partir de __DIR__, não
 * por ROOTDIR (não confirmado disponível neste contexto). Isso cria um
 * acoplamento leve à ESTRUTURA DE DIRETÓRIOS do módulo de gateway (não ao
 * código dele): se pagarme_credit_card_taxes.json for movido algum dia, este
 * arquivo e modules/gateways/pagarme/installments.php precisam ser
 * atualizados juntos.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

const PAGARME_FEE_RATES_BRANDS = array('visa', 'mastercard', 'elo', 'amex', 'outras');
const PAGARME_FEE_RATES_DEFAULT_COMMENT =
    'Taxas MDR reais da Pagar.me (Stone). Faixas da proposta: 1x (a vista), 2-6x, 7-12x.';
const PAGARME_FEE_RATES_CSRF_SESSION_KEY = 'pagarme_fee_rates_csrf';

/**
 * Token anti-CSRF por sessão. O WHMCS não expõe um mecanismo de CSRF
 * padronizado para Addon Modules (diferente de hooks de client area, que
 * recebem $vars['token']) — confirmado ausente na documentação oficial e em
 * pedido de feature aberto há anos sem resolução. Sem isto, um site externo
 * poderia montar um formulário oculto apontando para
 * addonmodules.php?module=pagarme_fee_rates e, se um admin logado visitasse
 * essa página, o browser enviaria a sessão automaticamente — alterando
 * taxas sem o admin saber.
 *
 * Um token por sessão (não por formulário) é suficiente aqui: a tela não
 * tem múltiplos formulários simultâneos, e reemitir a cada carga só
 * quebraria abrir a tela em duas abas.
 *
 * @return string
 */
function pagarme_fee_rates_csrfToken()
{
    if (empty($_SESSION[PAGARME_FEE_RATES_CSRF_SESSION_KEY])) {
        $_SESSION[PAGARME_FEE_RATES_CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
    }
    return $_SESSION[PAGARME_FEE_RATES_CSRF_SESSION_KEY];
}

/**
 * Valida o token anti-CSRF enviado no POST contra o da sessão, com
 * comparação em tempo constante (hash_equals) para não vazar o valor
 * correto por diferença de tempo de resposta.
 *
 * @param mixed $submitted
 * @return bool
 */
function pagarme_fee_rates_csrfValid($submitted)
{
    $expected = isset($_SESSION[PAGARME_FEE_RATES_CSRF_SESSION_KEY])
        ? $_SESSION[PAGARME_FEE_RATES_CSRF_SESSION_KEY]
        : null;

    if (!$expected || !is_string($submitted) || $submitted === '') {
        return false;
    }

    return hash_equals($expected, $submitted);
}

/**
 * Config do addon (nome/descrição/versão). Sem campos de configuração
 * próprios — nenhuma opção precisa ser guardada em tbladdonmodules.
 */
function pagarme_fee_rates_config()
{
    return array(
        'name'        => 'Pagar.me - Taxas MDR',
        'description' => 'Permite editar as taxas MDR de cartão de crédito da Pagar.me '
            . '(por bandeira e número de parcelas) direto pelo admin do WHMCS, sem acesso '
            . 'a servidor ou repositório.',
        'version'     => '1.0',
        'author'      => 'Stay',
        'fields'      => array(),
    );
}

function pagarme_fee_rates_activate()
{
    return array('status' => 'success');
}

function pagarme_fee_rates_deactivate()
{
    return array('status' => 'success');
}

function pagarme_fee_rates_upgrade($vars)
{
    // Sem migrações até hoje — addon não é dono de nenhuma tabela própria.
}

/**
 * Caminho absoluto do JSON de taxas, no módulo de gateway.
 *
 * @return string
 */
function pagarme_fee_rates_taxesPath()
{
    // __DIR__ = <RAIZ>/modules/addons/pagarme_fee_rates
    // dirname(__DIR__) = <RAIZ>/modules/addons
    // dirname(dirname(__DIR__)) = <RAIZ>/modules
    return dirname(dirname(__DIR__))
        . '/gateways/pagarme/inc/pagarme_credit_card_taxes.json';
}

/**
 * Caminho do piso de taxas (snapshot congelado, criado uma vez).
 *
 * @return string
 */
function pagarme_fee_rates_floorPath()
{
    return dirname(dirname(__DIR__))
        . '/gateways/pagarme/inc/pagarme_credit_card_taxes.floor.json';
}

/**
 * Caminho do arquivo de promoções.
 *
 * @return string
 */
function pagarme_fee_rates_promotionsPath()
{
    return dirname(dirname(__DIR__))
        . '/gateways/pagarme/inc/pagarme_promotions.json';
}

/**
 * Caminho do arquivo de modo de cálculo (fórmula simples/composta + margem).
 *
 * @return string
 */
function pagarme_fee_rates_modePath()
{
    return dirname(dirname(__DIR__))
        . '/gateways/pagarme/inc/pagarme_installment_mode.json';
}

/**
 * Garante que o piso de taxas existe, criando-o a partir do arquivo de
 * taxas ATUAL na primeira vez que esta função roda (nunca depois disso —
 * uma vez criado, o piso só é alterado manualmente por um dev editando o
 * arquivo direto, para refletir uma renegociação real com a Pagar.me).
 *
 * @param string $taxesPath
 * @param string $floorPath
 * @return array O piso (recém-criado ou já existente)
 */
function pagarme_fee_rates_ensureFloor($taxesPath, $floorPath)
{
    if (is_readable($floorPath)) {
        return pagarme_fee_rates_loadTable($floorPath);
    }

    $current = pagarme_fee_rates_loadTable($taxesPath);
    if (empty($current)) {
        return array();
    }

    pagarme_fee_rates_atomicWrite($floorPath, $current);
    return pagarme_fee_rates_loadTable($floorPath);
}

/**
 * Lê e decodifica o JSON de taxas. Nunca fatal: qualquer falha (arquivo
 * ilegível, JSON inválido) devolve array vazio, e quem chama trata isso como
 * "sem dado", igual ao padrão de pagarme_loadTable() no módulo de gateway.
 *
 * @param string $path
 * @return array
 */
function pagarme_fee_rates_loadTable($path)
{
    if (!is_readable($path)) {
        return array();
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return array();
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return array();
    }

    return $decoded;
}

/**
 * Extrai só a grade credito[bandeira][parcela] para renderizar o formulário,
 * com 0 como default de EXIBIÇÃO caso o arquivo em disco já esteja
 * incompleto (nunca usado para decidir o que é gravado — ver
 * pagarme_fee_rates_validateAndBuild(), que rejeita ausência em vez de
 * assumir 0).
 *
 * @param array $table
 * @return array<string, array<string, float>>
 */
function pagarme_fee_rates_extractGrid($table)
{
    $grid = array();
    foreach (PAGARME_FEE_RATES_BRANDS as $brand) {
        $grid[$brand] = array();
        for ($n = 1; $n <= 12; $n++) {
            $key = (string) $n;
            $value = isset($table[$brand]['credito'][$key]) ? $table[$brand]['credito'][$key] : 0;
            $grid[$brand][$key] = (float) $value;
        }
    }
    return $grid;
}

/**
 * Valida o POST e monta o array pronto para serialização.
 *
 * Rejeita a gravação inteira (sem gravação parcial) se qualquer célula:
 * estiver ausente, não for numérica, ou estiver fora de [0, 20].
 *
 * `_comment` é preservado do arquivo atual (ou regenerado com o texto padrão
 * se ausente); `debito` é preservado por bandeira do arquivo atual, nunca
 * calculado ou zerado por este addon, já que nada o lê hoje.
 *
 * O piso (`$floorTable`, snapshot congelado — ver pagarme_fee_rates_ensureFloor())
 * nunca é editado por esta função; só usado para rejeitar um valor abaixo dele.
 *
 * @param mixed $postedRates $_POST['rates'] bruto
 * @param array $currentTable Tabela atual, para _comment/debito e diff
 * @param array $floorTable Piso de taxas (mínimo aceito por célula)
 * @return array{success: bool, data: array|null, errors: string[]}
 */
function pagarme_fee_rates_validateAndBuild($postedRates, $currentTable, $floorTable = array())
{
    $errors = array();

    if (!is_array($postedRates)) {
        return array('success' => false, 'data' => null, 'errors' => array('Dados do formulário ausentes.'));
    }

    $floorGrid = pagarme_fee_rates_extractGrid($floorTable);

    $result = array(
        '_comment' => isset($currentTable['_comment']) && is_string($currentTable['_comment'])
            ? $currentTable['_comment']
            : PAGARME_FEE_RATES_DEFAULT_COMMENT,
    );

    foreach (PAGARME_FEE_RATES_BRANDS as $brand) {
        $brandLabel = pagarme_fee_rates_brandLabel($brand);

        if (!isset($postedRates[$brand]) || !is_array($postedRates[$brand])) {
            $errors[] = "Faixa ausente: {$brandLabel} (nenhum valor recebido).";
            continue;
        }

        $credito = array();
        for ($n = 1; $n <= 12; $n++) {
            $key = (string) $n;

            if (!array_key_exists($key, $postedRates[$brand])) {
                $errors[] = "Faixa ausente: {$brandLabel} {$n}x.";
                continue;
            }

            $raw = trim((string) $postedRates[$brand][$key]);
            if ($raw === '' || !is_numeric($raw)) {
                $errors[] = "Valor inválido em {$brandLabel} {$n}x: informe um número.";
                continue;
            }

            $value = (float) $raw;
            if ($value < 0 || $value > 20) {
                $errors[] = "Valor fora da faixa em {$brandLabel} {$n}x: deve estar entre 0 e 20.";
                continue;
            }

            $floor = isset($floorGrid[$brand][$key]) ? round((float) $floorGrid[$brand][$key], 2) : 0.0;
            if (round($value, 2) < $floor) {
                $errors[] = "Valor abaixo do mínimo em {$brandLabel} {$n}x: mínimo é {$floor}%.";
                continue;
            }

            $credito[$key] = round($value, 2);
        }

        $result[$brand] = array(
            'debito'  => isset($currentTable[$brand]['debito']) ? $currentTable[$brand]['debito'] : 0,
            'credito' => $credito,
        );
    }

    if (!empty($errors)) {
        return array('success' => false, 'data' => null, 'errors' => $errors);
    }

    return array('success' => true, 'data' => $result, 'errors' => array());
}

/**
 * Grava o JSON de forma atômica: escreve num arquivo temporário no mesmo
 * diretório, confirma que o conteúdo escrito é JSON válido, só então troca
 * pelo arquivo final via rename() (atômico em POSIX e NTFS). Qualquer falha
 * em qualquer etapa limpa o temporário e devolve false — o arquivo original
 * nunca fica meio-escrito.
 *
 * @param string $path
 * @param array $data
 * @return bool
 */
function pagarme_fee_rates_atomicWrite($path, $data)
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false || json_last_error() !== JSON_ERROR_NONE) {
        return false;
    }

    $tmp = $path . '.tmp';

    try {
        $written = @file_put_contents($tmp, $json, LOCK_EX);
        if ($written === false) {
            return false;
        }

        $roundTrip = @file_get_contents($tmp);
        if ($roundTrip === false) {
            @unlink($tmp);
            return false;
        }

        $decoded = json_decode($roundTrip, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
    } catch (\Throwable $e) {
        @unlink($tmp);
        return false;
    }
}

/**
 * Registra no Activity Log do WHMCS um diff compacto (só o que mudou de
 * fato) das taxas alteradas. Nunca bloqueia nem desfaz um save que já
 * gravou no disco: qualquer falha de log é engolida.
 *
 * @param array $before Tabela antes do save
 * @param array $after Tabela recém-gravada
 */
function pagarme_fee_rates_logChange($before, $after)
{
    if (!function_exists('logActivity')) {
        return;
    }

    try {
        $changes = array();
        foreach (PAGARME_FEE_RATES_BRANDS as $brand) {
            $brandLabel = pagarme_fee_rates_brandLabel($brand);
            for ($n = 1; $n <= 12; $n++) {
                $key = (string) $n;
                $old = round((float) (isset($before[$brand]['credito'][$key]) ? $before[$brand]['credito'][$key] : 0), 2);
                $new = round((float) (isset($after[$brand]['credito'][$key]) ? $after[$brand]['credito'][$key] : 0), 2);

                if ($old !== $new) {
                    $changes[] = "{$brandLabel} {$n}x: {$old}% -> {$new}%";
                }
            }
        }

        if (empty($changes)) {
            return;
        }

        $adminId = isset($_SESSION['adminid']) ? (int) $_SESSION['adminid'] : 0;

        $suffix = '';
        if (count($changes) > 30) {
            $extra = count($changes) - 30;
            $changes = array_slice($changes, 0, 30);
            $suffix = " ... (+{$extra} mais)";
        }

        $description = 'Pagar.me - Taxas MDR alteradas: ' . implode('; ', $changes) . $suffix;
        logActivity(substr($description, 0, 2000), $adminId);
    } catch (\Throwable $e) {
        // Log nunca pode derrubar um save que já teve sucesso.
    }
}

/**
 * Valida o POST de promoções e monta o array pronto para serialização.
 *
 * Mecanismo separado da grade de taxas — nunca edita nem lê o piso. Cada
 * bandeira tem `active` (bool) e `start`/`end` opcionais ('Y-m-d'). Rejeita
 * a gravação inteira se qualquer bandeira tiver `end` anterior a `start`,
 * ou uma data em formato inválido.
 *
 * @param mixed $postedPromotions $_POST['promotions'] bruto
 * @return array{success: bool, data: array|null, errors: string[]}
 */
function pagarme_fee_rates_validatePromotions($postedPromotions)
{
    $errors = array();
    $result = array();

    if (!is_array($postedPromotions)) {
        $postedPromotions = array();
    }

    foreach (PAGARME_FEE_RATES_BRANDS as $brand) {
        $brandLabel = pagarme_fee_rates_brandLabel($brand);
        $posted = isset($postedPromotions[$brand]) && is_array($postedPromotions[$brand])
            ? $postedPromotions[$brand]
            : array();

        $active = !empty($posted['active']);

        $start = isset($posted['start']) ? trim((string) $posted['start']) : '';
        $end   = isset($posted['end']) ? trim((string) $posted['end']) : '';

        $datePattern = '/^\d{4}-\d{2}-\d{2}$/';
        if ($start !== '' && !preg_match($datePattern, $start)) {
            $errors[] = "Data inválida em Promoções - {$brandLabel}: data de início.";
            $start = '';
        }
        if ($end !== '' && !preg_match($datePattern, $end)) {
            $errors[] = "Data inválida em Promoções - {$brandLabel}: data de fim.";
            $end = '';
        }

        if ($start !== '' && $end !== '' && $end < $start) {
            $errors[] = "Em Promoções - {$brandLabel}: data de fim não pode ser antes da data de início.";
        }

        $result[$brand] = array(
            'active' => $active,
            'start'  => $start !== '' ? $start : null,
            'end'    => $end !== '' ? $end : null,
        );
    }

    if (!empty($errors)) {
        return array('success' => false, 'data' => null, 'errors' => $errors);
    }

    return array('success' => true, 'data' => $result, 'errors' => array());
}

/**
 * Registra no Activity Log as promoções que mudaram (ativação/desativação
 * ou alteração de datas) — separado do log de taxas para não misturar os
 * dois tipos de mudança na mesma entrada.
 *
 * @param array $before
 * @param array $after
 */
function pagarme_fee_rates_logPromotionChange($before, $after)
{
    if (!function_exists('logActivity')) {
        return;
    }

    try {
        $changes = array();
        foreach (PAGARME_FEE_RATES_BRANDS as $brand) {
            $oldBrand = isset($before[$brand]) ? $before[$brand] : array('active' => false, 'start' => null, 'end' => null);
            $newBrand = isset($after[$brand]) ? $after[$brand] : array('active' => false, 'start' => null, 'end' => null);

            $oldKey = json_encode($oldBrand);
            $newKey = json_encode($newBrand);
            if ($oldKey === $newKey) {
                continue;
            }

            $brandLabel = pagarme_fee_rates_brandLabel($brand);
            if (empty($newBrand['active'])) {
                $changes[] = "{$brandLabel} desativada";
                continue;
            }

            $period = '';
            if (!empty($newBrand['start']) || !empty($newBrand['end'])) {
                $period = ' de ' . ($newBrand['start'] ?: '(sem início)') . ' a ' . ($newBrand['end'] ?: '(sem fim)');
            } else {
                $period = ' sem prazo definido';
            }
            $changes[] = "{$brandLabel} ativada{$period}";
        }

        if (empty($changes)) {
            return;
        }

        $adminId = isset($_SESSION['adminid']) ? (int) $_SESSION['adminid'] : 0;
        $description = 'Pagar.me - Promoções alteradas: ' . implode('; ', $changes);
        logActivity(substr($description, 0, 2000), $adminId);
    } catch (\Throwable $e) {
        // Log nunca pode derrubar um save que já teve sucesso.
    }
}

/**
 * Valida o POST do modo de cálculo (fórmula simples/composta, fonte da taxa
 * composta, margem fixa opcional) e monta o array pronto para serialização.
 *
 * Diferente da grade de taxas MDR: nem a margem nem a taxa personalizada da
 * composta têm piso/mínimo (são parâmetros discricionários da loja, sem
 * custo real de gateway a proteger) - só a faixa 0-20 já usada na grade
 * principal. Confirmado explicitamente com o usuário: a taxa personalizada
 * pode ser menor que a MDR real sem quebrar a trava de mínimo, porque a
 * decisão de negócio é dela mesma servir como um valor livre.
 *
 * A taxa personalizada é um valor ÚNICO (não uma grade por parcela): decisão
 * confirmada com o usuário em 14/08/2026 - o mesmo percentual vale para
 * qualquer bandeira e qualquer número de parcelas, ao contrário da margem
 * (que continua sendo uma grade de 12 células).
 *
 * @param mixed $postedMode $_POST['mode'] bruto
 * @return array{success: bool, data: array|null, errors: string[]}
 */
function pagarme_fee_rates_validateMode($postedMode)
{
    $errors = array();

    if (!is_array($postedMode)) {
        $postedMode = array();
    }

    $formula = (isset($postedMode['formula']) && $postedMode['formula'] === 'compound') ? 'compound' : 'simple';
    $compoundRateSource = (isset($postedMode['compound_rate_source']) && $postedMode['compound_rate_source'] === 'custom')
        ? 'custom'
        : 'mdr';
    $marginEnabled = !empty($postedMode['margin_enabled']);

    $margin = array();
    $postedMargin = isset($postedMode['margin']) && is_array($postedMode['margin']) ? $postedMode['margin'] : array();

    for ($n = 1; $n <= 12; $n++) {
        $key = (string) $n;
        $raw = array_key_exists($key, $postedMargin) ? trim((string) $postedMargin[$key]) : '0';

        if ($raw === '' || !is_numeric($raw)) {
            $errors[] = "Valor inválido em Margem fixa {$n}x: informe um número.";
            continue;
        }

        $value = (float) $raw;
        if ($value < 0 || $value > 20) {
            $errors[] = "Valor fora da faixa em Margem fixa {$n}x: deve estar entre 0 e 20.";
            continue;
        }

        $margin[$key] = round($value, 2);
    }

    $compoundCustomRate = 0.0;
    $rawCustomRate = isset($postedMode['compound_custom_rate']) ? trim((string) $postedMode['compound_custom_rate']) : '0';

    if ($rawCustomRate === '' || !is_numeric($rawCustomRate)) {
        $errors[] = 'Valor inválido em Taxa personalizada: informe um número.';
    } else {
        $customValue = (float) $rawCustomRate;
        if ($customValue < 0 || $customValue > 20) {
            $errors[] = 'Valor fora da faixa em Taxa personalizada: deve estar entre 0 e 20.';
        } else {
            $compoundCustomRate = round($customValue, 4);
        }
    }

    if (!empty($errors)) {
        return array('success' => false, 'data' => null, 'errors' => $errors);
    }

    return array(
        'success' => true,
        'data'    => array(
            'formula'              => $formula,
            'compound_rate_source' => $compoundRateSource,
            'compound_custom_rate' => $compoundCustomRate,
            'margin_enabled'       => $marginEnabled,
            'margin'               => $margin,
        ),
        'errors' => array(),
    );
}

/**
 * Registra no Activity Log a mudança de modo de cálculo (fórmula e/ou
 * margem) - separado dos outros dois logs para não misturar tipos de
 * mudança na mesma entrada.
 *
 * @param array $before
 * @param array $after
 */
function pagarme_fee_rates_logModeChange($before, $after)
{
    if (!function_exists('logActivity')) {
        return;
    }

    try {
        $changes = array();

        $oldFormula = isset($before['formula']) && $before['formula'] === 'compound' ? 'compound' : 'simple';
        $newFormula = isset($after['formula']) && $after['formula'] === 'compound' ? 'compound' : 'simple';
        if ($oldFormula !== $newFormula) {
            $label = array('simple' => 'Simples', 'compound' => 'Composta (Tabela Price)');
            $changes[] = "Fórmula: {$label[$oldFormula]} -> {$label[$newFormula]}";
        }

        $oldRateSource = isset($before['compound_rate_source']) && $before['compound_rate_source'] === 'custom' ? 'custom' : 'mdr';
        $newRateSource = isset($after['compound_rate_source']) && $after['compound_rate_source'] === 'custom' ? 'custom' : 'mdr';
        if ($oldRateSource !== $newRateSource) {
            $sourceLabel = array('mdr' => 'Taxa MDR da tabela', 'custom' => 'Taxa personalizada única');
            $changes[] = "Fonte da taxa composta: {$sourceLabel[$oldRateSource]} -> {$sourceLabel[$newRateSource]}";
        }

        $oldCustomRate = round((float) (isset($before['compound_custom_rate']) ? $before['compound_custom_rate'] : 0), 4);
        $newCustomRate = round((float) (isset($after['compound_custom_rate']) ? $after['compound_custom_rate'] : 0), 4);
        if ($oldCustomRate !== $newCustomRate) {
            $changes[] = "Taxa personalizada: {$oldCustomRate}% -> {$newCustomRate}%";
        }

        $oldMarginEnabled = !empty($before['margin_enabled']);
        $newMarginEnabled = !empty($after['margin_enabled']);
        if ($oldMarginEnabled !== $newMarginEnabled) {
            $changes[] = 'Margem fixa: ' . ($newMarginEnabled ? 'ativada' : 'desativada');
        }

        $oldMargin = isset($before['margin']) && is_array($before['margin']) ? $before['margin'] : array();
        $newMargin = isset($after['margin']) && is_array($after['margin']) ? $after['margin'] : array();
        for ($n = 1; $n <= 12; $n++) {
            $key = (string) $n;
            $old = round((float) (isset($oldMargin[$key]) ? $oldMargin[$key] : 0), 2);
            $new = round((float) (isset($newMargin[$key]) ? $newMargin[$key] : 0), 2);
            if ($old !== $new) {
                $changes[] = "Margem {$n}x: {$old}% -> {$new}%";
            }
        }

        if (empty($changes)) {
            return;
        }

        $adminId = isset($_SESSION['adminid']) ? (int) $_SESSION['adminid'] : 0;
        $description = 'Pagar.me - Modo de cálculo alterado: ' . implode('; ', $changes);
        logActivity(substr($description, 0, 2000), $adminId);
    } catch (\Throwable $e) {
        // Log nunca pode derrubar um save que já teve sucesso.
    }
}

/**
 * Ciclos conhecidos (rótulo => meses), mesmos usados em
 * includes/hooks/pagarme_installments_selector.php (CYCLE_LABELS) - mantidos
 * em sincronia manualmente, mesmo padrão de duplicação fina já usado em todo
 * este arquivo (isolamento deliberado, ver cabeçalho do arquivo).
 *
 * @return array<string, array{label: string, months: int}>
 */
function pagarme_fee_rates_cycles()
{
    return array(
        'monthly'      => array('label' => 'Mensal', 'months' => 1),
        'quarterly'    => array('label' => 'Trimestral', 'months' => 3),
        'semiannually' => array('label' => 'Semestral', 'months' => 6),
        'annually'     => array('label' => 'Anual', 'months' => 12),
        'biennially'   => array('label' => 'Bienal', 'months' => 24),
        'triennially'  => array('label' => 'Trienal', 'months' => 36),
    );
}

/**
 * Teto de parcelas para um ciclo (em meses). Espelha
 * pagarme_maxInstallmentsForMonths() em modules/gateways/pagarme/installments.php
 * - replicado aqui por ser uma tabela fixa pequena, mantendo o isolamento do
 * addon (ver cabeçalho do arquivo). Mudar as regras de negócio exige
 * atualizar as duas funções.
 *
 * @param int $months
 * @return int
 */
function pagarme_fee_rates_maxInstallmentsForMonths($months)
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
    return 12;
}

/**
 * Parcelas sem juros para um ciclo (em meses). Espelha
 * pagarme_freeInstallmentsForMonths() em modules/gateways/pagarme/installments.php
 * - mesma nota de replicação acima.
 *
 * @param int $months
 * @return int
 */
function pagarme_fee_rates_freeInstallmentsForMonths($months)
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
    return 6;
}

/**
 * Converte um valor monetário digitado em BR (1.389,96 ou 1389,96) ou em US
 * (1389.96) para float. Aceita os dois formatos porque o campo é texto livre
 * e a maior parte de quem usa esta tela vai digitar no formato BR (vírgula
 * decimal) - is_numeric() sozinho rejeita vírgula, o que fazia todo valor
 * digitado nesse formato falhar a validação.
 *
 * Regra: se o valor tem vírgula E ponto, o ÚLTIMO separador (o mais à
 * direita) é o decimal, e o outro é separador de milhar, removido antes de
 * converter - cobre "1.389,96" (BR) e, por simetria, "1,389.96" (US) sem
 * precisar de um campo de formato separado. Se só tem vírgula, ela é o
 * decimal. Se só tem ponto, ele é o decimal (comportamento nativo do PHP).
 *
 * @param mixed $raw
 * @return float|null null se não for um número válido em nenhum dos formatos
 */
function pagarme_fee_rates_parseAmount($raw)
{
    $value = trim((string) $raw);
    if ($value === '') {
        return null;
    }

    $hasComma = strpos($value, ',') !== false;
    $hasDot = strpos($value, '.') !== false;

    if ($hasComma && $hasDot) {
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if ($lastComma > $lastDot) {
            // "1.389,96" - ponto é milhar, vírgula é decimal.
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            // "1,389.96" - vírgula é milhar, ponto é decimal.
            $value = str_replace(',', '', $value);
        }
    } elseif ($hasComma) {
        $value = str_replace(',', '.', $value);
    }
    // Só ponto, ou nenhum separador: já está no formato que is_numeric() aceita.

    return is_numeric($value) ? (float) $value : null;
}

/**
 * Simula o parcelamento para um valor/bandeira/ciclo/modo, SEM tocar em
 * nenhum arquivo em disco - nunca grava, nunca loga, nunca afeta o modo de
 * cálculo salvo (aba "Modo de Cálculo"). Usa as taxas MDR REAIS já gravadas
 * (via $taxesTable, já carregada pelo output() - não relê o arquivo aqui),
 * para a simulação refletir fielmente o que uma cobrança real faria.
 *
 * A matemática de juros (simples/composta/margem) é replicada aqui a partir
 * de pagarme_compoundTotal()/pagarme_installmentTotal()
 * (modules/gateways/pagarme/installments.php) - deliberado, não um lapso: ao
 * contrário dos outros lugares desta sessão que duplicavam cálculo (onde
 * divergir significa cobrar errado), esta simulação nunca cobra ninguém e
 * nunca é lida por nenhum outro código. O pior caso de uma divergência
 * futura é a calculadora mostrar um número errado numa tela só de consulta,
 * nunca uma cobrança incorreta - por isso o isolamento do addon (sem
 * `require` do módulo de gateway) prevalece aqui também.
 *
 * O "resumo" de cada linha (valor inicial / valor adicional de parcelamento /
 * taxa de serviço do gateway) mostra: base da fatura; o juros repassado ao
 * CLIENTE (0 dentro da faixa sem juros); e o custo MDR REAL que a Pagar.me
 * cobra da loja pela FAIXA de parcelas escolhida (ex: 12x usa a taxa real de
 * 7-12x, não a de 1x), sobre o total. Esta terceira coluna espelha o campo
 * interno real do WHMCS (pagarme_transactionFeeAmount($chargeAmount, $brand,
 * $installments) em pagarme.php - usa a taxa da faixa real desde a reversão
 * de 14/08/2026), replicada aqui pela mesma nota de isolamento acima.
 *
 * @param mixed  $amount              Valor a simular, texto livre (BR ou US) - ver pagarme_fee_rates_parseAmount()
 * @param string $brand               Bandeira (uma das PAGARME_FEE_RATES_BRANDS)
 * @param string $cycleKey            Chave de pagarme_fee_rates_cycles()
 * @param string $formula             'simple'|'compound'
 * @param string $compoundRateSource  'mdr'|'custom' - só relevante quando $formula === 'compound'
 * @param float  $compoundCustomRate  Taxa mensal composta personalizada ÚNICA (mesma para qualquer bandeira/parcela)
 * @param bool   $marginEnabled
 * @param array  $marginTable         parcela => %
 * @param array  $taxesTable          Tabela de taxas MDR já carregada (pagarme_credit_card_taxes.json)
 * @return array{success: bool, errors: string[], rows: array, cycleLabel: string, maxInstallments: int, freeInstallments: int}
 */
function pagarme_fee_rates_simulate(
    $amount,
    $brand,
    $cycleKey,
    $formula,
    $compoundRateSource,
    $compoundCustomRate,
    $marginEnabled,
    $marginTable,
    $taxesTable
) {
    $errors = array();

    $amount = pagarme_fee_rates_parseAmount($amount);
    if ($amount === null || $amount <= 0) {
        $errors[] = 'Informe um valor maior que zero para simular (aceita vírgula ou ponto decimal).';
        $amount = 0.0;
    }

    $cycles = pagarme_fee_rates_cycles();
    if (!isset($cycles[$cycleKey])) {
        $errors[] = 'Ciclo inválido.';
    }

    if (!in_array($brand, PAGARME_FEE_RATES_BRANDS, true)) {
        $brand = 'outras';
    }

    if (!empty($errors)) {
        return array(
            'success'          => false,
            'errors'           => $errors,
            'rows'             => array(),
            'cycleLabel'       => '',
            'maxInstallments'  => 0,
            'freeInstallments' => 0,
        );
    }

    $months = $cycles[$cycleKey]['months'];
    $max = pagarme_fee_rates_maxInstallmentsForMonths($months);
    $free = pagarme_fee_rates_freeInstallmentsForMonths($months);

    $feeTable = isset($taxesTable[$brand]['credito']) ? $taxesTable[$brand]['credito'] : array();
    if (empty($feeTable) && isset($taxesTable['outras']['credito'])) {
        $feeTable = $taxesTable['outras']['credito'];
    }

    $rows = array();
    for ($n = 1; $n <= $max; $n++) {
        // Taxa REAL da faixa desta parcela (MDR que a Pagar.me cobra da loja),
        // sem zerar pela faixa sem juros - distinta do juros repassado ao
        // CLIENTE, que é 0 dentro da faixa livre. Mesma distinção que
        // pagarme_mdrRate() (custo real, sempre) faz com
        // pagarme_customerRate() (juros do cliente, zera na faixa livre) no
        // módulo de gateway.
        $realMdrRate = isset($feeTable[(string) $n]) ? (float) $feeTable[(string) $n] : 0.0;
        $customerRate = ($n <= $free) ? 0.0 : $realMdrRate;

        if ($customerRate <= 0) {
            $total = $amount;
        } elseif ($formula === 'compound') {
            // Mesma fórmula de pagarme_compoundTotal() (Tabela Price), replicada
            // aqui deliberadamente - ver nota de isolamento no docblock acima.
            // A taxa usada para COMPOR pode vir da tabela MDR real ($customerRate,
            // padrão) ou de uma taxa personalizada ÚNICA (mesmo valor para
            // qualquer bandeira/parcela) - mas SE há juros continua decidido só
            // por $customerRate acima, nunca pela personalizada (mesma regra do
            // core, ver pagarme_installmentTotal()).
            $compoundRate = ($compoundRateSource === 'custom') ? (float) $compoundCustomRate : $customerRate;

            if ($compoundRate <= 0) {
                $total = $amount;
            } else {
                $i = $compoundRate / 100;
                $factor = pow(1 + $i, $n);
                $total = ($factor > 1) ? round($amount * ($i * $factor) / ($factor - 1) * $n, 2) : $amount;
                $total = max($total, $amount);
            }
        } else {
            $total = round($amount + $amount * ($customerRate / 100), 2);
        }

        if ($marginEnabled && $n > $free) {
            $marginPct = isset($marginTable[(string) $n]) ? (float) $marginTable[(string) $n] : 0.0;
            if ($marginPct > 0) {
                $total = round($total * (1 + $marginPct / 100), 2);
            }
        }

        $effectiveRate = $amount > 0 ? round((($total - $amount) / $amount) * 100, 4) : 0.0;
        $installmentFee = round($total - $amount, 2);
        // Taxa de serviço do gateway, na simulação: a taxa MDR REAL da faixa
        // escolhida (não fixa em 1x) sobre o total - mesma regra do campo
        // interno real do WHMCS (pagarme_transactionFeeAmount() em
        // pagarme.php, taxa da faixa real desde a reversão de 14/08/2026).
        $gatewayServiceFee = round($total * ($realMdrRate / 100), 2);

        $rows[] = array(
            'installments'       => $n,
            'rate'               => $effectiveRate,
            'interest_free'      => ($total <= $amount + 0.001),
            'installment_amount' => round($total / $n, 2),
            'total'              => $total,
            'initial_amount'     => $amount,
            'installment_fee'    => $installmentFee,
            'gateway_service_fee' => $gatewayServiceFee,
        );
    }

    return array(
        'success'          => true,
        'errors'           => array(),
        'rows'             => $rows,
        'cycleLabel'       => $cycles[$cycleKey]['label'],
        'maxInstallments'  => $max,
        'freeInstallments' => $free,
    );
}

/**
 * Rótulo de exibição de uma bandeira (para mensagens de erro/log legíveis).
 *
 * @param string $brand
 * @return string
 */
function pagarme_fee_rates_brandLabel($brand)
{
    $labels = array(
        'visa'       => 'Visa',
        'mastercard' => 'Mastercard',
        'elo'        => 'Elo',
        'amex'       => 'Amex',
        'outras'     => 'Outras bandeiras',
    );
    return isset($labels[$brand]) ? $labels[$brand] : $brand;
}

function pagarme_fee_rates_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Renderiza a página (4 abas: Taxas, Promoção sem juros, Modo de Cálculo,
 * Simulador + avisos). $values é a grade bandeira => parcela => valor (crua,
 * pode conter o que o usuário acabou de digitar em caso de erro de
 * validação). $floorGrid é o piso, mesma forma, só leitura (nunca editável
 * pela tela). $promotions é bandeira => {active, start, end}. $mode é
 * {formula, margin_enabled, margin}. $simInput são os campos da simulação tal
 * como enviados (para reexibir o formulário preenchido); $simResult é o
 * retorno de pagarme_fee_rates_simulate() ou null se ainda não simulou.
 *
 * @param array $values
 * @param array $floorGrid
 * @param array $promotions
 * @param array $mode
 * @param array $simInput
 * @param array|null $simResult
 * @param string[] $errors
 * @param bool $justSaved
 * @param string $moduleLink
 * @param string $activeTab 'taxas'|'promo'|'modo'|'sim' - qual aba deve abrir já selecionada
 * @return string
 */
function pagarme_fee_rates_renderForm($values, $floorGrid, $promotions, $mode, $simInput, $simResult, $errors, $justSaved, $moduleLink, $activeTab = 'taxas')
{
    $knownTabs = array('taxas', 'promo', 'modo', 'sim');
    if (!in_array($activeTab, $knownTabs, true)) {
        $activeTab = 'taxas';
    }
    $groups = array(
        1  => 'g1',
        2  => 'g2', 3 => 'g2', 4 => 'g2', 5 => 'g2', 6 => 'g2',
        7  => 'g3', 8 => 'g3', 9 => 'g3', 10 => 'g3', 11 => 'g3', 12 => 'g3',
    );

    ob_start();
    ?>
    <style>
        .pfr-wrap { max-width: 100%; overflow-x: auto; }
        .pfr-table { border-collapse: collapse; margin-top: 10px; }
        .pfr-table th, .pfr-table td { border: 1px solid #ddd; padding: 6px 8px; text-align: center; vertical-align: top; }
        .pfr-table th { background: #f5f5f5; font-weight: 600; }
        .pfr-table td.pfr-brand { text-align: left; font-weight: 600; background: #fafafa; white-space: nowrap; vertical-align: middle; }
        .pfr-table th.g1, .pfr-table td.g1 { border-left: 3px solid #999; }
        .pfr-table th.g2, .pfr-table td.g2 { border-left: 3px solid #999; }
        .pfr-table th.g3, .pfr-table td.g3 { border-left: 3px solid #999; }
        .pfr-cell { display: flex; flex-direction: column; align-items: center; gap: 2px; }
        /* Slider só aparece com o mouse sobre a célula da taxa (td.g1/g2/g3),
           não só sobre o próprio input — pedido de UI, evita 60 sliders
           visíveis ao mesmo tempo. visibility (não display) para o espaço
           ficar sempre reservado, sem a tabela "pular" no hover, e para
           navegação por teclado (:focus-within) continuar revelando o
           slider quando o campo numérico recebe foco via Tab. */
        .pfr-table td.g1 input[type="range"],
        .pfr-table td.g2 input[type="range"],
        .pfr-table td.g3 input[type="range"] {
            visibility: hidden;
        }
        .pfr-table td.g1:hover input[type="range"],
        .pfr-table td.g2:hover input[type="range"],
        .pfr-table td.g3:hover input[type="range"],
        .pfr-table td.g1:focus-within input[type="range"],
        .pfr-table td.g2:focus-within input[type="range"],
        .pfr-table td.g3:focus-within input[type="range"] {
            visibility: visible;
        }
        .pfr-cell input[type="range"] { width: 72px; }
        .pfr-percent-wrap { position: relative; display: inline-block; }
        .pfr-percent-wrap input[type="number"] { width: 64px; padding-right: 16px; text-align: center; }
        .pfr-percent-sign {
            position: absolute;
            right: 5px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 11px;
            color: #999;
            pointer-events: none;
        }
        .pfr-floor-hint { font-size: 10px; color: #999; white-space: nowrap; }
        .pfr-actions { margin-top: 14px; }
        .pfr-hint { color: #666; font-size: 12px; margin-top: 6px; max-width: 720px; line-height: 1.5; }
        .pfr-promo-table { border-collapse: collapse; margin-top: 10px; width: 100%; max-width: 720px; }
        .pfr-promo-table th, .pfr-promo-table td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; }
        .pfr-promo-table th { background: #f5f5f5; font-weight: 600; }
        .pfr-promo-table input[type="date"] { width: 140px; }
        .pfr-section-title { margin-top: 26px; }
        .pfr-promo-dates-hint {
            margin-top: 10px;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #fafafa;
            font-size: 12px;
            color: #555;
            max-width: 720px;
        }
        .pfr-promo-dates-cols {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 8px;
        }
        .pfr-promo-dates-cols > div { flex: 1 1 260px; min-width: 220px; }
        .pfr-promo-dates-cols ul { margin: 4px 0 0; padding-left: 18px; }
        .pfr-promo-dates-cols li { margin-bottom: 2px; }
        .pfr-tabs { display: flex; gap: 4px; border-bottom: 2px solid #ddd; margin-top: 14px; }
        .pfr-tab-btn {
            padding: 8px 16px;
            border: 1px solid #ddd;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            background: #f5f5f5;
            color: #555;
            font-size: 13px;
            cursor: pointer;
        }
        .pfr-tab-btn.pfr-tab-active { background: #fff; color: #222; font-weight: 600; border-bottom: 2px solid #fff; margin-bottom: -2px; }
        .pfr-tab-panel { display: none; padding-top: 16px; }
        .pfr-tab-panel.pfr-tab-active { display: block; }
        .pfr-mode-table { border-collapse: collapse; margin-top: 10px; }
        .pfr-mode-table th, .pfr-mode-table td { border: 1px solid #ddd; padding: 6px 8px; text-align: center; vertical-align: top; }
        .pfr-mode-table th { background: #f5f5f5; font-weight: 600; }
        .pfr-mode-example {
            margin-top: 10px;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #fafafa;
            font-size: 12px;
            color: #555;
            max-width: 720px;
        }
        .pfr-mode-example table { border-collapse: collapse; margin-top: 8px; }
        .pfr-mode-example th, .pfr-mode-example td { padding: 4px 10px; text-align: left; }
        .pfr-mode-warning {
            margin-top: 10px;
            padding: 10px 12px;
            border: 1px solid color-mix(in srgb, #d92d20 40%, #ddd);
            border-radius: 4px;
            background: color-mix(in srgb, #d92d20 8%, #fff);
            font-size: 12px;
            color: #7a1f16;
            max-width: 720px;
        }
        #pfr-margin-table-wrap { display: none; margin-top: 10px; }
        .pfr-sim-fields { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; max-width: 720px; }
        .pfr-sim-fields label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; }
        .pfr-sim-fields input[type="text"], .pfr-sim-fields select { height: 32px; padding: 0 8px; }
        .pfr-sim-note {
            margin-top: 10px;
            padding: 8px 12px;
            border: 1px solid color-mix(in srgb, #0d6efd 40%, #ddd);
            border-radius: 4px;
            background: color-mix(in srgb, #0d6efd 6%, #fff);
            font-size: 12px;
            color: #0a3a7a;
            max-width: 720px;
        }
        .pfr-sim-results { border-collapse: collapse; margin-top: 16px; }
        .pfr-sim-results th, .pfr-sim-results td { border: 1px solid #ddd; padding: 6px 10px; text-align: center; }
        .pfr-sim-results th { background: #f5f5f5; font-weight: 600; }
        .pfr-sim-results td.pfr-sim-free { color: #17803d; }
    </style>
    <div class="pfr-wrap">
        <h3>Taxas MDR - Pagar.me</h3>
        <p class="pfr-hint" style="font-size:13px;color:#444;">Percentual de taxa cobrado por bandeira e número de parcelas. Valores entre 0 e 20, nunca abaixo do mínimo (custo real do gateway).</p>

        <?php if ($justSaved): ?>
            <div class="alert alert-success">Taxas atualizadas com sucesso.</div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <strong>Não foi possível salvar. Corrija os itens abaixo:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo pagarme_fee_rates_h($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo pagarme_fee_rates_h($moduleLink); ?>" id="pfr-form">
            <input type="hidden" name="csrf_token" value="<?php echo pagarme_fee_rates_h(pagarme_fee_rates_csrfToken()); ?>">
            <input type="hidden" name="active_tab" id="pfr-active-tab" value="<?php echo pagarme_fee_rates_h($activeTab); ?>">

            <div class="pfr-tabs">
                <button type="button" class="pfr-tab-btn<?php echo $activeTab === 'taxas' ? ' pfr-tab-active' : ''; ?>" data-tab="taxas">Taxas</button>
                <button type="button" class="pfr-tab-btn<?php echo $activeTab === 'promo' ? ' pfr-tab-active' : ''; ?>" data-tab="promo">Promoção sem juros</button>
                <button type="button" class="pfr-tab-btn<?php echo $activeTab === 'modo' ? ' pfr-tab-active' : ''; ?>" data-tab="modo">Modo de Cálculo</button>
                <button type="button" class="pfr-tab-btn<?php echo $activeTab === 'sim' ? ' pfr-tab-active' : ''; ?>" data-tab="sim">Simulador</button>
            </div>

            <div class="pfr-tab-panel<?php echo $activeTab === 'taxas' ? ' pfr-tab-active' : ''; ?>" data-tab="taxas">
            <table class="pfr-table">
                <thead>
                    <tr>
                        <th>Bandeira</th>
                        <?php for ($n = 1; $n <= 12; $n++): ?>
                            <th class="<?php echo $groups[$n]; ?>"><?php echo $n; ?>x</th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (PAGARME_FEE_RATES_BRANDS as $brand): ?>
                        <tr>
                            <td class="pfr-brand"><?php echo pagarme_fee_rates_h(pagarme_fee_rates_brandLabel($brand)); ?></td>
                            <?php for ($n = 1; $n <= 12; $n++):
                                $key = (string) $n;
                                $value = isset($values[$brand][$key]) ? $values[$brand][$key] : '';
                                $floor = isset($floorGrid[$brand][$key]) ? (float) $floorGrid[$brand][$key] : 0;
                                $inputName = "rates[{$brand}][{$n}]";
                                $inputId = "pfr-rate-{$brand}-{$n}";
                            ?>
                                <td class="<?php echo $groups[$n]; ?>">
                                    <div class="pfr-cell">
                                        <input
                                            type="range"
                                            id="<?php echo $inputId; ?>-range"
                                            step="0.01"
                                            min="<?php echo pagarme_fee_rates_h($floor); ?>"
                                            max="20"
                                            value="<?php echo pagarme_fee_rates_h($value !== '' ? $value : $floor); ?>"
                                            oninput="document.getElementById('<?php echo $inputId; ?>').value = this.value"
                                        >
                                        <span class="pfr-percent-wrap">
                                            <input
                                                type="number"
                                                id="<?php echo $inputId; ?>"
                                                step="0.01"
                                                min="<?php echo pagarme_fee_rates_h($floor); ?>"
                                                max="20"
                                                name="<?php echo pagarme_fee_rates_h($inputName); ?>"
                                                value="<?php echo pagarme_fee_rates_h($value); ?>"
                                                oninput="document.getElementById('<?php echo $inputId; ?>-range').value = this.value"
                                            >
                                            <span class="pfr-percent-sign" aria-hidden="true">%</span>
                                        </span>
                                        <span class="pfr-floor-hint">mín. <?php echo pagarme_fee_rates_h(number_format($floor, 2, ',', '')); ?></span>
                                    </div>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="pfr-hint">
                As colunas 2x-6x e 7x-12x acompanham as faixas de taxa acordadas com a Pagar.me.
                "Outras bandeiras" é usada para Hipercard, Diners e qualquer bandeira não listada.
                O mínimo de cada célula é o custo real do gateway; não é possível salvar abaixo dele.
            </p>
            </div>

            <div class="pfr-tab-panel<?php echo $activeTab === 'promo' ? ' pfr-tab-active' : ''; ?>" data-tab="promo">
            <h3 class="pfr-section-title">Promoções sem juros</h3>
            <p class="pfr-hint">
                Enquanto ativa, TODAS as parcelas da bandeira ficam sem juros, mesmo acima do teto
                normal do ciclo. Não altera a grade de taxas acima. Datas são opcionais: sem elas,
                a promoção vale enquanto estiver marcada como ativa.
            </p>
            <div class="pfr-promo-dates-hint">
                <strong>Sobre as horas do dia selecionado:</strong> a data não tem horário próprio
                — o dia inteiro conta, do início ao fim.
                <div class="pfr-promo-dates-cols">
                    <div>
                        <strong>Data de início</strong>
                        <ul>
                            <li>Vale a partir de 00h00 daquele dia.</li>
                            <li>Ex.: início em 01/09 já vale às 00h01 do dia 1.</li>
                        </ul>
                    </div>
                    <div>
                        <strong>Data de fim</strong>
                        <ul>
                            <li>Vale até 23h59 daquele dia (o dia inteiro conta).</li>
                            <li>Ex.: fim em 30/09 ainda vale às 23h50 do dia 30, mas não mais no dia 1º/10.</li>
                        </ul>
                    </div>
                </div>
            </div>
            <table class="pfr-promo-table">
                <thead>
                    <tr>
                        <th>Bandeira</th>
                        <th>Ativa</th>
                        <th>Início</th>
                        <th>Fim</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (PAGARME_FEE_RATES_BRANDS as $brand):
                        $promo = isset($promotions[$brand]) ? $promotions[$brand] : array('active' => false, 'start' => null, 'end' => null);
                    ?>
                        <tr>
                            <td><?php echo pagarme_fee_rates_h(pagarme_fee_rates_brandLabel($brand)); ?></td>
                            <td>
                                <input
                                    type="checkbox"
                                    name="promotions[<?php echo pagarme_fee_rates_h($brand); ?>][active]"
                                    value="1"
                                    <?php echo !empty($promo['active']) ? 'checked' : ''; ?>
                                >
                            </td>
                            <td>
                                <input
                                    type="date"
                                    name="promotions[<?php echo pagarme_fee_rates_h($brand); ?>][start]"
                                    value="<?php echo pagarme_fee_rates_h($promo['start'] ?? ''); ?>"
                                >
                            </td>
                            <td>
                                <input
                                    type="date"
                                    name="promotions[<?php echo pagarme_fee_rates_h($brand); ?>][end]"
                                    value="<?php echo pagarme_fee_rates_h($promo['end'] ?? ''); ?>"
                                >
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <div class="pfr-tab-panel<?php echo $activeTab === 'modo' ? ' pfr-tab-active' : ''; ?>" data-tab="modo">
            <h3 class="pfr-section-title">Modo de Cálculo</h3>
            <p class="pfr-hint">
                Como o juros de parcelamento é calculado quando o cliente escolhe mais de 1x
                (acima da faixa sem juros do ciclo). Afeta o valor cobrado do cliente - a mesma
                escolha do cliente entre digitar a parcela e pagar de fato pode acontecer minutos
                ou horas depois, então o modo ativo no momento da escolha fica preso àquela
                cobrança, mesmo que este ajuste seja alterado depois.
            </p>

            <div>
                <label style="display:block;margin-top:10px;">
                    <input type="radio" name="mode[formula]" value="simple"
                        <?php echo (empty($mode['formula']) || $mode['formula'] !== 'compound') ? 'checked' : ''; ?>>
                    Simples (juros aplicado uma única vez sobre o valor da fatura)
                </label>
                <label style="display:block;margin-top:6px;">
                    <input type="radio" name="mode[formula]" value="compound" id="pfr-formula-compound"
                        <?php echo (!empty($mode['formula']) && $mode['formula'] === 'compound') ? 'checked' : ''; ?>>
                    Composta — Tabela Price (juros compostos por parcela, mesmo modelo de financiamento bancário)
                </label>
            </div>

            <div id="pfr-mode-compound-warning" class="pfr-mode-warning" style="display:none;">
                <strong>Atenção:</strong> o modo composto aumenta significativamente o custo para
                o cliente em parcelamentos longos (o juros incide sobre o juros acumulado a cada
                parcela, não só sobre o valor original). Confirme com o financeiro antes de ativar
                em produção.
            </div>

            <div id="pfr-compound-rate-source-wrap" style="display:none;margin-top:12px;padding-left:22px;">
                <p class="pfr-hint" style="margin-top:0;">
                    Taxa usada para compor os juros do modo Composto (não decide se a parcela TEM
                    juros — isso continua vindo do teto do ciclo e das promoções ativas na aba
                    "Promoção sem juros"; só decide o percentual usado a partir daí).
                </p>
                <label style="display:block;">
                    <input type="radio" name="mode[compound_rate_source]" value="mdr"
                        <?php echo (empty($mode['compound_rate_source']) || $mode['compound_rate_source'] !== 'custom') ? 'checked' : ''; ?>>
                    Usar taxa MDR da tabela (aba "Taxas", padrão)
                </label>
                <label style="display:block;margin-top:4px;">
                    <input type="radio" name="mode[compound_rate_source]" value="custom" id="pfr-rate-source-custom"
                        <?php echo (!empty($mode['compound_rate_source']) && $mode['compound_rate_source'] === 'custom') ? 'checked' : ''; ?>>
                    Usar taxa personalizada (abaixo)
                </label>

                <div id="pfr-custom-rate-table-wrap" style="display:none;margin-top:10px;">
                    <span class="pfr-percent-wrap">
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            max="20"
                            name="mode[compound_custom_rate]"
                            value="<?php echo pagarme_fee_rates_h(isset($mode['compound_custom_rate']) ? $mode['compound_custom_rate'] : 0); ?>"
                        >
                        <span class="pfr-percent-sign" aria-hidden="true">%</span>
                    </span>
                    <p class="pfr-hint">
                        Um único valor, usado como taxa mensal composta para QUALQUER bandeira e
                        QUALQUER número de parcelas — não varia por parcela como a grade de taxas
                        MDR ou a margem fixa. Sem piso/mínimo — este valor é livre (pode ser menor
                        que o custo real do gateway sem risco, já que a diferença não é repassada
                        como prejuízo à loja).
                    </p>
                </div>
            </div>

            <div class="pfr-mode-example">
                <strong>Exemplo: R$ 1.200,00 em 12x, taxa MDR 3,82%</strong>
                <table>
                    <tr><td>Simples</td><td>total R$ 1.245,84 (3,82% aplicado uma vez)</td></tr>
                    <tr><td>Composta</td><td>total R$ 1.850,55 (3,82% ao mês, composto em 12 parcelas)</td></tr>
                    <tr><td>+ margem de exemplo (7% em 12x)</td><td>multiplica o total acima por 1,07</td></tr>
                </table>
                <p style="margin:6px 0 0;">Valores fixos de exemplo — não recalculados ao vivo conforme os campos abaixo.</p>
            </div>

            <p class="pfr-hint" style="margin-top:18px;">
                <label>
                    <input type="checkbox" name="mode[margin_enabled]" value="1" id="pfr-margin-enabled"
                        <?php echo !empty($mode['margin_enabled']) ? 'checked' : ''; ?>>
                    Adicionar margem fixa da loja (percentual extra, somado por cima do cálculo acima —
                    simples ou composto, nunca somada à taxa MDR antes do cálculo)
                </label>
            </p>

            <div id="pfr-margin-table-wrap">
                <table class="pfr-mode-table">
                    <thead>
                        <tr>
                            <?php for ($n = 1; $n <= 12; $n++): ?>
                                <th><?php echo $n; ?>x</th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <?php for ($n = 1; $n <= 12; $n++):
                                $key = (string) $n;
                                $marginValue = isset($mode['margin'][$key]) ? $mode['margin'][$key] : 0;
                            ?>
                                <td>
                                    <span class="pfr-percent-wrap">
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            max="20"
                                            name="mode[margin][<?php echo $n; ?>]"
                                            value="<?php echo pagarme_fee_rates_h($marginValue); ?>"
                                        >
                                        <span class="pfr-percent-sign" aria-hidden="true">%</span>
                                    </span>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
            </div>

            <div class="pfr-actions pfr-save-actions" style="<?php echo $activeTab === 'sim' ? 'display:none;' : ''; ?>">
                <button type="submit" name="pagarme_fee_rates_save" value="1" class="btn btn-primary">
                    Salvar
                </button>
            </div>

            <div class="pfr-tab-panel<?php echo $activeTab === 'sim' ? ' pfr-tab-active' : ''; ?>" data-tab="sim">
            <h3 class="pfr-section-title">Simulador</h3>
            <div class="pfr-sim-note">
                Esta simulação não altera nenhuma configuração salva — serve só para consulta.
                Usa as taxas MDR já gravadas na aba "Taxas".
            </div>

            <?php if ($simResult !== null && !$simResult['success']): ?>
                <div class="alert alert-danger" style="margin-top:10px;max-width:720px;">
                    <ul style="margin:0;">
                        <?php foreach ($simResult['errors'] as $simError): ?>
                            <li><?php echo pagarme_fee_rates_h($simError); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="pfr-sim-fields" style="margin-top:14px;">
                <div>
                    <label for="pfr-sim-amount">Valor (R$)</label>
                    <input
                        type="text"
                        inputmode="decimal"
                        id="pfr-sim-amount"
                        name="sim[amount]"
                        value="<?php echo pagarme_fee_rates_h(isset($simInput['amount']) ? $simInput['amount'] : ''); ?>"
                        placeholder="1200.00"
                    >
                </div>
                <div>
                    <label for="pfr-sim-brand">Bandeira</label>
                    <select id="pfr-sim-brand" name="sim[brand]">
                        <?php foreach (PAGARME_FEE_RATES_BRANDS as $brand): ?>
                            <option value="<?php echo pagarme_fee_rates_h($brand); ?>"
                                <?php echo (isset($simInput['brand']) && $simInput['brand'] === $brand) ? 'selected' : ''; ?>>
                                <?php echo pagarme_fee_rates_h(pagarme_fee_rates_brandLabel($brand)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="pfr-sim-cycle">Ciclo</label>
                    <select id="pfr-sim-cycle" name="sim[cycle]">
                        <?php foreach (pagarme_fee_rates_cycles() as $cycleKey => $cycleInfo): ?>
                            <option value="<?php echo pagarme_fee_rates_h($cycleKey); ?>"
                                <?php echo (isset($simInput['cycle']) && $simInput['cycle'] === $cycleKey) ? 'selected' : ''; ?>>
                                <?php echo pagarme_fee_rates_h($cycleInfo['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="margin-top:14px;">
                <label style="display:block;">
                    <input type="radio" name="sim[formula]" value="simple"
                        <?php echo (!isset($simInput['formula']) || $simInput['formula'] !== 'compound') ? 'checked' : ''; ?>>
                    Simples
                </label>
                <label style="display:block;margin-top:4px;">
                    <input type="radio" name="sim[formula]" value="compound" id="pfr-sim-formula-compound"
                        <?php echo (isset($simInput['formula']) && $simInput['formula'] === 'compound') ? 'checked' : ''; ?>>
                    Composta (Tabela Price)
                </label>
            </div>

            <div id="pfr-sim-compound-rate-source-wrap" style="display:none;margin-top:10px;padding-left:22px;">
                <label style="display:block;">
                    <input type="radio" name="sim[compound_rate_source]" value="mdr"
                        <?php echo (empty($simInput['compound_rate_source']) || $simInput['compound_rate_source'] !== 'custom') ? 'checked' : ''; ?>>
                    Usar taxa MDR da tabela
                </label>
                <label style="display:block;margin-top:4px;">
                    <input type="radio" name="sim[compound_rate_source]" value="custom" id="pfr-sim-rate-source-custom"
                        <?php echo (!empty($simInput['compound_rate_source']) && $simInput['compound_rate_source'] === 'custom') ? 'checked' : ''; ?>>
                    Usar taxa personalizada
                </label>

                <div id="pfr-sim-custom-rate-table-wrap" style="display:none;margin-top:10px;">
                    <span class="pfr-percent-wrap">
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            max="20"
                            name="sim[compound_custom_rate]"
                            value="<?php echo pagarme_fee_rates_h(isset($simInput['compound_custom_rate']) ? $simInput['compound_custom_rate'] : 0); ?>"
                        >
                        <span class="pfr-percent-sign" aria-hidden="true">%</span>
                    </span>
                    <p class="pfr-hint">Um único valor, usado para qualquer bandeira e parcela simulada.</p>
                </div>
            </div>

            <p class="pfr-hint" style="margin-top:14px;">
                <label>
                    <input type="checkbox" name="sim[margin_enabled]" value="1" id="pfr-sim-margin-enabled"
                        <?php echo !empty($simInput['margin_enabled']) ? 'checked' : ''; ?>>
                    Simular com margem fixa também
                </label>
            </p>

            <div id="pfr-sim-margin-table-wrap" style="display:none;margin-top:10px;">
                <table class="pfr-mode-table">
                    <thead>
                        <tr>
                            <?php for ($n = 1; $n <= 12; $n++): ?>
                                <th><?php echo $n; ?>x</th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <?php for ($n = 1; $n <= 12; $n++):
                                $key = (string) $n;
                                $simMarginValue = isset($simInput['margin'][$key]) ? $simInput['margin'][$key] : 0;
                            ?>
                                <td>
                                    <span class="pfr-percent-wrap">
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            max="20"
                                            name="sim[margin][<?php echo $n; ?>]"
                                            value="<?php echo pagarme_fee_rates_h($simMarginValue); ?>"
                                        >
                                        <span class="pfr-percent-sign" aria-hidden="true">%</span>
                                    </span>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="pfr-actions">
                <button type="submit" name="pagarme_fee_rates_simulate" value="1" class="btn">
                    Simular
                </button>
            </div>

            <?php if ($simResult !== null && $simResult['success']): ?>
                <table class="pfr-sim-results">
                    <thead>
                        <tr>
                            <th>Parcela</th>
                            <th>Taxa efetiva</th>
                            <th>Valor da parcela</th>
                            <th>Total</th>
                            <th>Valor inicial</th>
                            <th>Valor adicional de parcelamento</th>
                            <th>Taxa de serviço do gateway</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($simResult['rows'] as $row): ?>
                            <tr>
                                <td><?php echo (int) $row['installments']; ?>x</td>
                                <td class="<?php echo $row['interest_free'] ? 'pfr-sim-free' : ''; ?>">
                                    <?php echo $row['interest_free'] ? 'sem juros' : pagarme_fee_rates_h(number_format($row['rate'], 2, ',', '')) . '%'; ?>
                                </td>
                                <td>R$ <?php echo pagarme_fee_rates_h(number_format($row['installment_amount'], 2, ',', '.')); ?></td>
                                <td>R$ <?php echo pagarme_fee_rates_h(number_format($row['total'], 2, ',', '.')); ?></td>
                                <td>R$ <?php echo pagarme_fee_rates_h(number_format($row['initial_amount'], 2, ',', '.')); ?></td>
                                <td>R$ <?php echo pagarme_fee_rates_h(number_format($row['installment_fee'], 2, ',', '.')); ?></td>
                                <td>R$ <?php echo pagarme_fee_rates_h(number_format($row['gateway_service_fee'], 2, ',', '.')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="pfr-hint">
                    Ciclo <?php echo pagarme_fee_rates_h($simResult['cycleLabel']); ?>: até
                    <?php echo (int) $simResult['maxInstallments']; ?>x, sendo até
                    <?php echo (int) $simResult['freeInstallments']; ?>x sem juros.
                    "Taxa de serviço do gateway" é o custo MDR real que a Pagar.me cobra da
                    loja para a faixa de parcelas ESCOLHIDA nesta linha (ex: em 12x, usa a
                    taxa real de 7-12x), sobre o total — o mesmo valor que aparece como
                    "Taxas da Transação" na fatura real.
                </p>
            <?php endif; ?>
            </div>
        </form>
    </div>
    <script>
    (function () {
        // --- Abas ---------------------------------------------------------
        // O botão "Salvar" (fora de qualquer painel, compartilhado pelas 3
        // abas que gravam algo) fica oculto na aba "Simulador" - simular
        // nunca grava, e mostrar "Salvar" ali ao lado de "Simular" confundiria
        // qual botão faz o quê.
        var tabButtons = document.querySelectorAll('.pfr-tab-btn');
        var tabPanels = document.querySelectorAll('.pfr-tab-panel');
        var saveActions = document.querySelector('.pfr-save-actions');
        var activeTabField = document.getElementById('pfr-active-tab');
        function activateTab(target) {
            for (var j = 0; j < tabButtons.length; j++) {
                tabButtons[j].classList.toggle('pfr-tab-active', tabButtons[j].getAttribute('data-tab') === target);
            }
            for (var k = 0; k < tabPanels.length; k++) {
                tabPanels[k].classList.toggle('pfr-tab-active', tabPanels[k].getAttribute('data-tab') === target);
            }
            if (saveActions) {
                saveActions.style.display = (target === 'sim') ? 'none' : '';
            }
            // Grava qual aba está ativa no campo oculto submetido junto do
            // form - sem isso, um postback (Salvar ou Simular) sempre
            // reabriria a página na aba "Taxas" (a marcada como ativa no HTML
            // inicial), perdendo a aba em que o usuário realmente estava.
            if (activeTabField) {
                activeTabField.value = target;
            }
        }
        for (var i = 0; i < tabButtons.length; i++) {
            tabButtons[i].addEventListener('click', function () {
                activateTab(this.getAttribute('data-tab'));
            });
        }

        // --- Grade de margem: só aparece se a margem estiver ativa --------
        var marginCheckbox = document.getElementById('pfr-margin-enabled');
        var marginTableWrap = document.getElementById('pfr-margin-table-wrap');
        function syncMarginVisibility() {
            if (marginCheckbox && marginTableWrap) {
                marginTableWrap.style.display = marginCheckbox.checked ? 'block' : 'none';
            }
        }
        if (marginCheckbox) {
            marginCheckbox.addEventListener('change', syncMarginVisibility);
            syncMarginVisibility();
        }

        // --- Mesmo toggle, para a grade de margem da aba Simulador --------
        var simMarginCheckbox = document.getElementById('pfr-sim-margin-enabled');
        var simMarginTableWrap = document.getElementById('pfr-sim-margin-table-wrap');
        function syncSimMarginVisibility() {
            if (simMarginCheckbox && simMarginTableWrap) {
                simMarginTableWrap.style.display = simMarginCheckbox.checked ? 'block' : 'none';
            }
        }
        if (simMarginCheckbox) {
            simMarginCheckbox.addEventListener('change', syncSimMarginVisibility);
            syncSimMarginVisibility();
        }

        // --- Mesma hierarquia de fonte de taxa, para o Simulador -----------
        var simFormulaRadios = document.querySelectorAll('input[name="sim[formula]"]');
        var simRateSourceWrap = document.getElementById('pfr-sim-compound-rate-source-wrap');
        var simRateSourceRadios = document.querySelectorAll('input[name="sim[compound_rate_source]"]');
        var simCustomRateTableWrap = document.getElementById('pfr-sim-custom-rate-table-wrap');
        function syncSimCompoundRateSourceVisibility() {
            var formulaSelected = document.querySelector('input[name="sim[formula]"]:checked');
            var isCompound = formulaSelected && formulaSelected.value === 'compound';
            if (simRateSourceWrap) {
                simRateSourceWrap.style.display = isCompound ? 'block' : 'none';
            }
            var sourceSelected = document.querySelector('input[name="sim[compound_rate_source]"]:checked');
            var isCustom = isCompound && sourceSelected && sourceSelected.value === 'custom';
            if (simCustomRateTableWrap) {
                simCustomRateTableWrap.style.display = isCustom ? 'block' : 'none';
            }
        }
        for (var r = 0; r < simFormulaRadios.length; r++) {
            simFormulaRadios[r].addEventListener('change', syncSimCompoundRateSourceVisibility);
        }
        for (var s = 0; s < simRateSourceRadios.length; s++) {
            simRateSourceRadios[s].addEventListener('change', syncSimCompoundRateSourceVisibility);
        }
        syncSimCompoundRateSourceVisibility();

        // --- Aviso permanente quando "Composta" está selecionada ----------
        var formulaRadios = document.querySelectorAll('input[name="mode[formula]"]');
        var compoundWarning = document.getElementById('pfr-mode-compound-warning');
        function syncCompoundWarning() {
            var selected = document.querySelector('input[name="mode[formula]"]:checked');
            if (compoundWarning) {
                compoundWarning.style.display = (selected && selected.value === 'compound') ? 'block' : 'none';
            }
        }
        for (var m = 0; m < formulaRadios.length; m++) {
            formulaRadios[m].addEventListener('change', syncCompoundWarning);
        }
        syncCompoundWarning();

        // --- Sub-opção de fonte da taxa: só aparece com "Composta" ativa,
        // e a grade personalizada só aparece com "Taxa personalizada" ativa
        // dentro dela (hierarquia de 2 níveis).
        var rateSourceWrap = document.getElementById('pfr-compound-rate-source-wrap');
        var rateSourceRadios = document.querySelectorAll('input[name="mode[compound_rate_source]"]');
        var customRateTableWrap = document.getElementById('pfr-custom-rate-table-wrap');
        function syncCompoundRateSourceVisibility() {
            var formulaSelected = document.querySelector('input[name="mode[formula]"]:checked');
            var isCompound = formulaSelected && formulaSelected.value === 'compound';
            if (rateSourceWrap) {
                rateSourceWrap.style.display = isCompound ? 'block' : 'none';
            }
            var sourceSelected = document.querySelector('input[name="mode[compound_rate_source]"]:checked');
            var isCustom = isCompound && sourceSelected && sourceSelected.value === 'custom';
            if (customRateTableWrap) {
                customRateTableWrap.style.display = isCustom ? 'block' : 'none';
            }
        }
        for (var p = 0; p < formulaRadios.length; p++) {
            formulaRadios[p].addEventListener('change', syncCompoundRateSourceVisibility);
        }
        for (var q = 0; q < rateSourceRadios.length; q++) {
            rateSourceRadios[q].addEventListener('change', syncCompoundRateSourceVisibility);
        }
        syncCompoundRateSourceVisibility();

        // --- Confirmação antes de salvar -----------------------------------
        // Ativar uma promoção zera juros para TODAS as parcelas da bandeira,
        // para todo cliente, a partir do save — impacto financeiro amplo e
        // imediato. Confirmação extra só quando pelo menos uma promoção está
        // marcada como ativa no momento do submit (edição só de taxa não
        // pede confirmação, para não incomodar o uso mais comum). Mesmo
        // padrão para o modo composto: só pede confirmação se o modo estava
        // "simples" ao carregar a página e o usuário está mudando para
        // "composto" agora, não em toda gravação com composto já ativo.
        //
        // Só se aplica ao botão "Salvar" - "Simular" nunca grava nada, então
        // nunca precisa dessas confirmações. Rastreado pelo clique no botão
        // (submitter), já que o evento 'submit' por si só não diz qual botão
        // disparou o envio.
        var form = document.getElementById('pfr-form');
        if (!form) return;
        var originalFormula = <?php echo json_encode((!empty($mode['formula']) && $mode['formula'] === 'compound') ? 'compound' : 'simple'); ?>;
        var lastClickedSubmitName = null;
        form.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('button[type="submit"]') : null;
            if (btn) lastClickedSubmitName = btn.getAttribute('name');
        });

        form.addEventListener('submit', function (e) {
            if (lastClickedSubmitName !== 'pagarme_fee_rates_save') return;

            var messages = [];

            var activeBoxes = form.querySelectorAll('input[name^="promotions["][name$="][active]"]:checked');
            if (activeBoxes.length === 1) {
                messages.push('Uma promoção sem juros está marcada como ativa. Isso zera os juros de TODAS as parcelas dessa bandeira, para todo cliente, a partir de agora.');
            } else if (activeBoxes.length > 1) {
                messages.push(activeBoxes.length + ' promoções sem juros estão marcadas como ativas. Isso zera os juros de TODAS as parcelas dessas bandeiras, para todo cliente, a partir de agora.');
            }

            var selectedFormula = document.querySelector('input[name="mode[formula]"]:checked');
            if (selectedFormula && selectedFormula.value === 'compound' && originalFormula !== 'compound') {
                messages.push('Você está ativando o modo de juros COMPOSTO. Isso aumenta significativamente o valor cobrado do cliente em parcelamentos longos.');
            }

            if (messages.length === 0) return;

            if (!window.confirm(messages.join('\n\n') + '\n\nConfirmar?')) {
                e.preventDefault();
            }
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Lê e normaliza os campos do formulário de simulação ($_POST['sim']), no
 * mesmo formato que pagarme_fee_rates_simulate() espera - usado tanto para
 * rodar a simulação quanto para reexibir o formulário preenchido.
 *
 * @param mixed $postedSim
 * @return array
 */
function pagarme_fee_rates_readSimInput($postedSim)
{
    if (!is_array($postedSim)) {
        $postedSim = array();
    }

    $margin = array();
    $postedMargin = isset($postedSim['margin']) && is_array($postedSim['margin']) ? $postedSim['margin'] : array();
    for ($n = 1; $n <= 12; $n++) {
        $key = (string) $n;
        $margin[$key] = array_key_exists($key, $postedMargin) ? $postedMargin[$key] : 0;
    }

    $compoundCustomRate = isset($postedSim['compound_custom_rate']) ? $postedSim['compound_custom_rate'] : 0;

    return array(
        'amount'               => isset($postedSim['amount']) ? $postedSim['amount'] : '',
        'brand'                => isset($postedSim['brand']) ? (string) $postedSim['brand'] : 'outras',
        'cycle'                => isset($postedSim['cycle']) ? (string) $postedSim['cycle'] : 'annually',
        'formula'              => (isset($postedSim['formula']) && $postedSim['formula'] === 'compound') ? 'compound' : 'simple',
        'compound_rate_source' => (isset($postedSim['compound_rate_source']) && $postedSim['compound_rate_source'] === 'custom') ? 'custom' : 'mdr',
        'compound_custom_rate' => $compoundCustomRate,
        'margin_enabled'       => !empty($postedSim['margin_enabled']),
        'margin'               => $margin,
    );
}

/**
 * Ponto de entrada único do addon. WHMCS roteia GET e POST de
 * addonmodules.php?module=pagarme_fee_rates para esta função.
 *
 * Um único submit grava três arquivos independentes (taxas, promoções, modo
 * de cálculo), cada um com sua própria validação e seu próprio log de
 * auditoria — mas como uma única operação do ponto de vista do usuário: se
 * qualquer um dos três falhar a validação, NADA é gravado, e a página
 * reexibe os três blocos com os valores digitados e os erros de todos, para
 * o usuário corrigir tudo de uma vez.
 *
 * A aba "Simulador" é um quarto submit (`pagarme_fee_rates_simulate`),
 * tratado à parte: nunca grava nada em disco, nunca loga, e não participa do
 * tudo-ou-nada das outras três abas.
 *
 * @param array $vars
 */
function pagarme_fee_rates_output($vars)
{
    $taxesPath = pagarme_fee_rates_taxesPath();
    $floorPath = pagarme_fee_rates_floorPath();
    $promotionsPath = pagarme_fee_rates_promotionsPath();
    $modePath = pagarme_fee_rates_modePath();
    $moduleLink = isset($vars['modulelink']) ? $vars['modulelink'] : 'addonmodules.php?module=pagarme_fee_rates';

    $floorTable = pagarme_fee_rates_ensureFloor($taxesPath, $floorPath);
    $floorGrid = pagarme_fee_rates_extractGrid($floorTable);

    $isSaveAttempt = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pagarme_fee_rates_save']);
    $isSimulateAttempt = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pagarme_fee_rates_simulate']);

    // Qual aba estava selecionada no momento do submit (campo oculto
    // 'active_tab', atualizado pelo JS a cada clique de aba) - sem isto,
    // qualquer postback (Salvar ou Simular) sempre reabriria a tela na aba
    // "Taxas", perdendo onde o usuário realmente estava.
    $postedActiveTab = isset($_POST['active_tab']) ? (string) $_POST['active_tab'] : 'taxas';

    if (($isSaveAttempt || $isSimulateAttempt)
        && !pagarme_fee_rates_csrfValid(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)
    ) {
        $currentTaxes = pagarme_fee_rates_loadTable($taxesPath);
        $currentPromotions = pagarme_fee_rates_loadTable($promotionsPath);
        $currentMode = pagarme_fee_rates_loadTable($modePath);
        echo pagarme_fee_rates_renderForm(
            pagarme_fee_rates_extractGrid($currentTaxes),
            $floorGrid,
            $currentPromotions,
            $currentMode,
            pagarme_fee_rates_readSimInput(isset($_POST['sim']) ? $_POST['sim'] : array()),
            null,
            array('Sessão expirada ou formulário inválido. Recarregue a página e tente novamente.'),
            false,
            $moduleLink,
            $postedActiveTab
        );
        return;
    }

    if ($isSimulateAttempt) {
        $currentTaxes = pagarme_fee_rates_loadTable($taxesPath);
        $currentPromotions = pagarme_fee_rates_loadTable($promotionsPath);
        $currentMode = pagarme_fee_rates_loadTable($modePath);
        $renderRates = pagarme_fee_rates_extractGrid($currentTaxes);

        $simInput = pagarme_fee_rates_readSimInput(isset($_POST['sim']) ? $_POST['sim'] : array());
        $simResult = pagarme_fee_rates_simulate(
            $simInput['amount'],
            $simInput['brand'],
            $simInput['cycle'],
            $simInput['formula'],
            $simInput['compound_rate_source'],
            $simInput['compound_custom_rate'],
            $simInput['margin_enabled'],
            $simInput['margin'],
            $currentTaxes
        );

        echo pagarme_fee_rates_renderForm(
            $renderRates,
            $floorGrid,
            $currentPromotions,
            $currentMode,
            $simInput,
            $simResult,
            array(),
            false,
            $moduleLink,
            // Sempre 'sim': só se chega aqui submetendo o botão "Simular",
            // que só existe dentro do painel do Simulador.
            'sim'
        );
        return;
    }

    if ($isSaveAttempt) {
        $currentTaxes = pagarme_fee_rates_loadTable($taxesPath);
        $currentPromotions = pagarme_fee_rates_loadTable($promotionsPath);
        $currentMode = pagarme_fee_rates_loadTable($modePath);

        $taxesResult = pagarme_fee_rates_validateAndBuild(
            isset($_POST['rates']) ? $_POST['rates'] : array(),
            $currentTaxes,
            $floorTable
        );
        $promotionsResult = pagarme_fee_rates_validatePromotions(
            isset($_POST['promotions']) ? $_POST['promotions'] : array()
        );
        $modeResult = pagarme_fee_rates_validateMode(
            isset($_POST['mode']) ? $_POST['mode'] : array()
        );

        $errors = array_merge($taxesResult['errors'], $promotionsResult['errors'], $modeResult['errors']);

        if ($taxesResult['success'] && $promotionsResult['success'] && $modeResult['success']) {
            $taxesWriteOk = pagarme_fee_rates_atomicWrite($taxesPath, $taxesResult['data']);
            $promotionsWriteOk = $taxesWriteOk
                ? pagarme_fee_rates_atomicWrite($promotionsPath, $promotionsResult['data'])
                : false;
            $modeWriteOk = $promotionsWriteOk
                ? pagarme_fee_rates_atomicWrite($modePath, $modeResult['data'])
                : false;

            if ($taxesWriteOk && $promotionsWriteOk && $modeWriteOk) {
                pagarme_fee_rates_logChange($currentTaxes, $taxesResult['data']);
                pagarme_fee_rates_logPromotionChange($currentPromotions, $promotionsResult['data']);
                pagarme_fee_rates_logModeChange($currentMode, $modeResult['data']);
                $separator = strpos($moduleLink, '?') !== false ? '&' : '?';
                header('Location: ' . $moduleLink . $separator . 'saved=1');
                exit;
            }

            $errors[] = 'Falha ao gravar o arquivo. Verifique permissões de escrita em '
                . 'modules/gateways/pagarme/inc/.';
            $renderRates = pagarme_fee_rates_extractGrid($taxesResult['data']);
            $renderPromotions = $promotionsResult['data'];
            $renderMode = $modeResult['data'];
        } else {
            // Preserva exatamente o que o usuário digitou, incluindo valores inválidos,
            // para não obrigar redigitar tudo por causa de um único campo errado.
            $renderRates = isset($_POST['rates']) && is_array($_POST['rates']) ? $_POST['rates'] : array();
            $renderPromotions = isset($_POST['promotions']) && is_array($_POST['promotions'])
                ? $_POST['promotions']
                : array();
            $renderMode = isset($_POST['mode']) && is_array($_POST['mode']) ? $_POST['mode'] : array();
        }

        echo pagarme_fee_rates_renderForm(
            $renderRates,
            $floorGrid,
            $renderPromotions,
            $renderMode,
            pagarme_fee_rates_readSimInput(array()),
            null,
            $errors,
            false,
            $moduleLink,
            $postedActiveTab
        );
        return;
    }

    $currentTaxes = pagarme_fee_rates_loadTable($taxesPath);
    $currentPromotions = pagarme_fee_rates_loadTable($promotionsPath);
    $currentMode = pagarme_fee_rates_loadTable($modePath);
    $renderRates = pagarme_fee_rates_extractGrid($currentTaxes);
    echo pagarme_fee_rates_renderForm(
        $renderRates,
        $floorGrid,
        $currentPromotions,
        $currentMode,
        pagarme_fee_rates_readSimInput(array()),
        null,
        array(),
        isset($_GET['saved']),
        $moduleLink
    );
}

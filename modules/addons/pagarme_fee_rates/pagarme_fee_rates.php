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
            for ($n = 1; $n <= 12; $n++) {
                $key = (string) $n;
                $old = round((float) (isset($before[$brand]['credito'][$key]) ? $before[$brand]['credito'][$key] : 0), 2);
                $new = round((float) (isset($after[$brand]['credito'][$key]) ? $after[$brand]['credito'][$key] : 0), 2);

                if ($old !== $new) {
                    $changes[] = "{$brand} {$n}x: {$old}% -> {$new}%";
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
 * Renderiza a página (formulário + avisos). $values é a grade
 * bandeira => parcela => valor (crua, pode conter o que o usuário acabou de
 * digitar em caso de erro de validação). $floorGrid é o piso, mesma forma,
 * só leitura (nunca editável pela tela). $promotions é
 * bandeira => {active, start, end}.
 *
 * @param array $values
 * @param array $floorGrid
 * @param array $promotions
 * @param string[] $errors
 * @param bool $justSaved
 * @param string $moduleLink
 * @return string
 */
function pagarme_fee_rates_renderForm($values, $floorGrid, $promotions, $errors, $justSaved, $moduleLink)
{
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
        .pfr-cell input[type="range"] { width: 72px; }
        .pfr-cell input[type="number"] { width: 64px; text-align: center; }
        .pfr-floor-hint { font-size: 10px; color: #999; white-space: nowrap; }
        .pfr-actions { margin-top: 14px; }
        .pfr-hint { color: #666; font-size: 12px; margin-top: 6px; }
        .pfr-promo-table { border-collapse: collapse; margin-top: 10px; width: 100%; max-width: 720px; }
        .pfr-promo-table th, .pfr-promo-table td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; }
        .pfr-promo-table th { background: #f5f5f5; font-weight: 600; }
        .pfr-promo-table input[type="date"] { width: 140px; }
        .pfr-section-title { margin-top: 26px; }
    </style>
    <div class="pfr-wrap">
        <h3>Taxas MDR - Pagar.me</h3>
        <p>Percentual de taxa cobrado por bandeira e número de parcelas. Valores entre 0 e 20, nunca abaixo do mínimo (custo real do gateway).</p>

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

        <form method="post" action="<?php echo pagarme_fee_rates_h($moduleLink); ?>">
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
                                            step="0.01"
                                            min="<?php echo pagarme_fee_rates_h($floor); ?>"
                                            max="20"
                                            value="<?php echo pagarme_fee_rates_h($value !== '' ? $value : $floor); ?>"
                                            oninput="document.getElementById('<?php echo $inputId; ?>').value = this.value"
                                        >
                                        <input
                                            type="number"
                                            id="<?php echo $inputId; ?>"
                                            step="0.01"
                                            min="<?php echo pagarme_fee_rates_h($floor); ?>"
                                            max="20"
                                            name="<?php echo pagarme_fee_rates_h($inputName); ?>"
                                            value="<?php echo pagarme_fee_rates_h($value); ?>"
                                            oninput="this.previousElementSibling.value = this.value"
                                        >
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

            <h3 class="pfr-section-title">Promoções sem juros</h3>
            <p class="pfr-hint">
                Enquanto ativa, TODAS as parcelas da bandeira ficam sem juros, mesmo acima do teto
                normal do ciclo. Não altera a grade de taxas acima. Datas são opcionais: sem elas,
                a promoção vale enquanto estiver marcada como ativa.
            </p>
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

            <div class="pfr-actions">
                <button type="submit" name="pagarme_fee_rates_save" value="1" class="btn btn-primary">
                    Salvar
                </button>
            </div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Ponto de entrada único do addon. WHMCS roteia GET e POST de
 * addonmodules.php?module=pagarme_fee_rates para esta função.
 *
 * Um único submit grava dois arquivos independentes (taxas e promoções),
 * cada um com sua própria validação e seu próprio log de auditoria — mas
 * como uma única operação do ponto de vista do usuário: se qualquer um dos
 * dois falhar a validação, NADA é gravado (nem taxas nem promoções), e a
 * página reexibe os dois blocos com os valores digitados e os erros de
 * ambos, para o usuário corrigir tudo de uma vez.
 *
 * @param array $vars
 */
function pagarme_fee_rates_output($vars)
{
    $taxesPath = pagarme_fee_rates_taxesPath();
    $floorPath = pagarme_fee_rates_floorPath();
    $promotionsPath = pagarme_fee_rates_promotionsPath();
    $moduleLink = isset($vars['modulelink']) ? $vars['modulelink'] : 'addonmodules.php?module=pagarme_fee_rates';

    $floorTable = pagarme_fee_rates_ensureFloor($taxesPath, $floorPath);
    $floorGrid = pagarme_fee_rates_extractGrid($floorTable);

    $isSaveAttempt = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pagarme_fee_rates_save']);

    if ($isSaveAttempt) {
        $currentTaxes = pagarme_fee_rates_loadTable($taxesPath);
        $currentPromotions = pagarme_fee_rates_loadTable($promotionsPath);

        $taxesResult = pagarme_fee_rates_validateAndBuild(
            isset($_POST['rates']) ? $_POST['rates'] : array(),
            $currentTaxes,
            $floorTable
        );
        $promotionsResult = pagarme_fee_rates_validatePromotions(
            isset($_POST['promotions']) ? $_POST['promotions'] : array()
        );

        $errors = array_merge($taxesResult['errors'], $promotionsResult['errors']);

        if ($taxesResult['success'] && $promotionsResult['success']) {
            $taxesWriteOk = pagarme_fee_rates_atomicWrite($taxesPath, $taxesResult['data']);
            $promotionsWriteOk = $taxesWriteOk
                ? pagarme_fee_rates_atomicWrite($promotionsPath, $promotionsResult['data'])
                : false;

            if ($taxesWriteOk && $promotionsWriteOk) {
                pagarme_fee_rates_logChange($currentTaxes, $taxesResult['data']);
                pagarme_fee_rates_logPromotionChange($currentPromotions, $promotionsResult['data']);
                $separator = strpos($moduleLink, '?') !== false ? '&' : '?';
                header('Location: ' . $moduleLink . $separator . 'saved=1');
                exit;
            }

            $errors[] = 'Falha ao gravar o arquivo. Verifique permissões de escrita em '
                . 'modules/gateways/pagarme/inc/.';
            $renderRates = pagarme_fee_rates_extractGrid($taxesResult['data']);
            $renderPromotions = $promotionsResult['data'];
        } else {
            // Preserva exatamente o que o usuário digitou, incluindo valores inválidos,
            // para não obrigar redigitar tudo por causa de um único campo errado.
            $renderRates = isset($_POST['rates']) && is_array($_POST['rates']) ? $_POST['rates'] : array();
            $renderPromotions = isset($_POST['promotions']) && is_array($_POST['promotions'])
                ? $_POST['promotions']
                : array();
        }

        echo pagarme_fee_rates_renderForm($renderRates, $floorGrid, $renderPromotions, $errors, false, $moduleLink);
        return;
    }

    $currentTaxes = pagarme_fee_rates_loadTable($taxesPath);
    $currentPromotions = pagarme_fee_rates_loadTable($promotionsPath);
    $renderRates = pagarme_fee_rates_extractGrid($currentTaxes);
    echo pagarme_fee_rates_renderForm(
        $renderRates,
        $floorGrid,
        $currentPromotions,
        array(),
        isset($_GET['saved']),
        $moduleLink
    );
}

<?php
/**
 * Hook WHMCS: widget de atalho na Home do admin para o addon
 * "Pagar.me - Taxas MDR" (modules/addons/pagarme_fee_rates/).
 *
 * Só um link direto — nenhum dado é buscado aqui. O objetivo é encurtar o
 * caminho de "Aplicativos e Integrações > localizar o addon > usar o
 * aplicativo" para um clique na tela inicial do admin.
 *
 * Este widget não substitui o addon: ele só existe se
 * modules/addons/pagarme_fee_rates/pagarme_fee_rates.php estiver instalado
 * e ativado. Sem o addon ativo, o link leva a uma página de addon inativo —
 * mas o WIDGET em si continua aparecendo (ver nota sobre verificação de
 * permissão abaixo).
 *
 * Instalação: copiar este arquivo para /includes/hooks/ na raiz do WHMCS.
 * Não precisa de nenhum passo extra de ativação — hooks em includes/hooks/
 * carregam automaticamente.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!class_exists('PagarmeFeeRatesHomeWidget')) {

/**
 * Checa se o admin logado tem acesso ao addon `pagarme_fee_rates`, via a
 * API oficial de permissões de módulo do WHMCS. Qualquer falha aqui (classe
 * ausente em versões mais antigas do WHMCS, admin não resolvido, etc.)
 * degrada para "mostrar o widget assim mesmo" — o pior caso é um admin sem
 * acesso ver um link que devolve "módulo não ativado/sem permissão" ao
 * clicar, nunca uma tela em branco por causa desta checagem.
 *
 * @return bool
 */
function pagarme_fee_rates_widget_hasAccess()
{
    try {
        if (!class_exists('\WHMCS\Authentication\CurrentUser')) {
            return true;
        }

        $currentAdmin = \WHMCS\Authentication\CurrentUser::admin();
        if (!$currentAdmin || !method_exists($currentAdmin, 'getModulePermissions')) {
            return true;
        }

        $modulePermissions = $currentAdmin->getModulePermissions();
        if (!is_array($modulePermissions)) {
            return true;
        }

        // Administradores com acesso total (Full Administrator) não têm
        // restrição por módulo — lista pode vir vazia ou não conter o nome
        // exato mesmo com acesso liberado. Só bloqueia quando a lista existe
        // E claramente não contém este addon.
        if (empty($modulePermissions)) {
            return true;
        }

        return in_array('pagarme_fee_rates', $modulePermissions, true);
    } catch (\Throwable $e) {
        return true;
    }
}

/**
 * Quantas bandeiras têm promoção "sem juros" ativa agora, lendo
 * pagarme_promotions.json diretamente (mesmo motivo do resto deste
 * arquivo: isolamento do módulo de gateway, sem require). Duplica a mesma
 * janela de datas de pagarme_isPromotionActive() — se a regra de datas
 * mudar lá, atualizar aqui também.
 *
 * Nunca fatal: qualquer falha de leitura devolve 0, sem quebrar o widget.
 *
 * @return int
 */
function pagarme_fee_rates_widget_activePromotionsCount()
{
    try {
        $path = dirname(dirname(__DIR__))
            . '/modules/gateways/pagarme/inc/pagarme_promotions.json';
        if (!is_readable($path)) {
            return 0;
        }

        $decoded = json_decode(file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return 0;
        }

        $today = date('Y-m-d');
        $count = 0;
        foreach ($decoded as $promo) {
            if (empty($promo['active'])) {
                continue;
            }
            $start = isset($promo['start']) ? $promo['start'] : null;
            $end   = isset($promo['end']) ? $promo['end'] : null;
            if (!empty($start) && $today < $start) {
                continue;
            }
            if (!empty($end) && $today > $end) {
                continue;
            }
            $count++;
        }

        return $count;
    } catch (\Throwable $e) {
        return 0;
    }
}

class PagarmeFeeRatesHomeWidget extends \WHMCS\Module\AbstractWidget
{
    protected $title = 'Pagar.me - Taxas MDR';
    protected $description = 'Editar as taxas de parcelamento da Pagar.me';
    protected $weight = 100;
    protected $columns = 1;
    protected $cache = false;

    public function getData()
    {
        return array('activePromotions' => pagarme_fee_rates_widget_activePromotionsCount());
    }

    public function generateOutput($data)
    {
        $activePromotions = isset($data['activePromotions']) ? (int) $data['activePromotions'] : 0;

        $badge = '';
        if ($activePromotions > 0) {
            $label = $activePromotions === 1 ? '1 promoção ativa' : $activePromotions . ' promoções ativas';
            $badge = '<span style="display:inline-block;margin-bottom:8px;padding:2px 8px;'
                . 'border-radius:10px;background:#fff3cd;color:#856404;font-size:11px;font-weight:600;">'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                . '</span><br>';
        }

        return '<div style="padding:4px 0;">'
            . $badge
            . '<p style="margin:0 0 10px;color:#666;">'
            . 'Ajuste os percentuais de taxa por bandeira e número de parcelas.'
            . '</p>'
            . '<a class="btn btn-primary btn-sm" href="addonmodules.php?module=pagarme_fee_rates">'
            . 'Editar taxas'
            . '</a>'
            . '</div>';
    }
}

}

add_hook('AdminHomeWidgets', 1, function () {
    if (!class_exists('PagarmeFeeRatesHomeWidget') || !pagarme_fee_rates_widget_hasAccess()) {
        return;
    }

    return new PagarmeFeeRatesHomeWidget();
});

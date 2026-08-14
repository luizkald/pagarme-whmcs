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

class PagarmeFeeRatesHomeWidget extends \WHMCS\Module\AbstractWidget
{
    protected $title = 'Pagar.me - Taxas MDR';
    protected $description = 'Editar as taxas de parcelamento da Pagar.me';
    protected $weight = 100;
    protected $columns = 1;
    protected $cache = false;

    public function getData()
    {
        return array();
    }

    public function generateOutput($data)
    {
        return '<div style="padding:4px 0;">'
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

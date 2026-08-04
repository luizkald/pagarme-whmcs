<?php
/**
 * WHMCS Custom API Action: GetPagarmeInstallments
 *
 * Expõe as opções de parcelamento da Pagar.me para consumo pelos checkouts
 * headless (checkout-staycloud e staycloud-frontned). As REGRAS vivem no
 * módulo (modules/gateways/pagarme/installments.php) e NÃO devem ser
 * reimplementadas no frontend - é isso que garante que o valor exibido ao
 * cliente seja o mesmo que será cobrado.
 *
 * Instalação:
 *   1. Copiar para <WHMCS_ROOT>/includes/api/getpagarmeinstallments.php
 *   2. Registrar a action no catálogo de custom actions do projeto
 *      (staycloud-frontned/whmcs/custom-actions/custom_api_register.php)
 *   3. Liberar a permissão no papel de API usado pelos apps
 *   Sem os passos 2 e 3 a chamada retorna 403 sem mensagem útil.
 *
 * Dois modos:
 *   - invoice  (autoritativo): informe 'invoiceid'. Usa o ciclo dos serviços
 *              da fatura e o saldo real como base.
 *   - preview  (consultivo)  : informe 'billingcycle' + 'amount'. Existe porque
 *              o checkout renderiza o seletor ANTES de criar o pedido, quando
 *              ainda não há fatura. O total só vira compromisso via
 *              SetPagarmeInstallments, que revalida.
 *
 * Parâmetros:
 *   invoiceid     int    (modo invoice)
 *   billingcycle  string (modo preview) monthly|quarterly|semiannually|
 *                        annually|biennially|triennially
 *   amount        float  (modo preview) base em reais
 *   brand         string (opcional) visa|mastercard|elo|amex|hipercard|outras
 *                        Default 'outras' - a faixa mais conservadora.
 */

use WHMCS\Database\Capsule;

/**
 * Lê um parâmetro da requisição da API.
 *
 * Guardas de function_exists porque as actions da Pagar.me compartilham estes
 * helpers e podem ser carregadas na mesma requisição.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
if (!function_exists('pagarme_apiParam')) {
function pagarme_apiParam($key, $default = null)
{
    if (class_exists('\WHMCS\Application\Support\Facades\Di') && function_exists('App')) {
        // Caminho preferencial no WHMCS moderno
        try {
            $value = \App::getFromRequest($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        } catch (\Throwable $e) {
            // cai para $_REQUEST
        }
    }

    if (isset($_REQUEST[$key]) && $_REQUEST[$key] !== '') {
        return $_REQUEST[$key];
    }

    return $default;
}
}

/**
 * Carrega a lógica de parcelamento do módulo.
 *
 * @return bool
 */
if (!function_exists('pagarme_apiLoadInstallments')) {
function pagarme_apiLoadInstallments()
{
    if (function_exists('pagarme_buildInstallmentOptions')) {
        return true;
    }

    $root = defined('ROOTDIR') ? ROOTDIR : dirname(dirname(__DIR__));
    $path = $root . '/modules/gateways/pagarme/installments.php';

    if (is_readable($path)) {
        require_once $path;
        return function_exists('pagarme_buildInstallmentOptions');
    }

    return false;
}
}

try {
    if (!pagarme_apiLoadInstallments()) {
        $apiresults = array(
            'result'  => 'error',
            'message' => 'Módulo Pagar.me não encontrado (installments.php).',
        );
        return;
    }

    // Parcelamento pode estar desligado na configuração do gateway. Devolvemos
    // 'enabled: false' em vez de erro, para o frontend simplesmente não
    // renderizar o seletor sem precisar de uma segunda chamada.
    //
    // A ÚNICA fonte de verdade é o campo enableInstallments do próprio
    // gateway. Havia aqui uma checagem extra em $gw['type'] que nunca foi
    // confirmada como válida - e o log de captura de pagarme_capture() (que lê
    // o mesmo enableInstallments por outro caminho, via $params, preenchido
    // pelo WHMCS direto do banco) provou 'on' num momento em que esta action
    // devolvia false. Ou seja, aquela checagem extra é que estava sempre
    // reprovando. Removida.
    $enabled = false;
    if (function_exists('getGatewayVariables')) {
        $gw = getGatewayVariables('pagarme');
        $enabled = (!empty($gw['enableInstallments']) && $gw['enableInstallments'] === 'on');
    }

    $brand     = (string) pagarme_apiParam('brand', 'outras');
    $invoiceId = (int) pagarme_apiParam('invoiceid', 0);

    if ($invoiceId > 0) {
        $mode = 'invoice';
        $data = pagarme_installmentOptionsForInvoice($invoiceId, $brand);
    } else {
        $cycle  = (string) pagarme_apiParam('billingcycle', '');
        $amount = (float) pagarme_apiParam('amount', 0);

        if ($cycle === '' || $amount <= 0) {
            $apiresults = array(
                'result'  => 'error',
                'message' => 'Informe invoiceid, ou billingcycle e amount.',
            );
            return;
        }

        $months = pagarme_cycleToMonths($cycle);
        if ($months <= 0) {
            $apiresults = array(
                'result'  => 'error',
                'message' => 'billingcycle inválido: ' . $cycle,
            );
            return;
        }

        $mode = 'preview';
        $data = pagarme_buildInstallmentOptions($months, $amount, $brand);
    }

    $apiresults = array(
        'result'            => 'success',
        'mode'              => $mode,
        'gateway'           => 'pagarme',
        'enabled'           => $enabled,
        'invoiceid'         => $invoiceId ?: null,
        'brand'             => $data['brand'],
        'cycle_months'      => $data['cycle_months'],
        'base_amount'       => $data['base_amount'],
        'max_installments'  => $data['max_installments'],
        'free_installments' => $data['free_installments'],
        'options'           => $data['options'],
    );
} catch (\Throwable $e) {
    $apiresults = array(
        'result'  => 'error',
        'message' => 'Falha ao calcular parcelamento: ' . $e->getMessage(),
    );
}

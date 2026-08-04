<?php
/**
 * WHMCS Custom API Action: GetPagarmeInstallments
 *
 * Exposes Pagar.me installment options to headless checkouts. Calculation rules
 * live in modules/gateways/pagarme/installments.php; this action only loads and
 * returns the authoritative result.
 */

use WHMCS\Database\Capsule;

if (!function_exists('pagarme_apiParam')) {
function pagarme_apiParam($key, $default = null)
{
    if (class_exists('\WHMCS\Application\Support\Facades\Di') && function_exists('App')) {
        try {
            $value = \App::getFromRequest($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        } catch (\Throwable $e) {
            // Fall through to $_REQUEST.
        }
    }

    if (isset($_REQUEST[$key]) && $_REQUEST[$key] !== '') {
        return $_REQUEST[$key];
    }

    return $default;
}
}

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

if (!function_exists('pagarme_apiLoadGatewayFunctions')) {
function pagarme_apiLoadGatewayFunctions()
{
    if (function_exists('getGatewayVariables')) {
        return true;
    }

    $root = defined('ROOTDIR') ? ROOTDIR : dirname(dirname(__DIR__));
    $path = $root . '/includes/gatewayfunctions.php';

    if (is_readable($path)) {
        require_once $path;
    }

    return function_exists('getGatewayVariables');
}
}

if (!function_exists('pagarme_apiTruthy')) {
function pagarme_apiTruthy($value)
{
    if ($value === true || $value === 1) {
        return true;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, array('on', '1', 'true', 'yes', 'enabled'), true);
}
}

if (!function_exists('pagarme_apiInstallmentsEnabled')) {
function pagarme_apiInstallmentsEnabled()
{
    if (pagarme_apiLoadGatewayFunctions()) {
        $gw = getGatewayVariables('pagarme');
        if (is_array($gw) && array_key_exists('enableInstallments', $gw)) {
            return pagarme_apiTruthy($gw['enableInstallments']);
        }
    }

    try {
        $value = Capsule::table('tblpaymentgateways')
            ->where('gateway', 'pagarme')
            ->where('setting', 'enableInstallments')
            ->value('value');

        return pagarme_apiTruthy($value);
    } catch (\Throwable $e) {
        return false;
    }
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

    $enabled = pagarme_apiInstallmentsEnabled();
    $brand = (string) pagarme_apiParam('brand', 'outras');
    $invoiceId = (int) pagarme_apiParam('invoiceid', 0);

    if ($invoiceId > 0) {
        $mode = 'invoice';
        $data = pagarme_installmentOptionsForInvoice($invoiceId, $brand);
    } else {
        $cycle = (string) pagarme_apiParam('billingcycle', '');
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

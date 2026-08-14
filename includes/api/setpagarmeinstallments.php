<?php
/**
 * WHMCS Custom API Action: SetPagarmeInstallments
 *
 * Registra a parcela escolhida pelo cliente para uma fatura, ANTES da captura.
 *
 * Por que existe: nos checkouts headless a escolha acontece numa requisição e a
 * cobrança em outra. Sem um registro do lado do servidor, o módulo não teria
 * como saber o que o cliente escolheu e cobraria à vista em silêncio.
 *
 * A trava 'expected_total': o chamador informa o total que EXIBIU ao cliente.
 * Se o módulo calcular um total diferente (fatura alterada, tabela de taxa
 * atualizada, preview defasado), a chamada é REJEITADA e nada é cobrado. É o
 * que impede o sistema de cobrar um valor diferente do que o cliente viu.
 *
 * Instalação: ver cabeçalho de getpagarmeinstallments.php.
 *
 * Parâmetros:
 *   invoiceid      int    obrigatório
 *   installments   int    obrigatório (1..max do ciclo)
 *   expected_total float  obrigatório - total exibido ao cliente, em reais
 *   brand          string opcional - bandeira, afeta a taxa
 */

try {
    if (!function_exists('pagarme_installmentOptionsForInvoice')) {
        // getpagarmeinstallments.php não pode ser incluído aqui: além dos
        // helpers, ele EXECUTA a própria action ao ser carregado. Replicamos
        // apenas o carregamento do módulo.
        $root = defined('ROOTDIR') ? ROOTDIR : dirname(dirname(__DIR__));
        $path = $root . '/modules/gateways/pagarme/installments.php';
        if (is_readable($path)) {
            require_once $path;
        }
    }

    if (!function_exists('pagarme_installmentOptionsForInvoice')) {
        $apiresults = array(
            'result'  => 'error',
            'message' => 'Módulo Pagar.me não encontrado (installments.php).',
        );
        return;
    }

    $getParam = function ($key, $default = null) {
        if (isset($_REQUEST[$key]) && $_REQUEST[$key] !== '') {
            return $_REQUEST[$key];
        }
        return $default;
    };

    $invoiceId     = (int) $getParam('invoiceid', 0);
    $installments  = (int) $getParam('installments', 0);
    $expectedTotal = $getParam('expected_total', null);
    $brand         = (string) $getParam('brand', 'outras');

    if ($invoiceId <= 0 || $installments <= 0 || $expectedTotal === null) {
        $apiresults = array(
            'result'  => 'error',
            'message' => 'Parâmetros obrigatórios: invoiceid, installments, expected_total.',
        );
        return;
    }

    $expectedTotal = (float) $expectedTotal;
    $data = pagarme_installmentOptionsForInvoice($invoiceId, $brand);

    if ($data['base_amount'] <= 0) {
        $apiresults = array(
            'result'  => 'error',
            'code'    => 'invoice_not_payable',
            'message' => 'Fatura sem saldo a pagar.',
        );
        return;
    }

    if ($installments < 1 || $installments > $data['max_installments']) {
        $apiresults = array(
            'result'  => 'error',
            'code'    => 'installments_out_of_range',
            'message' => 'Parcelamento indisponível: máximo de ' . $data['max_installments']
                . 'x para este ciclo.',
            'max_installments' => $data['max_installments'],
        );
        return;
    }

    $chosen = null;
    foreach ($data['options'] as $option) {
        if ((int) $option['installments'] === $installments) {
            $chosen = $option;
            break;
        }
    }

    if ($chosen === null) {
        $apiresults = array(
            'result'  => 'error',
            'code'    => 'installments_out_of_range',
            'message' => 'Opção de parcelamento inválida.',
        );
        return;
    }

    // A trava. Divergência acima de um centavo NUNCA vira cobrança.
    if (abs((float) $chosen['total'] - $expectedTotal) > 0.01) {
        $apiresults = array(
            'result'          => 'error',
            'code'            => 'total_mismatch',
            'message'         => 'Os valores do parcelamento mudaram. Revise antes de pagar.',
            'expected_total'  => round($expectedTotal, 2),
            'actual_total'    => $chosen['total'],
            'installments'    => $installments,
        );
        return;
    }

    // Snapshot do modo de cálculo AO VIVO neste exato momento (o mesmo que
    // pagarme_installmentOptionsForInvoice() acabou de usar para calcular
    // $chosen['total'], validado acima contra expected_total). Persistido
    // junto da escolha para a captura usar o MESMO modo, não o que estiver
    // ativo depois - ver pagarme_installmentTotal()/pagarme_capture().
    $modeSnapshot = function_exists('pagarme_loadInstallmentMode')
        ? pagarme_loadInstallmentMode()
        : array(
            'formula' => 'simple', 'margin_enabled' => false, 'margin' => array(),
            'compound_rate_source' => 'mdr', 'compound_custom_rate' => 0.0,
        );

    $stored = pagarme_storeSelectedInstallments(
        $invoiceId,
        $installments,
        $data['base_amount'],
        $modeSnapshot['formula'],
        $modeSnapshot['margin_enabled'],
        $modeSnapshot['margin'],
        $modeSnapshot['compound_rate_source'],
        $modeSnapshot['compound_custom_rate']
    );

    if (!$stored) {
        $apiresults = array(
            'result'  => 'error',
            'message' => 'Não foi possível registrar o parcelamento.',
        );
        return;
    }

    $apiresults = array(
        'result'       => 'success',
        'invoiceid'    => $invoiceId,
        'installments' => $installments,
        'base_amount'  => $data['base_amount'],
        'fee_amount'   => $chosen['fee_amount'],
        'rate'         => $chosen['rate'],
        'total'        => $chosen['total'],
    );
} catch (\Throwable $e) {
    $apiresults = array(
        'result'  => 'error',
        'message' => 'Falha ao registrar parcelamento: ' . $e->getMessage(),
    );
}

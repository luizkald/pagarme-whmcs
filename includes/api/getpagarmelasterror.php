<?php
/**
 * WHMCS Custom API Action: GetPagarmeLastError
 *
 * Devolve o motivo (já classificado e seguro para exibir) do último erro de
 * pagamento/cadastro de cartão de um cliente na Pagar.me - ver
 * pagarme_classifyDeclineReason() em modules/gateways/pagarme.php.
 *
 * Por que existe: CapturePayment e AddPayMethod (APIs nativas da WHMCS) nunca
 * repassam o `rawdata` que o módulo de gateway devolve - só uma mensagem
 * genérica própria da WHMCS. O motivo real (CVV inválido, cartão vencido,
 * saldo insuficiente, CPF ausente no cadastro, etc.) ficava preso no Gateway
 * Log, visível só para admin. Esta action expõe de volta ao app headless
 * SÓ o motivo já traduzido (code + message em PT-BR) - nunca o JSON cru da
 * Pagar.me, nunca dado de cartão ou pessoal.
 *
 * O módulo grava o motivo em mod_pagarme_last_error (chave: clientid, TTL
 * curto) no momento da recusa; o app chama esta action logo em seguida,
 * na mesma tentativa que acabou de falhar.
 *
 * Instalação: ver cabeçalho de getpagarmeinstallments.php (mesmo processo de
 * registro + liberação no papel de API).
 *
 * Parâmetros:
 *   clientid  int  obrigatório - o app só deve pedir o motivo do cliente que
 *                  ele mesmo acabou de tentar cobrar/cadastrar (mesmo modelo
 *                  de confiança de SetPagarmeInstallments: esta action não
 *                  reconfirma sessão de cliente, é responsabilidade do
 *                  caller nunca pedir o motivo de outro cliente).
 *
 * Resposta:
 *   { "result": "success", "found": true, "code": "cvv_invalid",
 *     "message": "Código de segurança (CVV) inválido..." }
 *   ou { "result": "success", "found": false } quando não há motivo recente
 *   (nenhuma tentativa falhou nos últimos ~2 minutos, ou já foi lido antes).
 */

try {
    if (!function_exists('pagarme_ensureLastErrorTable')) {
        // installments.php (não pagarme.php): mesmo arquivo que as outras
        // duas actions já carregam, sem exigir a constante WHMCS - ver
        // getpagarmeinstallments.php. pagarme_ensureLastErrorTable() e
        // pagarme_storeLastError() moram lá por esse motivo.
        $root = defined('ROOTDIR') ? ROOTDIR : dirname(dirname(__DIR__));
        $path = $root . '/modules/gateways/pagarme/installments.php';

        if (is_readable($path)) {
            require_once $path;
        }
    }

    if (!function_exists('pagarme_ensureLastErrorTable')) {
        $apiresults = array(
            'result'  => 'error',
            'message' => 'Módulo Pagar.me não encontrado (installments.php).',
        );
        return;
    }

    $clientId = (int) (isset($_REQUEST['clientid']) ? $_REQUEST['clientid'] : 0);

    if ($clientId <= 0) {
        $apiresults = array(
            'result'  => 'error',
            'message' => 'Parâmetro obrigatório: clientid.',
        );
        return;
    }

    if (!pagarme_ensureLastErrorTable()) {
        $apiresults = array(
            'result' => 'success',
            'found'  => false,
        );
        return;
    }

    $row = \WHMCS\Database\Capsule::table('mod_pagarme_last_error')
        ->where('clientid', $clientId)
        ->where('expires_at', '>=', date('Y-m-d H:i:s'))
        ->first();

    if (!$row) {
        $apiresults = array(
            'result' => 'success',
            'found'  => false,
        );
        return;
    }

    // Lido uma vez, apaga - evita que uma tela recarregada minutos depois
    // reexiba o motivo de uma tentativa antiga como se fosse da atual.
    \WHMCS\Database\Capsule::table('mod_pagarme_last_error')
        ->where('clientid', $clientId)
        ->delete();

    $apiresults = array(
        'result'  => 'success',
        'found'   => true,
        'code'    => $row->code,
        'message' => $row->message,
    );
} catch (\Throwable $e) {
    $apiresults = array(
        'result'  => 'success',
        'found'   => false,
    );
}

<?php
/**
 * Hook WHMCS: suprime e-mails de faturas com valor total R$ 0,00
 *
 * Contexto: o WHMCS gera fatura de renovação para todo produto ativo,
 * mesmo com preço R$ 0,00 (planos gratuitos). Isso é comportamento nativo
 * do WHMCS e independe do gateway de pagamento configurado.
 *
 * SOLUÇÃO PREFERENCIAL: configurar o produto gratuito com o ciclo de
 * cobrança "Free Account" em Setup > Products/Services > [produto] >
 * Pricing. Assim o WHMCS não gera fatura alguma para ele.
 *
 * ESTE HOOK é o plano B: quando não é possível usar o ciclo "Free",
 * ele impede que o cliente receba e-mails irrelevantes sobre faturas de
 * valor zero. A fatura continua sendo gerada e o WHMCS continua marcando-a
 * como Paga automaticamente — apenas o e-mail deixa de ser enviado.
 *
 * Instalação: copiar este arquivo para /includes/hooks/ na raiz do WHMCS.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

add_hook('EmailPreSend', 1, function ($vars) {

    // Templates de e-mail que faz sentido suprimir quando o valor é zero.
    // Ajuste conforme os nomes usados no seu WHMCS
    // (Configuração > System Settings > Email Templates).
    $templatesParaSuprimir = array(
        'Invoice Created',
        'Invoice Payment Reminder',
        'First Overdue Payment Reminder',
        'Second Overdue Payment Reminder',
        'Third Overdue Payment Reminder',
    );

    if (empty($vars['messagename']) || !in_array($vars['messagename'], $templatesParaSuprimir)) {
        return;
    }

    if (empty($vars['relid'])) {
        return;
    }

    $invoice = Capsule::table('tblinvoices')
        ->where('id', $vars['relid'])
        ->first();

    if ($invoice && (float) $invoice->total == 0.00) {
        // Cancela o envio deste e-mail específico
        return array('abortsend' => true);
    }
});

<?php
/**
 * Hook WHMCS: injeta o seletor de parcelas da Pagar.me no checkout e na tela
 * de pagamento de fatura (inclusive quando o cliente usa um cartão já salvo).
 *
 * Por que um hook em vez de editar o template?
 *   Editar os arquivos do tema (ex.: Lagom / Smart Order Form) é frágil - as
 *   alterações somem quando o tema é atualizado. Este hook injeta o campo via
 *   JavaScript, funciona em qualquer tema e sobrevive a atualizações.
 *
 * Cobre dois cenários:
 *   1. Cartão novo digitado  -> âncora no campo input[name="ccnumber"]
 *   2. Cartão já salvo (token) -> âncora no formulário de pagamento da fatura
 *      (viewinvoice.php), onde não há campo de número de cartão
 *
 * O teto de 5x é fixo e sem juros. Quem decide se o parcelamento é realmente
 * permitido (parcelamento habilitado, plano anual) é o módulo, no servidor -
 * mesmo que o cliente veja as opções, cobranças fora das regras caem para 1x.
 *
 * Instalação: copiar para /includes/hooks/ na raiz do WHMCS.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

add_hook('ClientAreaFooterOutput', 1, function ($vars) {

    // Injeta nas páginas de carrinho/checkout e de visualização de fatura
    $selfUrl = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';
    $reqUri  = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $alvo    = array('cart.php', 'viewinvoice.php');

    $ok = false;
    foreach ($alvo as $pagina) {
        if (strpos($selfUrl, $pagina) !== false || strpos($reqUri, $pagina) !== false) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        return '';
    }

    // Máximo de parcelas (espelha PAGARME_MAX_INSTALLMENTS no módulo)
    $maxInstallments = 5;

    return <<<HTML
<script>
(function () {
    var MAX_INSTALLMENTS = {$maxInstallments};

    function criarSelect() {
        var wrapper = document.createElement('div');
        wrapper.className = 'form-group';
        wrapper.id = 'pagarme-installments-wrapper';
        wrapper.style.marginTop = '12px';

        var label = document.createElement('label');
        label.setAttribute('for', 'pagarme_installments');
        label.textContent = 'Parcelas';
        label.style.display = 'block';

        var select = document.createElement('select');
        select.name = 'pagarme_installments';
        select.id = 'pagarme_installments';
        select.className = 'form-control';

        for (var n = 1; n <= MAX_INSTALLMENTS; n++) {
            var opt = document.createElement('option');
            opt.value = n;
            opt.textContent = (n === 1) ? 'À vista' : (n + 'x sem juros');
            select.appendChild(opt);
        }

        var hint = document.createElement('small');
        hint.className = 'text-muted';
        hint.textContent = 'Parcelamento em até ' + MAX_INSTALLMENTS + 'x sem juros, disponível para planos anuais.';

        wrapper.appendChild(label);
        wrapper.appendChild(select);
        wrapper.appendChild(document.createElement('br'));
        wrapper.appendChild(hint);
        return wrapper;
    }

    function jaInjetado() {
        return !!document.getElementById('pagarme_installments');
    }

    // Cenário 1: cartão novo digitado (campo ccnumber presente)
    function injetarPorCartaoNovo() {
        var cc = document.querySelector('input[name="ccnumber"]');
        if (!cc) return false;

        var form = cc.closest('form');
        if (!form) return false;

        var host = cc.closest('.form-group') || cc.parentNode;
        var wrapper = criarSelect();
        if (host && host.parentNode) {
            host.parentNode.insertBefore(wrapper, host.nextSibling);
        } else {
            form.appendChild(wrapper);
        }
        return true;
    }

    // Cenário 2: pagamento de fatura com cartão salvo (viewinvoice.php).
    // Procuramos o formulário de pagamento e inserimos o seletor dentro dele,
    // para que o valor seja enviado junto ao submeter.
    function injetarPorFaturaSalva() {
        // Só age em páginas de fatura
        if (location.href.indexOf('viewinvoice.php') === -1) return false;

        // Âncoras comuns: botão de pagar ou select de método de pagamento
        var btn = document.querySelector(
            '#btnPayNow, input[name="paymentmethod"], select[name="paymentmethod"], .btn-pay-now, button[type="submit"][name="paynow"]'
        );

        var form = null;
        if (btn) form = btn.closest('form');
        if (!form) {
            // fallback: primeiro form que aponte para viewinvoice
            var forms = document.querySelectorAll('form');
            for (var i = 0; i < forms.length; i++) {
                var action = (forms[i].getAttribute('action') || '').toLowerCase();
                if (action.indexOf('viewinvoice') !== -1 || forms[i].querySelector('[name="invoiceid"]')) {
                    form = forms[i];
                    break;
                }
            }
        }
        if (!form) return false;

        var wrapper = criarSelect();
        // Insere antes do botão de pagar, se houver
        if (btn && btn.parentNode && form.contains(btn)) {
            btn.parentNode.insertBefore(wrapper, btn);
        } else {
            form.appendChild(wrapper);
        }
        return true;
    }

    function injetar() {
        if (jaInjetado()) return;
        if (injetarPorCartaoNovo()) return;
        injetarPorFaturaSalva();
    }

    if (document.readyState !== 'loading') {
        injetar();
    }
    document.addEventListener('DOMContentLoaded', injetar);

    // O checkout/tela de fatura do Lagom recarrega trechos via AJAX
    var observer = new MutationObserver(function () {
        injetar();
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
})();
</script>
HTML;
});

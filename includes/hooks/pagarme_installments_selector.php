<?php
/**
 * Hook WHMCS: injeta o seletor de parcelas da Pagar.me no checkout.
 *
 * Por que um hook em vez de editar o template?
 *   O seletor precisa aparecer na tela de pagamento, mas editar os arquivos
 *   do tema (ex.: Lagom / Smart Order Form) é frágil - as alterações somem
 *   quando o tema é atualizado. Este hook injeta o campo via JavaScript,
 *   funciona em qualquer tema e sobrevive a atualizações.
 *
 * Como funciona:
 *   - Roda no rodapé das páginas de carrinho/checkout (cart.php).
 *   - Localiza o campo padrão de número de cartão (input[name="ccnumber"]).
 *   - Insere logo abaixo um <select name="pagarme_installments"> com 1x a 5x,
 *     DENTRO do mesmo <form>, para que o valor seja enviado na submissão.
 *   - Usa MutationObserver porque o checkout do Lagom carrega via AJAX.
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

    // Injeta apenas nas páginas de carrinho/checkout
    $selfUrl = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';
    $reqUri  = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

    if (strpos($selfUrl, 'cart.php') === false && strpos($reqUri, 'cart.php') === false) {
        return '';
    }

    // Máximo de parcelas (espelha PAGARME_MAX_INSTALLMENTS no módulo)
    $maxInstallments = 5;

    return <<<HTML
<script>
(function () {
    var MAX_INSTALLMENTS = {$maxInstallments};

    function injetarSeletor() {
        var cc = document.querySelector('input[name="ccnumber"]');
        if (!cc) {
            return; // não é a etapa de cartão
        }
        if (document.getElementById('pagarme_installments')) {
            return; // já injetado
        }

        var form = cc.closest('form');
        if (!form) {
            return; // sem form, o valor não seria enviado
        }

        var wrapper = document.createElement('div');
        wrapper.className = 'form-group';
        wrapper.id = 'pagarme-installments-wrapper';
        wrapper.style.marginTop = '12px';

        var label = document.createElement('label');
        label.setAttribute('for', 'pagarme_installments');
        label.textContent = 'Parcelas';

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

        // Insere logo após o grupo do campo de número do cartão
        var host = cc.closest('.form-group') || cc.parentNode;
        if (host && host.parentNode) {
            host.parentNode.insertBefore(wrapper, host.nextSibling);
        } else {
            form.appendChild(wrapper);
        }
    }

    if (document.readyState !== 'loading') {
        injetarSeletor();
    }
    document.addEventListener('DOMContentLoaded', injetarSeletor);

    // O checkout do Lagom recarrega trechos via AJAX; reavaliamos a cada mudança
    var observer = new MutationObserver(function () {
        injetarSeletor();
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
})();
</script>
HTML;
});

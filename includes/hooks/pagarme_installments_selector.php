<?php
/**
 * Hook WHMCS: seletor de parcelamento da Pagar.me (Caminho B - com juros).
 *
 * Espelha o comportamento do módulo Cielo, porém para o gateway Pagar.me.
 * Teto de parcelas e faixa sem juros por ciclo:
 *   - Mensal      : só 1x (à vista)
 *   - Trimestral  : até 3x, todas com juros (só 1x é sem juros)
 *   - Semestral   : até 6x; 1x-3x sem juros, 4x-6x com juros
 *   - Anual       : até 12x; 1x-5x sem juros, 6x-12x com juros
 *   - Bienal      : até 12x; 1x-5x sem juros, 6x-12x com juros
 *   - Trienal     : até 12x; 1x-6x sem juros, 7x-12x com juros
 *   - Juros (taxa MDR da Pagar.me, SEM margem da loja) repassado ao comprador
 *     acima da faixa sem juros, exibindo o valor de cada parcela
 *   - Posta o campo 'pagarme_installments'
 *
 * Coexiste com a injeção da Cielo: cada uma ativa apenas quando o SEU gateway
 * está selecionado (GATEWAY_KEY).
 *
 * ESCOPO: este hook só roda na área do cliente do WHMCS. Os checkouts headless
 * (checkout-staycloud e staycloud-frontned) consomem a API e nunca o executam -
 * lá o parcelamento vem de GetPagarmeInstallments.
 *
 * FONTE DAS REGRAS: este hook CARREGA `modules/gateways/pagarme/installments.php`
 * e deriva dele o teto e a faixa sem juros de cada ciclo. Não escreva esses
 * valores à mão aqui - divergir do módulo faz o cliente ver um valor e ser
 * cobrado outro. Ao JavaScript resta só a aritmética de exibição.
 *
 * Instalação: copiar para /includes/hooks/ na raiz do WHMCS.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

add_hook('ClientAreaFooterOutput', 1, function ($vars) {

    // Chave do gateway Pagar.me. É o valor de paymentmethod no WHMCS.
    $gatewayKey = 'pagarme';

    // Injeta apenas em páginas onde há pagamento (carrinho, fatura, addfunds)
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $paginas = array('cart.php', 'viewinvoice.php', '/invoice/', '/store/', '/order', 'addfunds');
    $ok = false;
    foreach ($paginas as $p) {
        if (strpos($uri, $p) !== false) { $ok = true; break; }
    }
    if (!$ok) {
        return '';
    }

    // Carrega a lógica do módulo. Sem isto, este hook precisaria reimplementar
    // as regras de parcelamento em PHP E em JavaScript - três cópias no total,
    // livres para divergirem entre si sem ninguém perceber.
    $moduleRoot = dirname(dirname(__DIR__)) . '/modules/gateways/pagarme';
    if (!function_exists('pagarme_maxInstallmentsForMonths')
        && is_readable($moduleRoot . '/installments.php')) {
        require_once $moduleRoot . '/installments.php';
    }

    // Sem o módulo não há como calcular nada de forma confiável; melhor não
    // renderizar seletor do que exibir um valor que não será o cobrado.
    if (!function_exists('pagarme_maxInstallmentsForMonths')) {
        return '';
    }

    // Carrega a tabela de taxas server-side (evita fetch bloqueado por .htaccess).
    // A margem da loja NÃO é mais usada - ver pagarme_customerRate().
    $incPath = $moduleRoot . '/inc';

    $feesJson = '{}';
    $f = $incPath . '/pagarme_credit_card_taxes.json';
    if (is_readable($f)) {
        $d = json_decode(file_get_contents($f), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($d)) {
            $feesJson = json_encode($d, JSON_UNESCAPED_UNICODE);
        }
    }

    // Promoções "sem juros" por bandeira (configuradas pelo addon de admin
    // pagarme_fee_rates). Calculado aqui via pagarme_isPromotionActive() -
    // mesma função que pagarme_customerRate() usa para a cobrança real -
    // para não reimplementar a janela de datas em JavaScript. Sem isto, o
    // seletor mostraria juros durante uma promoção ativa, embora a cobrança
    // real (e o preview de GetPagarmeInstallments) já estivesse sem juros.
    $promotionsActive = array();
    if (function_exists('pagarme_isPromotionActive')) {
        foreach (array('visa', 'mastercard', 'elo', 'amex', 'outras') as $b) {
            try {
                $promotionsActive[$b] = pagarme_isPromotionActive($b);
            } catch (\Throwable $e) {
                $promotionsActive[$b] = false;
            }
        }
    }
    $promotionsJson = json_encode($promotionsActive, JSON_UNESCAPED_UNICODE);

    // Teto e faixa sem juros por ciclo, DERIVADOS DO MÓDULO em vez de repetidos
    // em JavaScript. Antes eram duas tabelas escritas à mão aqui, que podiam
    // divergir das regras reais sem ninguém perceber - e divergir significa
    // exibir um valor e cobrar outro.
    $cycleRules = array();
    if (function_exists('pagarme_cycleToMonths')) {
        foreach (array(
            'free'          => 0,
            'onetime'       => 12,
            'monthly'       => 1,
            'quarterly'     => 3,
            'semiannually'  => 6,
            'semi-annually' => 6,
            'annually'      => 12,
            'biennially'    => 24,
            'triennially'   => 36,
        ) as $cycle => $months) {
            $cycleRules[$cycle] = array(
                'max'  => $months > 0 ? pagarme_maxInstallmentsForMonths($months) : 1,
                'free' => $months > 0 ? pagarme_freeInstallmentsForMonths($months) : 1,
            );
        }
    }
    $cycleRulesJson = json_encode($cycleRules, JSON_UNESCAPED_UNICODE);

    // Máximo de parcelas e faixa sem juros server-side quando é página de
    // fatura (consulta ciclo)
    $serverMax  = 0;
    $serverFree = 0;
    $invoiceId  = 0;
    if (preg_match('#/invoice/(\d+)#', $uri, $mm)) {
        $invoiceId = (int) $mm[1];
    } elseif (preg_match('#viewinvoice\.php.*[?&]id=(\d+)#', $uri, $mm)) {
        $invoiceId = (int) $mm[1];
    }
    if ($invoiceId > 0) {
        // Direto do módulo — mesma fonte que o _capture usa para cobrar.
        try {
            $serverMax  = (int) pagarme_maxInstallmentsForInvoice($invoiceId);
            $serverFree = (int) pagarme_freeInstallmentsForInvoice($invoiceId);
        } catch (\Throwable $e) {
            $serverMax = 0;
            $serverFree = 0;
        }
    }

    return <<<HTML
<script>
(function () {
    var GATEWAY_KEY = '{$gatewayKey}';
    var SERVER_MAX = {$serverMax};
    var SERVER_FREE = {$serverFree};
    var FEES = {$feesJson};
    // Teto e faixa sem juros por ciclo, gerados pelo módulo PHP. Não editar
    // valores aqui: a fonte é pagarme_maxInstallmentsForMonths() /
    // pagarme_freeInstallmentsForMonths().
    var CYCLE_RULES = {$cycleRulesJson};
    // Promoções "sem juros" ativas agora, por bandeira. Gerado por
    // pagarme_isPromotionActive() no PHP - não reimplementar a janela de
    // datas aqui.
    var PROMOTIONS = {$promotionsJson};

    function fmt(v){ return v.toFixed(2).replace('.', ','); }
    function parseMoney(t){
        if(!t) return 0;
        t = t.replace(/[^\d,.-]/g,'');
        if(/,/.test(t)&&/\./.test(t)) t=t.replace(/\./g,'').replace(',','.');
        else if(/,/.test(t)) t=t.replace(',','.');
        var n=parseFloat(t); return isNaN(n)?0:n;
    }

    // Faixa sem juros por ciclo (meses do billingcycle). Espelha
    // pagarme_freeInstallmentsForMonths() do módulo PHP: mensal/trimestral

    function detectBrand(num){
        num=(num||'').replace(/\D/g,'');
        if(!num) return 'outras';
        if(/^4/.test(num)) return 'visa';
        if(/^(5[1-5]|2[2-7])/.test(num)) return 'mastercard';
        if(/^3[47]/.test(num)) return 'amex';
        if(/^(606282|3841)/.test(num)) return 'hipercard';
        if(/^(636368|438935|504175|451416|636297|5067|4576|4011|506699|6363)/.test(num)) return 'elo';
        return 'outras';
    }

    function detectBrandFromSaved(){
        var checked=document.querySelector('.cc-item input[type="radio"]:checked, input[name="'+GATEWAY_KEY+'"]:checked');
        if(!checked) return null;
        var item=checked.closest('.cc-item, .payment-method-item, li, tr, label');
        if(!item) return null;
        var raw=item.textContent.toLowerCase();
        if(raw.indexOf('visa')>=0) return 'visa';
        if(raw.indexOf('master')>=0) return 'mastercard';
        if(raw.indexOf('amex')>=0||raw.indexOf('american')>=0) return 'amex';
        if(raw.indexOf('elo')>=0) return 'elo';
        if(raw.indexOf('hiper')>=0) return 'hipercard';
        return null;
    }

    function currentBrand(){
        var s=detectBrandFromSaved();
        if(s) return s;
        var inp=document.querySelector('input[name="ccnumber"]');
        if(inp&&inp.value) return detectBrand(inp.value);
        return 'outras';
    }

    // Espelha pagarme_customerRate(): promoção ativa zera antes de qualquer
    // outra coisa, senão só a taxa MDR, sem margem da loja. Qualquer
    // divergência aqui faz o cliente ver um valor e ser cobrado outro.
    function getRate(brand, n, free){
        if(PROMOTIONS[brand]) return 0;
        if(n<=free) return 0;
        var bt=(FEES[brand]&&FEES[brand].credito)||(FEES.outras&&FEES.outras.credito)||{};
        return parseFloat(bt[n])||0;
    }

    function getTotal(){
        var el=document.getElementById('totalDueToday')||document.getElementById('cartTotalAmount')
            ||document.querySelector('.price-left-h .price-amount.amt');
        if(el){ var n=parseMoney(el.dataset.pagarmeOrig||el.textContent); if(n>0) return n; }
        return 0;
    }

    function isPagarmeSelected(){
        var radio=document.querySelector('input[name="payment_method"][value="'+GATEWAY_KEY+'"], input[name="paymentmethod"][value="'+GATEWAY_KEY+'"]');
        if(radio) return radio.checked;
        // Na tela de pagamento da fatura (Caixa) não há radio de gateway; a
        // presença dos cartões salvos da Pagar.me (name="pagarme") ou do painel
        // do gateway indica que a Pagar.me é o gateway ativo.
        if(document.querySelector('input[name="'+GATEWAY_KEY+'"]')) return true;
        if(document.querySelector('#mg-gateway-form-'+GATEWAY_KEY)) return true;
        return false;
    }

    // Nome do ciclo em pt-BR -> [chave de ciclo, meses]. Os meses servem para
    // eleger o ciclo mais restritivo quando o resumo lista vários.
    var CYCLE_LABELS = [
        ['mensal', 'monthly', 1], ['trimestral', 'quarterly', 3],
        ['semestral', 'semiannually', 6], ['trienal', 'triennially', 36],
        ['bienal', 'biennially', 24], ['anual', 'annually', 12]
    ];

    // Ciclo lido do Resumo do Pedido. O Lagom (Vue) não renderiza campo
    // billingcycle quando o produto tem um único ciclo, então o nome exibido
    // ali é a única fonte no DOM.
    function cycleFromSummary(){
        var box=document.querySelector('.order-summary .summary-list-recurring')
            ||document.querySelector('.order-summary');
        if(!box) return '';
        var txt=(box.textContent||'').toLowerCase();
        // 'anual' é substring de 'bienal'/'trienal': remove os compostos antes.
        var scan=txt.replace(/trienal/g,' ').replace(/bienal/g,' ');
        var best='', bestMonths=0;
        for(var i=0;i<CYCLE_LABELS.length;i++){
            var label=CYCLE_LABELS[i][0];
            var hay=(label==='anual')?scan:txt;
            if(hay.indexOf(label)===-1) continue;
            var months=CYCLE_LABELS[i][2];
            if(!best||months<bestMonths){ best=CYCLE_LABELS[i][1]; bestMonths=months; }
        }
        return best;
    }

    function currentCycleValue(){
        var cycleEl=document.querySelector('input[name="billingcycle"]:checked, select[name="billingcycle"]');
        if(cycleEl) return (cycleEl.value||'').toLowerCase();
        return cycleFromSummary();
    }

    // Numa fatura, SERVER_* vem do próprio módulo e é autoritativo. No carrinho
    // ainda não há fatura, então o ciclo é lido do DOM e resolvido pela tabela
    // que o PHP gerou.
    function cycleRule(key, fallback){
        if(!CYCLE_RULES) return fallback;
        var rule=CYCLE_RULES[currentCycleValue()];
        return (rule && rule[key]!==undefined) ? rule[key] : fallback;
    }

    function maxAllowed(){
        if(SERVER_MAX>0) return SERVER_MAX;
        return cycleRule('max', 1);
    }

    function freeAllowed(){
        if(SERVER_FREE>0) return SERVER_FREE;
        return cycleRule('free', 1);
    }

    function setInstallmentValue(v){
        v=String(parseInt(v,10)||1);
        try{ document.cookie='pagarme_installments='+v+'; path=/; max-age=900; SameSite=Lax'; }catch(e){}
        var visual=document.getElementById('pagarme_installments');
        if(!visual) return;
        var form=visual.closest?visual.closest('form'):null;
        if(!form){ var fs=document.getElementsByTagName('form'); if(fs.length) form=fs[0]; }
        if(!form) return;
        var hidden=form.querySelector('input[type="hidden"][name="pagarme_installments"]');
        if(!hidden){
            hidden=document.createElement('input');
            hidden.type='hidden'; hidden.name='pagarme_installments';
            form.appendChild(hidden);
        }
        hidden.value=v;
    }

    function buildOrUpdate(){
        if(!isPagarmeSelected()){ removeIt(); return; }
        var total=getTotal();
        var maxInst=maxAllowed();
        var free=freeAllowed();
        var brand=currentBrand();

        var sel=document.getElementById('pagarme_installments');
        if(!sel){
            var container=findContainer();
            if(!container) return;
            var wrapper=document.createElement('div');
            wrapper.id='pagarme_installment_wrapper';
            wrapper.className='form-group';
            wrapper.style.marginTop='14px';
            var label=document.createElement('label');
            label.className='control-label';
            label.textContent='Parcelamento';
            label.style.display='block';
            sel=document.createElement('select');
            sel.id='pagarme_installments';
            sel.name='pagarme_installments';
            sel.className='form-control';
            sel.addEventListener('change',function(){ setInstallmentValue(sel.value); });
            wrapper.appendChild(label);
            wrapper.appendChild(sel);
            container.appendChild(wrapper);
        }

        // (Re)preenche as opções conforme total/bandeira/max
        var prev=sel.value;
        sel.innerHTML='';
        for(var n=1;n<=maxInst;n++){
            var rate=getRate(brand,n,free);
            var perInst=(total>0)?(total*(1+rate/100)/n):0;
            var opt=document.createElement('option');
            opt.value=n;
            if(n===1){
                opt.textContent='À vista' + (total>0?(' - R$ '+fmt(total)):'');
            } else if(rate===0){
                opt.textContent=n+'x de R$ '+fmt(perInst)+' sem juros';
            } else {
                opt.textContent=n+'x de R$ '+fmt(perInst)+' (com juros)';
            }
            sel.appendChild(opt);
        }
        if(prev && parseInt(prev,10)<=maxInst){ sel.value=prev; }
        setInstallmentValue(sel.value);
    }

    function findContainer(){
        // Painel do gateway no order form
        var panel=document.querySelector('#mg-gateway-form-'+GATEWAY_KEY);
        if(panel && panel.offsetParent!==null) return panel;
        // Cartão novo digitado
        var cc=document.querySelector('input[name="ccnumber"]');
        if(cc){ var g=cc.closest('.form-group')||cc.parentNode; return g?g.parentNode:null; }
        // Tela Caixa (fatura) com cartão salvo: ancora no campo CVV
        var cvv=document.querySelector('input[name="cccvv"], input[name="cvv"], input[name="ccv"]');
        if(cvv){ var gc=cvv.closest('.form-group')||cvv.parentNode; return gc?(gc.parentNode||gc):cvv.parentNode; }
        // Lista de cartões salvos
        var ccList=document.querySelector('.cc-input-container, .cc-list');
        if(ccList) return ccList.parentNode||ccList;
        // Botão de pagar
        var btn=document.querySelector('#btnPayNow, .btn-pay-now, button[name="paynow"]');
        if(btn){ var f=btn.closest('form'); if(f) return f; }
        return null;
    }

    function removeIt(){
        var w=document.getElementById('pagarme_installment_wrapper');
        if(w) w.remove();
    }

    var scheduled=false;
    function schedule(){
        if(scheduled) return; scheduled=true;
        setTimeout(function(){ scheduled=false; buildOrUpdate(); },120);
    }

    document.addEventListener('input',function(e){
        if(e.target&&e.target.matches&&e.target.matches('input[name="ccnumber"]')) schedule();
    });
    document.addEventListener('change',function(e){
        if(!e.target) return;
        if(e.target.name==='payment_method'||e.target.name==='paymentmethod'||e.target.name==='billingcycle') schedule();
        if(e.target.matches&&e.target.matches('.cc-item input[type="radio"]')) schedule();
    });
    document.addEventListener('submit',function(){
        var sel=document.getElementById('pagarme_installments');
        if(sel) setInstallmentValue(sel.value);
    },true);

    // Interceptação do XHR do Lagom Order Form: ele serializa apenas campos
    // pré-definidos e descarta selects/hidden/radios customizados. Injetamos
    // no corpo do POST: (a) a seleção de cartão salvo e (b) o parcelamento,
    // quando o gateway é a Pagar.me. Espelha a técnica do módulo Cielo.
    (function interceptXHR(){
        var origSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function(body){
            try {
                if (typeof body === 'string' &&
                    (body.indexOf('paymentmethod=' + GATEWAY_KEY) !== -1 ||
                     body.indexOf('payment_method=' + GATEWAY_KEY) !== -1)) {

                    var panel = document.querySelector('#mg-gateway-form-' + GATEWAY_KEY) || document;

                    // (a) Toggle "novo" x "existente". O Lagom (Vue) marca a aba
                    // ativa pela classe .active no <li>, não pelo :checked do radio.
                    var cardInfoInput =
                        (panel.querySelector('.nav-payment .nav-item.active input[name="cardInfo"]'))
                        || document.querySelector('.nav-item.active input[name="cardInfo"]')
                        || document.querySelector('input[name="cardInfo"]:checked');
                    var cardInfoVal = cardInfoInput ? cardInfoInput.value : 'existing';
                    if (body.indexOf('cardInfo=') === -1) {
                        body += '&cardInfo=' + encodeURIComponent(cardInfoVal);
                    }

                    // (b) Cartão salvo: o selecionado tem a classe .active no
                    // .cc-item (Vue), então detectamos por aí, com fallback :checked.
                    if (cardInfoVal !== 'new') {
                        var savedInput =
                            (panel.querySelector('.cc-item.active input[name="' + GATEWAY_KEY + '"]'))
                            || panel.querySelector('input[name="' + GATEWAY_KEY + '"]:checked');

                        if (savedInput && savedInput.value &&
                            body.indexOf(encodeURIComponent(GATEWAY_KEY) + '=') === -1) {
                            body += '&' + encodeURIComponent(GATEWAY_KEY) + '=' + encodeURIComponent(savedInput.value);
                        }
                    }

                    // (c) Parcelamento
                    var v = null;
                    var sel = document.getElementById('pagarme_installments');
                    if (sel && sel.value) {
                        v = sel.value;
                    }
                    if (!v) {
                        var mch = document.cookie.match(/(?:^|;\s*)pagarme_installments=(\d+)/);
                        if (mch) v = mch[1];
                    }
                    if (v && body.indexOf('pagarme_installments=') === -1) {
                        body += '&pagarme_installments=' + encodeURIComponent(v);
                    }
                }
            } catch (e) {}
            return origSend.call(this, body);
        };
    })();

    var obs=new MutationObserver(schedule);
    if(document.body){ obs.observe(document.body,{childList:true,subtree:true}); schedule(); }
    else document.addEventListener('DOMContentLoaded',function(){ obs.observe(document.body,{childList:true,subtree:true}); schedule(); });
})();
</script>
HTML;
});

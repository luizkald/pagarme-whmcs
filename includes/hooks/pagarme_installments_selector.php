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
 *   - Juros (taxa da adquirente + margem) repassado ao comprador acima da
 *     faixa sem juros, exibindo o valor de cada parcela e a taxa total
 *   - Posta o campo 'pagarme_installments' (com fallback por cookie, pois o
 *     Lagom serializa apenas campos pré-definidos)
 *
 * Coexiste com a injeção da Cielo: cada uma ativa apenas quando o SEU gateway
 * está selecionado (GATEWAY_KEY).
 *
 * IMPORTANTE: as taxas em inc/pagarme_credit_card_taxes.json são um CLONE das
 * taxas da Cielo (placeholder). Trocar pelas taxas reais da Pagar.me.
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

    // É a montagem do pedido (order form do Lagom) e não uma fatura? Só nela o
    // cartão salvo precisa ser ocultado - ver bloco IS_ORDER_FORM no JS abaixo.
    $isOrderForm = false;
    foreach (array('cart.php', '/store/', '/order') as $p) {
        if (strpos($uri, $p) !== false) { $isOrderForm = true; break; }
    }
    foreach (array('viewinvoice.php', '/invoice/') as $p) {
        if (strpos($uri, $p) !== false) { $isOrderForm = false; break; }
    }
    $isOrderFormJs = $isOrderForm ? 'true' : 'false';

    // Carrega tabelas de taxa/margem server-side (evita fetch bloqueado por .htaccess)
    $incPath = dirname(dirname(__DIR__)) . '/modules/gateways/pagarme/inc';

    $feesJson = '{}';
    $f = $incPath . '/pagarme_credit_card_taxes.json';
    if (is_readable($f)) {
        $d = json_decode(file_get_contents($f), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($d)) {
            $feesJson = json_encode($d, JSON_UNESCAPED_UNICODE);
        }
    }

    $marginsJson = '{}';
    $m = $incPath . '/stay_margins.json';
    if (is_readable($m)) {
        $d = json_decode(file_get_contents($m), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($d)) {
            $marginsJson = json_encode($d, JSON_UNESCAPED_UNICODE);
        }
    }

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
    if ($invoiceId > 0 && function_exists('pagarme_maxInstallmentsForInvoice')) {
        $serverMax  = (int) pagarme_maxInstallmentsForInvoice($invoiceId);
        $serverFree = (int) pagarme_freeInstallmentsForInvoice($invoiceId);
    } elseif ($invoiceId > 0) {
        // Fallback: replica o cálculo sem depender do módulo estar incluído
        try {
            $inv = localAPI('GetInvoice', array('invoiceid' => $invoiceId));
            $cycleMap = array('monthly'=>1,'quarterly'=>3,'semiannually'=>6,'semi-annually'=>6,'annually'=>12,'biennially'=>24,'triennially'=>36);
            $minM = 0; $found = false;
            if (!empty($inv['items']['item'])) {
                $items = $inv['items']['item'];
                if (isset($items['id'])) $items = array($items);
                foreach ($items as $it) {
                    $relid = isset($it['relid']) ? (int)$it['relid'] : 0;
                    if ($relid <= 0) continue;
                    $table = (isset($it['type']) && $it['type']==='Addon') ? 'tblhostingaddons' : 'tblhosting';
                    $svc = \WHMCS\Database\Capsule::table($table)->where('id',$relid)->first();
                    if ($svc && !empty($svc->billingcycle)) {
                        $mo = isset($cycleMap[strtolower($svc->billingcycle)]) ? $cycleMap[strtolower($svc->billingcycle)] : 0;
                        if ($mo>0) { $found=true; if($minM===0||$mo<$minM)$minM=$mo; }
                    }
                }
            }
            if ($found) {
                // Teto de parcelas por ciclo
                if ($minM<=1) $serverMax=1; elseif($minM<=3)$serverMax=3; elseif($minM<=6)$serverMax=6; else $serverMax=12;
                // Faixa sem juros por ciclo (ver pagarme_freeInstallmentsForMonths)
                if ($minM<=1) $serverFree=1; elseif($minM<=3)$serverFree=1; elseif($minM<=6)$serverFree=3; elseif($minM<=24)$serverFree=5; else $serverFree=6;
            }
        } catch (\Throwable $e) { $serverMax = 0; $serverFree = 0; }
    }

    return <<<HTML
<script>
(function () {
    var GATEWAY_KEY = '{$gatewayKey}';
    var IS_ORDER_FORM = {$isOrderFormJs};
    var SERVER_MAX = {$serverMax};
    var SERVER_FREE = {$serverFree};
    var FEES = {$feesJson};
    var MARGINS = {$marginsJson};

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
    // 1x, semestral 3x, anual/bienal 5x, trienal+ 6x.
    var FREE_BY_CYCLE = {
        free: 1, onetime: 5, monthly: 1, quarterly: 1,
        semiannually: 3, 'semi-annually': 3,
        annually: 5, biennially: 5, triennially: 6
    };
    // Teto de parcelas por ciclo. Espelha pagarme_maxInstallmentsForMonths().
    var MAX_BY_CYCLE = {
        free: 1, onetime: 12, monthly: 1, quarterly: 3,
        semiannually: 6, 'semi-annually': 6,
        annually: 12, biennially: 12, triennially: 12
    };

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

    function getRate(brand, n, free){
        if(n<=free) return 0;
        var bt=(FEES[brand]&&FEES[brand].credito)||(FEES.outras&&FEES.outras.credito)||{};
        var mt=(MARGINS.credito)||{};
        var fee=parseFloat(bt[n])||0;
        var mar=parseFloat(mt[n])||0;
        return fee+mar;
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

    function maxAllowed(){
        if(SERVER_MAX>0) return SERVER_MAX;
        var v=currentCycleValue();
        if(MAX_BY_CYCLE[v]!==undefined) return MAX_BY_CYCLE[v];
        return 12;
    }

    function freeAllowed(){
        if(SERVER_FREE>0) return SERVER_FREE;
        var v=currentCycleValue();
        if(FREE_BY_CYCLE[v]!==undefined) return FREE_BY_CYCLE[v];
        return 1;
    }

    // Na MONTAGEM DO PEDIDO, cartão salvo não conclui: o Lagom posta
    // 'ccinfo=<id do pay method>' e o WHMCS core recusa antes de chamar o
    // módulo, respondendo "O número do cartão que você digitou não é válido"
    // (comprovado por captura de XHR; ocorre também com a Cielo tokenizada,
    // ou seja, não é específico deste gateway). Como oferecer uma opção que
    // não finaliza o pedido só gera chamado de suporte, escondemos a aba e
    // deixamos apenas "cartão novo".
    //
    // Escopo: SOMENTE o order form. Na fatura o cartão salvo funciona
    // normalmente e não é tocado - assim como a renovação automática pelo
    // cron, que nem passa por esta tela.
    function enforceNewCardOnly(){
        if(!IS_ORDER_FORM) return;
        var panel=document.querySelector('#mg-gateway-form-'+GATEWAY_KEY);
        if(!panel) return;
        var exRadio=panel.querySelector('input[name="cardInfo"][value="existing"]');
        var nwRadio=panel.querySelector('input[name="cardInfo"][value="new"]');
        if(!exRadio||!nwRadio) return;
        var exLi=exRadio.closest('.nav-item'), nwLi=nwRadio.closest('.nav-item');
        if(!exLi||!nwLi) return;

        exLi.style.display='none';

        // Já está na aba de cartão novo: nada mais a fazer.
        if(!exLi.classList.contains('active')) return;

        if(exLi.dataset.pgmSwitch==='1'){
            // O clique não trocou a aba (Vue não reagiu). Esconde a lista de
            // cartões salvos para não oferecer algo que será recusado.
            var lista=panel.querySelector('.cc-input-container');
            if(lista) lista.style.display='none';
            return;
        }
        exLi.dataset.pgmSwitch='1';

        // O <a> tem href="" e recarregaria a página; o preventDefault em fase
        // de captura evita isso sem impedir o handler do Vue (preventDefault
        // não interrompe outros listeners).
        var alvo=nwLi.querySelector('a')||nwLi;
        try{
            alvo.addEventListener('click',function(e){ e.preventDefault(); },{capture:true,once:true});
            alvo.click();
        }catch(e){}
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
        enforceNewCardOnly();
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
                    // No order form o cartão salvo é recusado pelo WHMCS (ver
                    // enforceNewCardOnly), então nunca postamos 'existing' ali -
                    // nem pelo fallback acima, se a aba não for encontrada.
                    if (IS_ORDER_FORM) { cardInfoVal = 'new'; }
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

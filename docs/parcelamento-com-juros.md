# Parcelamento com juros (Caminho B) — Pagar.me

Replica o modelo do módulo Cielo para a Pagar.me, permitindo coexistência das duas.

## Regras implementadas

Teto de parcelas e faixa sem juros por ciclo (item mais restritivo da fatura
manda, ver `pagarme_minMonthsForInvoice`):

| Ciclo      | Teto de parcelas | Sem juros | Com juros (taxa da plataforma) |
|------------|:---:|:---:|:---:|
| Mensal     | 1x  | —      | — (só à vista) |
| Trimestral | 3x  | 1x     | 2x a 3x |
| Semestral  | 6x  | 1x a 3x | 4x a 6x |
| Anual      | 12x | 1x a 5x | 6x a 12x |
| Bienal     | 12x | 1x a 5x | 6x a 12x |
| Trienal    | 12x | 1x a 6x | 7x a 12x |

Implementado em `pagarme_maxInstallmentsForMonths` (teto) e
`pagarme_freeInstallmentsForMonths` (faixa sem juros), ambas em
`installments.php`.

O juros é a **taxa MDR da Pagar.me**, repassada ao comprador e adicionada como
item na fatura, para que o total cobrado bata com a contabilidade do WHMCS.

### Modo de cálculo (14/08/2026): simples (padrão) ou composto + margem opcional

Por padrão o juros é **simples**: a taxa MDR é aplicada uma única vez sobre a
base, independente do número de parcelas dentro da mesma faixa (ex: 7x e 12x
usam a mesma taxa "7-12x"). Esse continua sendo o comportamento sem nenhuma
configuração adicional.

Configurável pelo addon `modules/addons/pagarme_fee_rates/` (aba "Modo de
Cálculo"):

- **Composto** — Tabela Price/Sistema Francês de Amortização
  (`pagarme_compoundTotal()`), usando a taxa MDR cadastrada como taxa mensal
  composta. Resulta num total maior que o modo simples para a mesma taxa
  nominal, crescendo mais que proporcionalmente com o número de parcelas.
- **Margem fixa opcional** (desligada por padrão) — reintroduz o mecanismo do
  antigo `stay_margins.json` como um toggle configurável, aplicado **por cima**
  do total já calculado (simples ou composto), nunca somado à taxa MDR antes
  do cálculo.

Função central: `pagarme_installmentTotal($baseAmount, $brand, $installments,
$freeInstallments, $modeOverride)` em `installments.php`, que substitui as
chamadas separadas a `pagarme_customerRate()`/`pagarme_feeForInstallments()`
nos 3 pontos que decidem o total (`pagarme_buildInstallmentOptions()` 2x,
`pagarme_capture()` 1x) e no hook `pagarme_installments_selector.php`
(client-area, via `EFFECTIVE_RATES` pré-calculado no PHP).

**O modo é persistido junto da escolha do cliente** (`mod_pagarme_installments`,
colunas `formula`/`margin_enabled`/`margin_snapshot`), não relido ao vivo na
captura. Isso fecha a janela em que um admin muda o modo entre o cliente ver o
preço e a cobrança de fato acontecer — a captura sempre usa o modo que estava
ativo no momento em que o cliente viu o total, nunca o modo atual.

**A margem antiga (`stay_margins.json`) segue sem uso** — o arquivo novo
(`pagarme_installment_mode.json`) tem formato equivalente mas é um mecanismo
separado, editável pelo addon, desligado por padrão.

### Base de cálculo

O juros incide sobre `pagarme_invoiceBaseAmount()` — o saldo da fatura **menos**
o item `[PARCELAMENTO]` de uma tentativa anterior. Usar o total da fatura aqui
faria o juros incidir sobre o próprio juros a cada retentativa.

Quando uma nova tentativa cai numa faixa **sem** juros, o item de taxa anterior é
removido (`pagarme_clearInstallmentFee()`) e o total volta à base — senão a
fatura ficaria permanentemente inflada.

## Arquivos

- `modules/gateways/pagarme/installments.php` — regras por ciclo, cálculo de
  taxa, montagem das opções, reconciliação da fatura, registro da taxa de
  transação real (nota da fatura) e persistência da escolha
- `modules/gateways/pagarme/inc/pagarme_credit_card_taxes.json` — MDR por
  bandeira e número de parcelas. Conferido contra a proposta comercial.
- `modules/gateways/pagarme/inc/stay_margins.json` — **não utilizado**; mantido
  só como histórico
- `modules/gateways/pagarme/inc/pagarme_installment_mode.json` — modo de
  cálculo ativo (fórmula simples/composta + margem fixa opcional), gravado
  pelo addon `pagarme_fee_rates`
- `includes/api/` — custom API actions `GetPagarmeInstallments` e
  `SetPagarmeInstallments`, que expõem o parcelamento para os checkouts
  headless. Ver `includes/api/README.md` (instalação e registro).
- `includes/hooks/pagarme_installments_selector.php` — seletor na área do
  cliente do WHMCS. **Não executa** nos checkouts headless. Usa
  `EFFECTIVE_RATES` pré-calculado no PHP (via `pagarme_installmentTotal()`),
  nunca reimplementa juros/promoção em JavaScript.
- `modules/addons/pagarme_fee_rates/` — addon de admin (taxas, promoções,
  modo de cálculo). Ver seção 8 do `README.md`.

## Taxas — conferidas (03/08/2026)

`inc/pagarme_credit_card_taxes.json` bate exatamente com as condições acordadas
na proposta comercial Stone/Pagar.me:

| Bandeira | À vista | 2-6x | 7-12x |
|---|---|---|---|
| Mastercard | 1,87% | 2,29% | 3,82% |
| Visa | 1,97% | 2,29% | 3,82% |
| Elo | 2,40% | 2,65% | 3,82% |
| Amex | 2,40% | 2,65% | 3,82% |

`outras` (Hipercard, Diners e bandeiras não listadas) usa 2,40/2,65/3,82 — a
faixa mais cara entre as acordadas, portanto conservadora.

O rótulo de "clone das taxas da Cielo" que constava no código era resíduo do
início do projeto e estava errado; foi corrigido.

> **Confirmar com a Pagar.me antes do go-live:** que a conta está como
> **parcelado lojista** e não soma juros por cima do valor enviado. Nós já
> embutimos o juros no total; se a Pagar.me também somar, o cliente paga duas
> vezes. Este é o modelo necessário porque as faixas sem juros variam por ciclo,
> e a configuração de parcelamento da Pagar.me é global (uma só para a conta).

> **Em aberto (04/08/2026):** uma cobrança real em ambiente de teste (Loja de
> teste da Pagar.me) mostrou MDR efetivo de ~4,49% numa venda 12x, acima dos
> 3,82% desta tabela. Em conta de teste a Pagar.me costuma aplicar a tabela
> padrão dela, não a negociada — em investigação se produção bate com a
> proposta comercial antes de mudar estes números.
>
> **Fica mais crítico com o modo composto (14/08/2026):** juros compostos
> amplificam superlinearmente qualquer erro na taxa base ao longo de N
> parcelas — o mesmo desvio de ~0,7pp que muda pouco no modo simples muda bem
> mais em 12x composto. **Não ativar o modo composto em produção antes desta
> investigação de taxa ser encerrada**, mesmo que o addon já permita.

### Taxa de transação (MDR real) — campo nativo do WHMCS, não cobrada do cliente

Distinta do juros de parcelamento acima. O juros é repassado ao cliente **só**
fora da faixa sem juros do ciclo; a taxa de transação é o custo interno
registrado no WHMCS, sem relação com o juros repassado ao cliente.

Usa a taxa MDR **real da faixa de parcelas escolhida** pelo cliente (ex: 12x usa a
taxa real de 7-12x, não a de 1x) — `pagarme_transactionFeeAmount($chargeAmount,
$brand, $installments)`, tabela cheia à vista/2-6x/7-12x. Aplicada sobre o valor
bruto realmente processado (`$chargeAmount`).

> **Histórico:** entre a manhã e a tarde de 14/08/2026 esta regra foi
> temporariamente trocada para "sempre a taxa 1x/à vista, independente da
> parcela", e depois revertida no mesmo dia de volta para a taxa da faixa real
> — decisão de negócio confirmada nas duas direções pelo usuário. A regra
> vigente é a taxa da faixa real.

Vai para o campo **nativo** de taxa de transação do WHMCS: `_capture()` retorna
a chave `'fee'` (contrato documentado da WHMCS para módulos de gateway), que o
próprio WHMCS grava em `tblaccounts.fees` ao aplicar o pagamento — a coluna
"Taxas da Transação" que já aparece na aba Resumo da fatura (mesmo lugar que a
Cielo usa). **Não** altera `tblinvoices.total` nem é cobrada do cliente; existe
só para bater com o extrato da Pagar.me e alimentar os relatórios nativos do
WHMCS (Relatórios > Pagamentos, bruto/taxas/líquido).

Calculada sobre o valor bruto efetivamente processado pela Pagar.me
(`$chargeAmount` — já inclui o juros de parcelamento repassado ao cliente,
quando houver, porque é sobre esse total que a Pagar.me também desconta o
MDR). Quando há juros, o valor é rateado entre as duas parcelas do pagamento
(a aplicada automaticamente pelo WHMCS via `'fee'` e a do juros, aplicada por
`pagarme_applyInterestPortion()`) na mesma proporção de cada uma, para que a
soma bata com o valor real.

## Como a escolha de parcelas chega ao módulo

| Origem | Canal | Onde se aplica |
|---|---|---|
| Área do cliente do WHMCS | `$_REQUEST['pagarme_installments']` (hook) | Fluxo legado |
| Checkouts headless | Registro em `mod_pagarme_installments` via `SetPagarmeInstallments` | checkout-staycloud, staycloud-frontned |

O fallback por **cookie foi removido**: não funciona em chamada de API e podia
aplicar a escolha de uma fatura a outra.

Uma seleção persistida **expirada ou defasada** (fatura mudou de valor depois da
escolha) resulta em **recusa** com pedido de refazer a escolha. Nunca em
cobrança silenciosa à vista — isso cobraria o cliente num plano diferente do que
ele aceitou.

## Coexistência com a Cielo

Cada seletor ativa só quando o SEU gateway está selecionado:
- Cielo: `lknc_cielo_credit_card_token` → posta `lknc_installment`
- Pagar.me: `pagarme` → posta `pagarme_installments`

Não há conflito: as injeções não aparecem ao mesmo tempo.

## Validação recomendada no staging

Regras e reconciliação:

1. Fatura anual, 5x → sem juros, total inalterado, `capture: pago`, installments=5
2. Fatura anual, 8x → com juros: aparece item "[PARCELAMENTO] Taxa..." na fatura,
   total maior, fatura fecha **exatamente em zero**
3. Fatura trimestral, 3x → com juros (só 1x é sem juros nesse ciclo)
4. Cartão salvo (token) → o parcelamento também deve funcionar
5. Conferir no painel Pagar.me que o campo Parcelas reflete o escolhido

Regressões que já foram bugs (não remover destes testes):

6. **Juros não compõe**: recusa em 8x, repete em 8x → o item de taxa mantém o
   MESMO valor. Antes, a taxa incidia sobre base+taxa a cada tentativa.
7. **Taxa órfã**: depois da falha em 8x, repete em 4x (sem juros) → o item
   `[PARCELAMENTO]` é REMOVIDO e o total volta à base. Antes, o item ficava e a
   fatura seguia inflada.

API (ver `includes/api/README.md`):

8. `GetPagarmeInstallments` em fatura anual → `max=12`, `free=5`
9. Modo preview com o mesmo ciclo e valor → opções idênticas ao modo invoice
10. `SetPagarmeInstallments` com `expected_total` errado → `total_mismatch`, sem
    cobrança
11. Após qualquer captura aprovada (inclusive 1x à vista) → a coluna "Taxas da
    Transação" da fatura (aba Resumo, tabela Transações) mostra o valor real da
    MDR **na taxa da faixa de parcelas escolhida** (ex: 3,82% em 12x, não a taxa
    de 1x), e `tblinvoices.total` **não muda**.
12. Fatura anual, 8x (com juros) → a taxa de transação aparece rateada entre as
    DUAS linhas de transação (a base e a `_fee` do juros); a soma das duas bate
    com `pagarme_transactionFeeAmount($chargeAmount, $brand, 8)` sobre o valor
    bruto total (taxa real de 8x, não a taxa de 1x).

> Ponto que merece atenção especial no teste: a reconciliação da fatura quando
> há juros (itens 2 e 3). O módulo aplica a parcela do juros e o WHMCS aplica o
> restante; confirme que a fatura fecha exatamente em zero, sem saldo residual
> nem pagamento duplicado.

Modo de cálculo (14/08/2026, addon `pagarme_fee_rates`, aba "Modo de Cálculo"):

13. Modo simples (default) + margem desligada → nenhuma mudança de comportamento
    em relação aos itens 1-12 acima.
14. Ativar modo composto → fatura anual 12x calcula um total MAIOR que o modo
    simples equivalente para a mesma taxa nominal (juros compostos > juros
    simples para n>1); conferir a fórmula manualmente para uma parcela.
15. Ativar margem fixa junto do composto → total final = (total composto) ×
    (1 + margem%); nunca margem somada à taxa MDR antes de compor.
16. Escolher parcela com um modo ativo (`SetPagarmeInstallments`) → admin muda
    o modo → capturar a fatura → a cobrança real usa o modo que estava ativo
    NO MOMENTO DA ESCOLHA (persistido em `mod_pagarme_installments`), não o
    modo atual do admin.
17. Com o hook `pagarme_installments_selector.php` instalado → o seletor no
    client area mostra o mesmo total que `GetPagarmeInstallments` calcularia
    para a mesma fatura, em qualquer modo (simples ou composto).

## Problema em aberto: cartão salvo no pedido novo (Lagom Smart Order Form)

Ao criar um PEDIDO NOVO usando um cartão já salvo, aparece o erro "O número do
cartão que você digitou não é válido" e o pedido não conclui.

### Diagnóstico correto (revisado em 30/07/2026)

Uma versão anterior deste doc atribuía o erro a uma validação client-side do
Lagom, afirmando que nada chegava ao backend. **Isso estava errado.** Captura de
XHR no navegador (staging, produto Bienal, cliente 157) mostra que:

1. O Lagom ENVIA a requisição para `cart.php?a=checkout`, com
   `paymentmethod=pagarme` e `ccinfo=<id do cartão salvo>` — o `ccinfo` é o
   campo padrão do WHMCS para escolher um pay method existente.
2. O POST não contém `ccnumber`, `cccvv` nem `ccexpiry*` (correto para cartão
   salvo).
3. **O próprio WHMCS responde** (HTTP 200) com
   `<li>O número do cartão que você digitou não é válido`.

Ou seja: a rejeição é do WHMCS core, ANTES de chamar o módulo — por isso o
Gateway Log fica vazio (esta parte da observação original estava certa; errada
era a conclusão de que nada chegava ao servidor).

Hipótese de trabalho: o WHMCS não resolve o `ccinfo` recebido como um pay method
válido do cliente e, ao não reconhecê-lo, cai no caminho de "cartão novo" e
valida o número — que está vazio.

**O CVV está descartado como causa** (para ESTA investigação específica, ver
nota abaixo). No momento desta investigação, o caminho de cartão salvo do
módulo (`pagarme_capture`, cenário `gatewayid`) montava o payload só com
`customer_id` + `card_id`, sem CVV; era o mesmo caminho usado no pagamento de
fatura, que funcionava. O campo de CVV que aparece na tela de fatura era
renderizado pelo WHMCS mas ignorado pelo módulo nesse fluxo.

> **Atualização (17/08/2026):** esse comportamento mudou. `pagarme_capture()`
> agora exige e envia o CVV para cartão salvo quando a fatura é de PEDIDO NOVO
> (serviço ainda `Pending`) - ver `pagarme_isNewOrderInvoice()` no módulo.
> Renovação/recorrência via cron continua sem exigir CVV (nunca há CVV
> disponível nesse contexto). A conclusão acima permanece válida para a
> investigação original (o CVV não era a causa daquele bug específico de
> order form), mas a frase "ignorado pelo módulo nesse fluxo" não é mais
> verdadeira em geral.

### Hipóteses descartadas

- **CVV ausente**: ver acima; o caminho de cartão salvo não usa CVV.
- **Cartão salvo por outro gateway**: testado com cartões criados na própria
  Pagar.me; o erro persiste.
- **Gateway dedicado ao fluxo tokenizado**: a Cielo tokenizada
  (`lknc_cielo_credit_card_token`), que já é um gateway desse tipo, **falha da
  mesma forma** neste order form. Ou seja, o problema não é do registro do
  gateway e criar um módulo dedicado não resolveria.

Conclusão: é comportamento do WHMCS + Lagom no order form, fora do alcance de
qualquer módulo de gateway. Cabe chamado ao suporte do Lagom/ModulesGarden.

### Contorno REMOVIDO (03/08/2026)

Houve um `enforceNewCardOnly()` no hook que ocultava a aba "Usar cartão
existente" no order form. **Foi removido a pedido**: com a migração para os
checkouts headless, o order form do Lagom sai de cena e o contorno perdeu a
razão de existir.

Se o order form do WHMCS voltar a ser usado, o problema descrito acima
reaparece — o registro fica aqui para não se perder o diagnóstico.

O parcelamento (seletor + juros) funciona normalmente no pedido novo com cartão
NOVO e nas faturas, inclusive com cartão salvo.

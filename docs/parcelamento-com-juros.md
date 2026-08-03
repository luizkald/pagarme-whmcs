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

O juros (taxa da adquirente + margem da loja) é repassado ao comprador e
adicionado como item na fatura, para que o total cobrado bata com a
contabilidade do WHMCS.

## Arquivos

- `modules/gateways/pagarme/installments.php` — lógica de teto por ciclo, taxa,
  reconciliação da fatura
- `modules/gateways/pagarme/inc/pagarme_credit_card_taxes.json` — **CLONE das
  taxas da Cielo (placeholder)**. Substituir pelas taxas reais da Pagar.me.
- `modules/gateways/pagarme/inc/stay_margins.json` — margens da loja por parcela
- `includes/hooks/pagarme_installments_selector.php` — seletor no checkout/fatura

## Taxas — AÇÃO NECESSÁRIA

As tabelas em `inc/` são um **clone das taxas da Cielo**, só para o staging
funcionar. Quando o dono da conta passar as taxas reais da Pagar.me (por
bandeira e por número de parcelas), edite `pagarme_credit_card_taxes.json`
mantendo a mesma estrutura. As margens (`stay_margins.json`) são política
comercial da loja e podem permanecer.

## Coexistência com a Cielo

Cada seletor ativa só quando o SEU gateway está selecionado:
- Cielo: `lknc_cielo_credit_card_token` → posta `lknc_installment`
- Pagar.me: `pagarme` → posta `pagarme_installments` (com fallback por cookie)

Não há conflito: as injeções não aparecem ao mesmo tempo.

## Validação recomendada no staging

1. Fatura anual, 5x → sem juros, total inalterado, `capture: pago`, installments=5
2. Fatura anual, 8x → com juros: aparece item "[PARCELAMENTO] Taxa..." na fatura,
   total maior, fatura fecha em zero
3. Fatura trimestral, 3x → com juros (só 1x é sem juros nesse ciclo)
4. Cartão salvo (token) → o parcelamento também deve funcionar
5. Conferir no painel Pagar.me que o campo Parcelas reflete o escolhido

> Ponto que merece atenção especial no teste: a reconciliação da fatura quando
> há juros (itens 2 e 3). O módulo aplica a parcela do juros e o WHMCS aplica o
> restante; confirme que a fatura fecha exatamente em zero, sem saldo residual
> nem pagamento duplicado.

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

**O CVV está descartado como causa.** O caminho de cartão salvo do módulo
(`pagarme_capture`, cenário `gatewayid`) monta o payload com `customer_id` +
`card_id` e não envia CVV; é o mesmo caminho usado no pagamento de fatura, que
funciona. O campo de CVV que aparece na tela de fatura é renderizado pelo WHMCS
mas ignorado pelo módulo nesse fluxo.

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

### Contorno implementado

`enforceNewCardOnly()` em `includes/hooks/pagarme_installments_selector.php`
oculta a aba "Usar cartão existente" e ativa "Digite informações do novo cartão"
**apenas na montagem do pedido** (flag `IS_ORDER_FORM`, derivada da URI no PHP).
Se o clique de troca de aba não surtir efeito, a lista de cartões salvos é
escondida como fallback — a aba de cartão novo continua visível e clicável, sem
deixar o painel sem saída.

Não afeta:
- pagamento de fatura (cartão salvo segue funcionando, com parcelamento);
- renovação automática pelo cron (`capture` com `gatewayid`), que não passa por
  esta tela;
- os demais gateways, pois tudo é escopado em `#mg-gateway-form-pagarme`.

Impacto residual: em PEDIDO NOVO o cliente digita o cartão uma vez, mesmo já
tendo um salvo. O cartão é tokenizado no ato (`storeremote`), então as
renovações seguintes são automáticas.

O parcelamento (seletor + juros) funciona normalmente no pedido novo com cartão
NOVO e nas faturas, inclusive com cartão salvo.

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

**Sem margem da loja.** A margem de `stay_margins.json` existia para cobrir o
custo de antecipar recebíveis; como a Stay não antecipa (recebe parcela a
parcela), o custo real do parcelamento é o próprio MDR. O arquivo permanece no
repositório como histórico e **não é mais lido**.

### Base de cálculo

O juros incide sobre `pagarme_invoiceBaseAmount()` — o saldo da fatura **menos**
o item `[PARCELAMENTO]` de uma tentativa anterior. Usar o total da fatura aqui
faria o juros incidir sobre o próprio juros a cada retentativa.

Quando uma nova tentativa cai numa faixa **sem** juros, o item de taxa anterior é
removido (`pagarme_clearInstallmentFee()`) e o total volta à base — senão a
fatura ficaria permanentemente inflada.

## Arquivos

- `modules/gateways/pagarme/installments.php` — regras por ciclo, cálculo de
  taxa, montagem das opções, reconciliação da fatura e persistência da escolha
- `modules/gateways/pagarme/inc/pagarme_credit_card_taxes.json` — MDR por
  bandeira e número de parcelas. Conferido contra a proposta comercial.
- `modules/gateways/pagarme/inc/stay_margins.json` — **não utilizado**; mantido
  só como histórico
- `includes/api/` — custom API actions `GetPagarmeInstallments` e
  `SetPagarmeInstallments`, que expõem o parcelamento para os checkouts
  headless. Ver `includes/api/README.md` (instalação e registro).
- `includes/hooks/pagarme_installments_selector.php` — seletor na área do
  cliente do WHMCS. **Não executa** nos checkouts headless.

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

### Contorno REMOVIDO (03/08/2026)

Houve um `enforceNewCardOnly()` no hook que ocultava a aba "Usar cartão
existente" no order form. **Foi removido a pedido**: com a migração para os
checkouts headless, o order form do Lagom sai de cena e o contorno perdeu a
razão de existir.

Se o order form do WHMCS voltar a ser usado, o problema descrito acima
reaparece — o registro fica aqui para não se perder o diagnóstico.

O parcelamento (seletor + juros) funciona normalmente no pedido novo com cartão
NOVO e nas faturas, inclusive com cartão salvo.

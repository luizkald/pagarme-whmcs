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

## Limitação conhecida: cartão salvo no pedido novo (Lagom Smart Order Form)

Ao criar um PEDIDO NOVO usando um cartão já salvo, o Lagom Smart Order Form
exibe o erro "O número do cartão que você digitou não é válido" e não envia o
formulário. Isso é uma validação **client-side do próprio Lagom** (frontend Vue
da RS Studio/ModulesGarden), que dispara antes do envio quando o campo de número
de cartão está vazio — situação normal ao usar um cartão salvo.

Como a validação ocorre no navegador, antes de qualquer requisição ao servidor,
o módulo PHP não tem como interceptá-la. Confirmado: nesse cenário nenhuma linha
aparece no Gateway Log (nada chega ao backend).

Decisão de projeto: NÃO tratar isso no módulo. Impacto real é pequeno —
- Pedido novo: o cliente digita o cartão uma vez (fluxo normal de primeira compra).
- Renovações (via fatura): o cartão salvo funciona normalmente.

Caminhos possíveis caso, no futuro, seja necessário suportar cartão salvo já no
pedido novo:
- Criar um gateway dedicado ao fluxo tokenizado (como a Cielo faz com
  `lknc_cielo_credit_card_token`), reconhecido pelo Lagom como gateway de
  tokenização.
- Abrir chamado com o suporte do Lagom/ModulesGarden (é comportamento do produto
  deles).

O parcelamento (seletor + juros) funciona normalmente no pedido novo com cartão
NOVO e nas faturas, inclusive com cartão salvo.

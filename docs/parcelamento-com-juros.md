# Parcelamento com juros (Caminho B) — Pagar.me

Replica o modelo do módulo Cielo para a Pagar.me, permitindo coexistência das duas.

## Regras implementadas

M�ximo de parcelas por ciclo (item mais restritivo da fatura manda):
- Mensal: 1x
- Trimestral: até 3x
- Semestral: até 6x
- Anual ou superior: até 12x

Faixa sem juros:
- Anual+: 1x a 5x sem juros; 6x a 12x com juros
- Trimestral/Semestral: apenas 1x (à vista); 2x+ com juros
- Mensal: só 1x

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

# Custom API Actions — Pagar.me

Expõem o parcelamento para os checkouts headless (`checkout-staycloud` e
`staycloud-frontned`), que consomem só a API do WHMCS e nunca renderizam a área
do cliente — logo, nunca executam o hook `ClientAreaFooterOutput`.

As **regras** (teto por ciclo, faixa sem juros, taxa por bandeira) continuam
morando em `modules/gateways/pagarme/installments.php`. As actions só expõem o
que o módulo calcula. Nenhum frontend deve reimplementar essa matemática: é o
que garante que o valor exibido ao cliente seja o mesmo que será cobrado.

## Arquivos

| Action | Arquivo | Papel |
|---|---|---|
| `GetPagarmeInstallments` | `getpagarmeinstallments.php` | Lista as opções de parcelamento |
| `SetPagarmeInstallments` | `setpagarmeinstallments.php` | Registra a escolha antes da captura |

## Instalação

1. Copiar os dois `.php` para `<WHMCS_ROOT>/includes/api/`.
2. **Registrar no catálogo de custom actions.** As entradas já estão em
   `staycloud-frontned/whmcs/custom-actions/custom_api_register.php`
   (`getpagarmeinstallments` / `setpagarmeinstallments`) — só falta esse hook
   estar instalado em `<WHMCS_ROOT>/includes/hooks/custom_api_register.php` no
   servidor. Ele só roda no hook `AdminAreaPage`, então **precisa de alguém
   abrir qualquer página do admin do WHMCS pelo menos uma vez** depois de
   instalado para as duas actions aparecerem no catálogo.
3. **Liberar as duas actions no papel de API.** Toda action registrada por
   esse hook nasce com `default: 0` — ou seja, desligada em todos os papéis até
   alguém marcar manualmente. Vá em **Setup > Staff Management > API Roles**,
   abra o papel usado pelo identifier/secret dos apps, e marque
   `GetPagarmeInstallments` e `SetPagarmeInstallments` no grupo "Custom API
   Actions".

> Pular qualquer um dos passos 2 ou 3 faz a chamada retornar
> `403 Forbidden` com corpo `{"error":"Não foi possível calcular o
> parcelamento."}` (do lado do checkout/painel) — o arquivo `.php` sozinho em
> `includes/api/` **não é suficiente**, mesmo estando no lugar certo.

Nenhuma migração de banco é necessária: a tabela `mod_pagarme_installments` é
criada sob demanda (módulos de gateway do WHMCS não têm hook de ativação).

## GetPagarmeInstallments

Dois modos:

- **`invoice`** (autoritativo) — passe `invoiceid`. Usa o ciclo dos serviços da
  fatura e o **saldo real** como base.
- **`preview`** (consultivo) — passe `billingcycle` + `amount`. Existe porque o
  checkout renderiza o seletor **antes** de criar o pedido, quando ainda não há
  fatura.

| Parâmetro | Modo | Notas |
|---|---|---|
| `invoiceid` | invoice | — |
| `billingcycle` | preview | `monthly`…`triennially` |
| `amount` | preview | base em reais |
| `brand` | ambos | opcional; default `outras` (faixa mais conservadora) |

```bash
curl -s https://SEU_WHMCS/includes/api.php \
  -d identifier=... -d secret=... -d responsetype=json \
  -d action=GetPagarmeInstallments \
  -d invoiceid=1234 -d brand=visa
```

Resposta (resumida):

```json
{
  "result": "success",
  "mode": "invoice",
  "enabled": true,
  "cycle_months": 12,
  "base_amount": 1200.00,
  "max_installments": 12,
  "free_installments": 5,
  "options": [
    { "installments": 1, "interest_free": true,  "rate": 0,    "fee_amount": 0,     "installment_amount": 1200.00, "total": 1200.00 },
    { "installments": 8, "interest_free": false, "rate": 6.82, "fee_amount": 81.84, "installment_amount": 160.23,  "total": 1281.84 }
  ]
}
```

`total` é **autoritativo** — é o que será cobrado. `installment_amount` é só
exibição: quem divide de fato a cobrança é a Pagar.me, que absorve a diferença
de centavos na última parcela.

`enabled: false` quando o parcelamento está desligado na configuração do
gateway. O frontend pode usar isso para não renderizar o seletor, sem precisar
de uma segunda chamada.

## SetPagarmeInstallments

Registra a escolha do cliente **antes** da captura. Obrigatório nos checkouts
headless: a escolha acontece numa requisição e a cobrança em outra, então sem
esse registro o módulo cobraria à vista em silêncio.

| Parâmetro | Obrigatório | Notas |
|---|---|---|
| `invoiceid` | sim | — |
| `installments` | sim | 1..`max_installments` do ciclo |
| `expected_total` | sim | o total que a UI **exibiu** ao cliente |
| `brand` | não | afeta a taxa |

**A trava.** Se o módulo calcular um total diferente do `expected_total` em mais
de R$ 0,01, a chamada é rejeitada com `code: total_mismatch` e **nada é
cobrado**. É isso que impede o sistema de cobrar um valor diferente do que o
cliente viu — especialmente vindo do modo `preview`, que é por natureza uma
estimativa.

Códigos de erro: `total_mismatch`, `installments_out_of_range`,
`invoice_not_payable`.

O registro vale ~30 min e é invalidado se a base da fatura mudar. Na captura,
uma seleção expirada ou defasada resulta em **recusa com mensagem pedindo para
refazer a escolha** — nunca uma cobrança silenciosa à vista.

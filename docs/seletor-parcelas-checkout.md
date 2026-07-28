# Seletor de parcelas no checkout

O módulo lê a parcela escolhida pelo cliente a partir do campo `pagarme_installments`
enviado no request da tela de pagamento. Para que esse campo exista, é preciso
adicioná-lo ao formulário de cartão de crédito do seu tema.

O teto é fixo em **5x** e **sem juros** — o valor cobrado é sempre igual ao total da
fatura, apenas dividido.

## Opção A (recomendada): adicionar o seletor via arquivo do tema

No seu tema (ex.: `templates/six/`), localize o template do formulário de cartão de
crédito — normalmente `creditcard.tpl` (ou o bloco de pagamento em `viewinvoice.tpl`).
Adicione o `<select>` abaixo dentro do formulário de pagamento:

```html
<div class="form-group" id="pagarme-installments-wrapper">
    <label for="pagarme_installments">Parcelas</label>
    <select name="pagarme_installments" id="pagarme_installments" class="form-control">
        <option value="1">À vista</option>
        <option value="2">2x sem juros</option>
        <option value="3">3x sem juros</option>
        <option value="4">4x sem juros</option>
        <option value="5">5x sem juros</option>
    </select>
    <small class="text-muted">Parcelamento em até 5x sem juros, disponível para planos anuais.</small>
</div>
```

> Observação: este `<select>` estático sempre mostra as 5 opções. Quem decide se o
> parcelamento é realmente permitido (plano anual, parcelamento habilitado) é o
> módulo, no servidor — se as condições não forem atendidas, ele ignora a escolha e
> cobra à vista. Ou seja, o cliente pode ver as opções, mas a regra é aplicada com
> segurança no backend.

## Opção B: exibir as opções com o valor de cada parcela

Se quiser mostrar o valor de cada parcela (ex.: "3x de R$ 400,00"), gere as opções com
JavaScript a partir do total da fatura. Exemplo simples, assumindo que a variável
`invoiceTotal` contenha o total em reais:

```html
<div class="form-group" id="pagarme-installments-wrapper">
    <label for="pagarme_installments">Parcelas</label>
    <select name="pagarme_installments" id="pagarme_installments" class="form-control"></select>
</div>

<script>
(function () {
    var invoiceTotal = {$invoice_balance}; // ajuste conforme a variável do seu tema
    var maxInstallments = 5;
    var select = document.getElementById('pagarme_installments');
    if (!select) return;

    for (var n = 1; n <= maxInstallments; n++) {
        var valor = (invoiceTotal / n).toLocaleString('pt-BR', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
        var label = n === 1 ? ('À vista - R$ ' + valor) : (n + 'x de R$ ' + valor + ' sem juros');
        var opt = document.createElement('option');
        opt.value = n;
        opt.textContent = label;
        select.appendChild(opt);
    }
})();
</script>
```

Ajuste `{$invoice_balance}` para a variável real do seu tema que contém o total da
fatura na tela de pagamento.

## Importante

- O módulo **clampa** a escolha entre 1 e 5. Qualquer valor fora disso vira 1x.
- Se o parcelamento estiver desligado nas configurações do gateway, ou a fatura não
  for de plano anual (quando "Somente planos anuais" está marcado), o módulo cobra à
  vista independentemente do que o cliente escolheu.
- Como não há juros, nenhuma alteração de valor/reconciliação de fatura é necessária.

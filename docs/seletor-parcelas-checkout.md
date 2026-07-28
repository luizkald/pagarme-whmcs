# Seletor de parcelas no checkout

O módulo lê a parcela escolhida pelo cliente a partir do campo `pagarme_installments`
enviado no request da tela de pagamento. Para que esse campo exista, ele precisa ser
adicionado ao formulário de cartão de crédito.

O teto é fixo em **5x** e **sem juros** — o valor cobrado é sempre igual ao total da
fatura, apenas dividido.

## Opção A (RECOMENDADA): hook — funciona em qualquer tema, inclusive Lagom

Basta copiar `includes/hooks/pagarme_installments_selector.php` para a pasta
`/includes/hooks/` na raiz do WHMCS. Pronto — não precisa editar nenhum template.

Esse é o método recomendado **especialmente para temas como o Lagom (RS Studio) /
Smart Order Form**, que sobrescrevem os templates padrão do WHMCS. Editar os arquivos
desses temas diretamente é frágil: as alterações são perdidas na próxima atualização
do tema. O hook injeta o seletor via JavaScript, localizando o campo padrão de cartão
(`input[name="ccnumber"]`) e inserindo o `<select>` dentro do mesmo formulário, então
o valor é enviado normalmente na submissão. Ele também usa MutationObserver, o que o
torna compatível com o checkout em AJAX do Lagom.

Se quiser mudar o rótulo das opções ou o texto de ajuda, edite o próprio hook.

## Opção B: adicionar o seletor manualmente via arquivo do tema

Se preferir controlar o HTML diretamente (temas padrão como o Six), localize o
template do formulário de cartão — normalmente `creditcard.tpl` (ou o bloco de
pagamento em `viewinvoice.tpl`) — e adicione o `<select>` abaixo dentro do formulário:

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

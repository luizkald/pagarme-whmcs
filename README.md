# Módulo de Gateway Pagar.me para WHMCS (Cartão de Crédito)

Módulo do tipo **merchant gateway** (o cliente digita os dados do cartão diretamente
no WHMCS), integrado com a **Pagar.me Core API v5**, com suporte a **tokenização**
para cobranças recorrentes.

## Estrutura de arquivos

```
modules/gateway/pagarme.php                        → módulo principal do gateway
modules/gateway/pagarme/pagarmeapi.php             → cliente HTTP da API Pagar.me
modules/gateway/callback/pagarme.php               → webhook (confirmação assíncrona)
includes/hooks/suppress_zero_invoice_emails.php    → hook opcional (faturas R$ 0,00)
```

## 1. Instalação

1. Copie as pastas `modules/` e `includes/` deste pacote para a raiz da sua instalação
   WHMCS, mesclando com as pastas existentes (são todos arquivos novos, nada é
   sobrescrito).
2. Acesse **Setup > Payments > Payment Gateways** no admin do WHMCS.
3. Na aba **All Payment Gateways**, ative **"Pagar.me - Cartão de Crédito"**.

> O hook `suppress_zero_invoice_emails.php` é **opcional** — veja a seção 7 antes de
> decidir se vai usá-lo.

## 2. Configuração no WHMCS

| Campo | Descrição |
|---|---|
| Secret Key (Produção) | Chave `sk_...` do painel Pagar.me em Conta > Chaves de API |
| Secret Key (Sandbox) | Chave `sk_test_...` para testes |
| Modo de Testes | Marque para usar a chave de Sandbox |
| Descritor na Fatura | Texto exibido na fatura do cartão (máx. 13 caracteres) |
| Parcelas Máximas | Informativo; por padrão o módulo cobra à vista (ver Observações) |
| Nome do Campo Personalizado (CPF/CNPJ) | Nome exato do Custom Client Field com o CPF/CNPJ |

## 3. Campo personalizado de CPF/CNPJ (obrigatório)

A Pagar.me exige um documento (CPF ou CNPJ) para todo cliente. Crie o campo em:

**Setup > Custom Client Fields > Add New Custom Field**
- Field Name: `CPF/CNPJ` (ou outro nome, desde que bata com a configuração do gateway)
- Type: Text Box
- Marque como obrigatório no formulário de cadastro

> Sem esse campo preenchido, o módulo recusa a cobrança com uma mensagem explicativa
> em vez de enviar um pedido inválido à Pagar.me.

## 4. Webhook (confirmação assíncrona)

Algumas cobranças ficam em análise antifraude (status `processing`) e só são
confirmadas depois. Configure no painel da Pagar.me:

**Configurações > Webhooks > Nova assinatura**
- URL: `https://SEUDOMINIO.com/modules/gateway/callback/pagarme.php`
- Eventos: `order.paid`, `order.payment_failed`, `charge.paid`,
  `charge.payment_failed`, `charge.refunded`

## 5. Tokenização (cobranças futuras)

O módulo salva o cartão na Pagar.me para reutilização em cobranças futuras
(recorrência e tentativas automáticas de fatura em atraso), sem que o cliente precise
redigitar os dados a cada fatura:

- **`pagarme_storeremote()`** — chamada quando o cliente salva ou atualiza o cartão
  (ex.: *Área do Cliente > Detalhes de Pagamento*). O módulo cria um `customer` e um
  `card` na Pagar.me e devolve ao WHMCS um token opaco (`customer_id|card_id`).
- **`pagarme_removeremote()`** — chamada quando o cliente remove ou substitui o cartão
  salvo; o módulo desativa o cartão correspondente na Pagar.me.
- **`pagarme_capture()`** — ao processar uma fatura (manual ou pelo cron de cobrança
  automática), se o WHMCS enviar um token salvo (`gatewayid`), o módulo cobra
  diretamente o cartão salvo (`card_id`) em vez de pedir os dados novamente.

Não é necessária configuração extra: o WHMCS detecta que o gateway suporta "salvar
cartão" pela presença dessas funções e passa a oferecer a opção na área do cliente e
no cron de cobrança automática.

Isso também melhora a conformidade PCI: sem tokenização, o WHMCS precisaria armazenar
o número do cartão localmente (criptografado) para conseguir cobrar depois; com
tokenização, apenas o token da Pagar.me fica no banco do WHMCS.

## 6. Testando

Use os cartões de teste oficiais da Pagar.me (https://docs.pagar.me/docs/cartoes-de-teste)
com o **Modo de Testes** ativado antes de ir para produção. Roteiro sugerido:

1. Pagar uma fatura com cartão digitado no checkout → status **Paid**
2. Salvar um cartão em *Detalhes de Pagamento* → token gravado no WHMCS
3. Rodar o cron de cobrança automática numa fatura em aberto → cobrança pelo token
4. Estornar a transação pelo admin → cobrança cancelada na Pagar.me
5. Remover o cartão salvo → cartão desativado na Pagar.me

## 7. Faturas de valor zero (planos gratuitos)

O WHMCS gera fatura de renovação para todo produto ativo no ciclo de cobrança dele,
mesmo que o preço seja R$ 0,00. Isso acontece na geração de faturas do cron, **antes**
de qualquer gateway ser chamado — ou seja, **não é causado pela Pagar.me** e
aconteceria com qualquer gateway.

O próprio WHMCS marca faturas de R$ 0,00 como pagas automaticamente, então o
`pagarme_capture()` normalmente nem chega a ser chamado para elas. Por segurança, o
módulo já tem uma guarda que retorna sucesso sem chamar a API caso isso aconteça.
O incômodo real costuma ser o cliente receber o e-mail "Fatura Criada" e ver a fatura
de R$ 0,00 no histórico.

**Solução recomendada (nativa, evita a fatura por completo):** no produto, em
*Setup > Products/Services > [produto] > Pricing*, troque o ciclo de cobrança para
**"Free Account"** em vez de um ciclo pago com preço R$ 0,00. Isso faz o WHMCS nunca
gerar fatura de renovação para aquele produto.

> Atenção: se os pedidos desse plano são criados via API (`AddOrder`), teste primeiro —
> há relatos na comunidade de o ciclo "Free" reverter para "Monthly" em alguns
> cenários, dependendo da versão do WHMCS.

**Plano B:** se não for possível usar o ciclo "Free" (ex.: precisa manter histórico de
preço para um upgrade futuro), copie `includes/hooks/suppress_zero_invoice_emails.php`
para a pasta `/includes/hooks/` do seu WHMCS. Ele suprime os e-mails de fatura quando
o total é R$ 0,00, sem alterar o fluxo de cobrança.

Existe também um addon pago no WHMCS Marketplace ("Zero Invoice Management") que
oferece uma opção de configuração para evitar a criação de faturas de R$ 0,00, caso
prefira uma solução via addon em vez de hook customizado.

## Observações importantes

- **PCI-DSS**: como o cartão é digitado diretamente no seu site (sem tokenização
  client-side), seu ambiente precisa estar em conformidade com PCI-DSS — HTTPS
  obrigatório em toda a aplicação e nenhum dado de cartão em log ou banco.
- **Parcelamento**: o módulo cobra à vista (1x). O campo "Parcelas Máximas" está
  disponível na configuração, mas oferecer a escolha ao cliente exige customizar o
  template de checkout do WHMCS para capturar a seleção e repassá-la ao campo
  `installments` do payload.
- **Antifraude**: pedidos que retornam `processing` ficam pendentes no WHMCS até a
  confirmação chegar pelo webhook.

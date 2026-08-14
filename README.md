# Módulo de Gateway Pagar.me para WHMCS (Cartão de Crédito)

Módulo do tipo **merchant gateway** (o cliente digita os dados do cartão diretamente
no WHMCS), integrado com a **Pagar.me Core API v5**, com suporte a **tokenização**
para cobranças recorrentes.

## Estrutura de arquivos

```
modules/gateways/pagarme.php                        → módulo principal do gateway
modules/gateways/pagarme/pagarmeapi.php             → cliente HTTP da API Pagar.me
modules/gateways/callback/pagarme.php               → webhook (confirmação assíncrona)
modules/addons/pagarme_fee_rates/                   → addon opcional (edição de taxas MDR pelo admin)
includes/hooks/pagarme_fee_rates_widget.php        → hook opcional (atalho na Home do admin)
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
- URL: `https://SEUDOMINIO.com/modules/gateways/callback/pagarme.php`
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

## 8. Editando as taxas MDR pelo admin (addon "Pagar.me - Taxas MDR")

As taxas MDR usadas no cálculo de juros do parcelamento (`modules/gateways/pagarme/inc/pagarme_credit_card_taxes.json`)
podem ser editadas direto pelo admin do WHMCS, sem acesso a servidor ou repositório, pelo
addon `modules/addons/pagarme_fee_rates/`. Pensado para o financeiro: uma grade de bandeira ×
número de parcelas, com validação e um registro de auditoria de cada alteração.

1. Copie a **pasta inteira** `modules/addons/pagarme_fee_rates/` (com o arquivo
   `pagarme_fee_rates.php` DENTRO dela) para `modules/addons/` na raiz da sua instalação
   WHMCS. O WHMCS exige que cada addon tenha sua própria pasta com o mesmo nome do arquivo
   principal — só o `.php` solto em `modules/addons/`, sem a pasta ao redor, não é reconhecido
   como addon.
2. Acesse **Setup > Addon Modules** (em versões recentes do WHMCS, o menu foi renomeado para
   **Aplicativos e Integrações**), localize **"Pagar.me - Taxas MDR"** na lista e clique em
   **Activate**.
3. Para dar acesso só a essa tela (sem tornar ninguém admin geral): **Configuration > System
   Settings > Admin Roles**, abra o papel do financeiro (ou crie um novo, restrito, se ainda
   não existir), na aba **Addon Modules** marque **"Pagar.me - Taxas MDR"**, e atribua os
   usuários do financeiro a esse papel.

> Pular o passo 2 ou 3 faz o addon não aparecer no menu, ou não aparecer para o papel do
> financeiro, mesmo com a pasta já copiada no lugar certo — mesmo aviso que vale para as
> custom API actions (ver `includes/api/README.md`).

Depois de ativado, o acesso à tela varia com a versão/tema do admin: em instalações mais
recentes costuma ficar em **Aplicativos e Integrações > Pagar.me - Taxas MDR** (clique no
addon, depois em **"Usar o aplicativo"** no popup); em instalações mais antigas, direto em
**Addons > Pagar.me - Taxas MDR**. Cada alteração salva
grava o JSON de forma atômica (nunca fica meio escrito, mesmo se a requisição cair no meio) e
registra um resumo do que mudou no **Activity Log** do WHMCS, com o admin responsável. Valores
aceitos: 0 a 20 (%); qualquer célula ausente ou fora da faixa rejeita o salvamento inteiro,
sem gravar parcialmente. O arquivo `stay_margins.json` (não usado no cálculo hoje) não é
editável por essa tela.

**Piso de taxa (mínimo, protege contra digitar abaixo do custo do gateway).** Na primeira vez
que a tela é aberta após esta atualização, o addon cria automaticamente
`modules/gateways/pagarme/inc/pagarme_credit_card_taxes.floor.json`, um retrato congelado das
taxas então em vigor. A partir daí, nenhuma célula pode ser salva abaixo do valor correspondente
nesse arquivo — a tela mostra o mínimo de cada célula abaixo do campo. O addon nunca reescreve
esse arquivo sozinho depois de criado; se a Pagar.me renegociar as taxas reais no futuro (para
baixo), um dev precisa editar `pagarme_credit_card_taxes.floor.json` manualmente para refletir
o novo piso.

**Promoções sem juros por bandeira.** Abaixo da grade de taxas, uma seção separada permite
marcar uma bandeira como "sem juros" por um período (datas de início/fim opcionais) ou
indefinidamente enquanto ativa. Isso NÃO edita a grade de taxas acima — é um interruptor à
parte, verificado em tempo real por `pagarme_isPromotionActive()`
(`modules/gateways/pagarme/installments.php`), a mesma função usada tanto no preview quanto na
cobrança real. Enquanto ativa, TODAS as parcelas daquela bandeira ficam sem juros,
independente do teto normal do ciclo. Guardado em
`modules/gateways/pagarme/inc/pagarme_promotions.json`, com log próprio no Activity Log,
separado do log de taxas.

> Se o hook opcional `includes/hooks/pagarme_installments_selector.php` (seção "Ativar" acima)
> estiver instalado, ele também respeita a promoção ativa — o seletor de parcelas no client
> area não mostra juros durante o período promocional, consistente com o que será cobrado.

Como ativar uma promoção zera juros para toda parcela e todo cliente daquela bandeira a partir
do save, a tela pede uma confirmação extra (`Confirmar?`) sempre que pelo menos uma promoção
estiver marcada como ativa no momento de salvar — não aparece numa edição só de taxa.

**Controle de taxa: slider + campo numérico.** Cada célula da grade tem um slider ao lado do
campo numérico, sincronizados — arrastar o slider atualiza o número e vice-versa. O slider não
permite arrastar abaixo do piso da célula.

**Proteção contra CSRF.** O formulário usa um token por sessão, validado a cada salvamento —
sem ele, um link malicioso visitado por um admin logado poderia alterar as taxas sem o admin
perceber. Se a sessão expirar ou o token não bater, o salvamento é recusado com uma mensagem
pedindo para recarregar a página.

**Atalho na tela inicial do admin (opcional).** Para não depender de encontrar o addon dentro
de "Aplicativos e Integrações" toda vez, copie `includes/hooks/pagarme_fee_rates_widget.php`
para `/includes/hooks/` do seu WHMCS. Ele adiciona um card na Home do admin com um link direto
para a tela de taxas — carrega automaticamente, sem passo de ativação. O card só aparece para
administradores com acesso ao addon `pagarme_fee_rates` (mesma permissão de Admin Roles do
passo 3 acima); para o "Full Administrator" aparece sempre. O WHMCS não tem uma forma
documentada/estável de adicionar item ao menu superior do admin (só a Home tem uma API de
widget oficial), por isso o atalho é um card na Home, não uma entrada de menu.

Quando há pelo menos uma promoção ativa, o card mostra um selo ("N promoções ativas") — lembrete
rápido sem precisar abrir a tela e ler a tabela inteira.

## Observações importantes

- **PCI-DSS**: como o cartão é digitado diretamente no seu site (sem tokenização
  client-side), seu ambiente precisa estar em conformidade com PCI-DSS — HTTPS
  obrigatório em toda a aplicação e nenhum dado de cartão em log ou banco.

  Este é o modelo **escolhido deliberadamente** (decisão de 03/08/2026): a
  integração usa apenas a secret key, sem chave pública, então o cartão trafega
  pelo backend até o WHMCS, que tokeniza via `_storeremote`. **Não "corrigir"
  isso** trocando por tokenização no navegador sem antes rediscutir a decisão —
  o PAN cru em `_capture`/`_storeremote` é intencional aqui. A contrapartida é
  que os checkouts headless que encaminham o cartão entram no mesmo escopo PCI.
- **Parcelamento com juros por ciclo**: o teto de parcelas e a faixa sem juros
  dependem do ciclo de cobrança do plano (mensal só à vista; trimestral até 3x, todas
  com juros; semestral até 3x sem juros e 4x-6x com juros; anual/bienal até 5x sem
  juros e 6x-12x com juros; trienal até 6x sem juros e 7x-12x com juros). Acima da
  faixa sem juros, o juros (taxa MDR da Pagar.me, **sem** margem da loja) é repassado
  ao comprador e reconciliado como item na fatura. Detalhes completos das regras e dos
  arquivos envolvidos em `docs/parcelamento-com-juros.md`. Para ativar:
  1. Nas configurações do gateway, marque a opção de parcelamento.
  2. Adicione o seletor de parcelas ao checkout. Em temas que sobrescrevem os
     templates padrão (ex.: **Lagom / Smart Order Form**), use o hook pronto:
     copie `includes/hooks/pagarme_installments_selector.php` para `/includes/hooks/`.
     Ele injeta o seletor via JavaScript, sem editar templates, e sobrevive a
     atualizações do tema. Detalhes e a alternativa manual em
     `docs/seletor-parcelas-checkout.md`. Sem esse passo, o campo não chega ao módulo
     e todas as cobranças ficam à vista.

  A regra de "quando parcelar" é aplicada no servidor: mesmo que o cliente veja o
  seletor, o módulo cobra à vista se o parcelamento estiver desligado. Cobranças
  automáticas por cartão salvo (cron/recorrência) são sempre à vista.
- **Antifraude**: pedidos que retornam `processing` ficam pendentes no WHMCS até a
  confirmação chegar pelo webhook.

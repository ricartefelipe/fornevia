# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/)
e versionamento [SemVer](https://semver.org/lang/pt-BR/).

## [Não lançado]

### Adicionado

- Métricas Prometheus em `/metrics`, protegidas por token: requisições por
  tipo e status com histograma de duração, tamanho das filas, outbox
  pendente e idade da mensagem mais antiga, jobs com falha e conflitos de
  concorrência no banco.
- Tela de auditoria com filtros por ação, autor, registro e período.
- Testes de navegador (Dusk) dos fluxos de login, compra pelo catálogo e
  carrinho e lista de pedidos da equipe, na CI e no ambiente local.
- Testes de mutação do domínio na CI, com nota mínima de 90%.

- Dados de demonstração (`php artisan demo:seed`): dois distribuidores com
  equipe, catálogo, compradores e pedidos; recusado em produção.
- Implantação de referência `compose.prod.yaml` com imagens de produção,
  processos sem privilégios e sistema de arquivos somente leitura, testada
  na CI; runbooks de implantação, rotação de chave, backup e restauração,
  outbox, filas, webhooks, gateway e alertas, com regras do Prometheus.

### Alterado

- CNPJ alfanumérico da Receita Federal (a partir de julho de 2026) aceito
  no cadastro de distribuidores e compradores, na importação CSV, nas
  buscas e na API; os CNPJs numéricos continuam válidos.

## [0.4.0] - 2026-11-24

### Adicionado

- Contas a receber: faturas comerciais (não fiscais) do expedido, com o
  frete na primeira fatura e parcelas pela condição do pedido; pagamentos
  parciais, taxas, reembolsos, créditos e ajustes por lançamentos
  compensatórios; o faturamento transfere o comprometimento de crédito para
  recebível.
- Gateway de pagamento por distribuidor: Asaas e, fora de produção, um
  simulador local; cobrança criada depois do commit e sem repetição após
  timeout; webhook autenticado e deduplicado; conciliação agendada;
  pagamento de faturas pelo portal.
- Indicadores de ticket médio, conversão de propostas, margem e
  inadimplência, com as fórmulas exibidas.
- API REST v1 só de leitura (pedidos, compradores, SKUs, estoque, faturas)
  e registro de eventos de remessa, por cliente da API com permissões e
  token próprios; contrato OpenAPI 3.1 em `docs/openapi.json`.
- Webhooks enviados para pedidos, remessas, faturas, pagamentos e
  devoluções, assinados com HMAC-SHA256, com novas tentativas, envio de
  teste e histórico de entregas.
- Importação CSV de clientes, catálogo e estoque com prévia por linha e
  política "só as válidas" ou "tudo ou nada"; exportação CSV dos pedidos
  filtrados.

### Segurança

- Nenhum dado de cartão armazenado: o cartão é pago na página do gateway.
- Chave do gateway cifrada e token do webhook guardado só como hash; a
  configuração é invisível ao comprador.
- Reembolsos limitados ao valor pago também por CHECK no banco; reembolso
  sem resposta do gateway fica em verificação, sem repetir.
- Tokens da API guardados só como hash, com prazo, limite de requisições e
  permissões restritas a uma lista; segredo dos webhooks cifrado.
- Webhooks só para HTTPS em IP público conferido no envio, sem seguir
  redirecionamentos (proteção contra SSRF).
- Exportações CSV com neutralização de fórmulas de planilha.

## [0.3.0] - 2026-11-23

### Adicionado

- Relay da outbox (`outbox:relay`) com lease, `SKIP LOCKED`, backoff,
  mensagens sem novas tentativas reenviáveis (`outbox:retry`), retenção e
  situação (`outbox:status`); consumo idempotente por mensagem e
  consumidor.
- Notificações no sistema e por e-mail sobre cotações, aprovação
  comercial, aprovação interna e pedidos, para quem precisa agir, nunca
  para quem causou o evento; central de notificações na equipe e no
  portal, com contagem no cabeçalho e preferência de e-mail por contexto.
- Expedição: separação por depósito limitada à reserva, conferência,
  expedição parcial com transportadora e rastreamento consumindo a reserva,
  entrega e ocorrências por eventos idempotentes e reserva do saldo sob
  encomenda; telas da equipe e entregas no pedido do portal.
- Devoluções: pedido pelo comprador sobre o entregue, autorização ou recusa,
  recebimento e inspeção, com retorno ao estoque só do aprovado e valor a
  devolver registrado.
- Frete do distribuidor por regra da UF, com isenção por subtotal, ou por
  condição negociada da empresa, calculado no carrinho e no checkout.

### Segurança

- Notificações visíveis só à própria pessoa, criadas apenas no contexto de
  sistema, com link sempre interno; preferência de e-mail gravável só para
  a própria associação.
- Remessas, eventos de transporte, devoluções e frete somente leitura no
  contexto de comprador; somas por item garantidas por CHECK no banco.

### Alterado

- O cancelamento de itens do pedido não alcança o que está em separação.

## [0.2.0] - 2026-11-23

### Adicionado

- Cotações: solicitação pelo carrinho, propostas da equipe em versões
  imutáveis com preço do servidor e desconto avaliado pela política
  comercial, aprovação comercial vinculada à versão, reserva de estoque
  opcional com prazo, pedido de revisão, recusa, cancelamento, conversa e
  notas internas, aceite que gera o pedido com os preços negociados e
  expiração agendada; telas do portal e da equipe.
- Aprovação interna de compras: regras por valor, centro de custo, unidade,
  categoria e perfil do solicitante; níveis com quórum; delegação com
  prazo; reatribuição de nível bloqueado; reavaliação de preços, condição
  de pagamento e itens na aprovação final, com nova escolha da regra pelo
  novo total; fila de aprovação no portal.
- Orçamento mensal por centro de custo com reserva na submissão, consumo na
  confirmação, liberação na rejeição ou no cancelamento e estorno do
  consumo no cancelamento de pedido confirmado.
- Pedido aguardando aprovação e aprovado sem confirmação nas telas do
  portal e da equipe, com nova tentativa de confirmação e cancelamento.

### Segurança

- Comprador só cancela ou confirma novamente pedidos que pode ver.
- Pedidos, itens, eventos e todas as tabelas da aprovação interna (regras,
  delegações, níveis, decisões, orçamentos e movimentos) passam a ser
  somente leitura na conexão do portal; a gravação ocorre no contexto de
  sistema do mesmo usuário, após a autorização.
- Submissão para aprovação exige assinatura ativa do distribuidor.
- Decisão de aprovação vale somente para a versão do pedido exibida ao
  aprovador: depois de uma reavaliação ou da mudança de nível, a decisão
  feita na tela antiga é recusada.

### Atualização

- O administrador da empresa compradora dos distribuidores já existentes
  recebe a permissão de regras e delegações de aprovação
  (`portal.approval_rules`), por migration de dados.

## [0.1.0] - 2026-11-21

### Adicionado

- Fundação da plataforma: Laravel 13, PHP 8.5, Docker multi-estágio,
  Compose de desenvolvimento e CI.
- Multitenancy com RLS forçada, contexto local à transação e chaves
  estrangeiras compostas por tenant.
- Logs estruturados com correlação, CSP com nonce e health checks.
- Identidade e acesso: associações por contexto, papéis e permissões,
  convites, revogação imediata, troca de contexto, 2FA para perfis
  privilegiados e auditoria.
- Assinaturas da plataforma: planos configuráveis, trial e limites
  efetivos de usuários, filiais e pedidos.
- Interface Blade/Livewire em português com identidade visual própria.
- Harness de testes de concorrência com processos separados.
- Empresas compradoras: cadastro com CNPJ, grupos de clientes, unidades,
  endereços, centros de custo, contatos, condições de pagamento, usuários
  e histórico.
- Catálogo: produtos, SKUs com embalagem e conversão exata, categorias,
  unidades de medida, mídias privadas, custos internos, política comercial
  e catálogos privados por empresa ou grupo; catálogo do portal com preço
  e regra de compra.
- Preços: tabelas padrão, por grupo e por empresa com vigência,
  prioridade e faixas; condições negociadas; margem mínima padrão;
  simulador que explica a precedência; cache invalidado por versão.
- Entrada de quantidades em português nas telas de preço e limites de
  magnitude para valores e quantidades.
- Estoque por depósito com ledger de movimentações e saldo projetado no
  banco: entradas, ajustes, transferências, reservas e histórico.
- Crédito comercial: contas, limites, bloqueio, tolerância de vencidos,
  exceções temporárias e posição calculada pela mesma fórmula do checkout.
- Pedidos: confirmação transacional com reserva, crédito, cota do plano,
  eventos, outbox e idempotência; cancelamento total ou parcial; estados
  comercial, de aprovação, financeiro e de atendimento separados.
- Portal do comprador: carrinho, pedido rápido por SKU, listas de compra,
  recompra, checkout e meus pedidos.
- Pedidos da equipe com filtros, detalhe, custos restritos e carteira do
  representante.
- Gate da auditoria npm com exceções justificadas e revisadas.

### Alterado

- PostgreSQL de desenvolvimento sem reinício automático.
- Identificadores recebidos do cliente normalizados para minúsculas.

### Segurança

- Contexto de comprador sem leitura de catálogos privados de outros
  grupos, de registros de auditoria de terceiros e sem escrita nas
  configurações de preço do tenant (RLS).
- Envio temporário de mídias restrito a quem altera o catálogo.
- Reservas, movimentações de estoque e dados de crédito fora da leitura do
  contexto de comprador; numeração de pedidos por distribuidor.
- Margem mínima interna fora do estado enviado ao navegador de perfis
  somente de consulta.

[Não lançado]: https://github.com/ricartefelipe/fornevia/compare/v0.4.0...develop
[0.4.0]: https://github.com/ricartefelipe/fornevia/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/ricartefelipe/fornevia/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/ricartefelipe/fornevia/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/ricartefelipe/fornevia/releases/tag/v0.1.0

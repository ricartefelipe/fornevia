# Decisões arquiteturais

Registro no formato ADR (contexto, decisão, consequências). Decisões novas
entram no fim; decisões substituídas permanecem com o status atualizado.

---

## ADR-001 — Stack e versões

**Status:** aceita (2026-11-17)

**Contexto.** O produto precisa de uma stack estável, suportada e com
ecossistema maduro para autenticação, filas e testes.

**Decisão.**

| Componente | Versão fixada | Motivo |
|---|---|---|
| PHP | 8.5.12 (`~8.5.0`) | Linha estável mais recente; suporte ativo até 2027 |
| Laravel | 13.42 | Versão principal corrente |
| Livewire | 4.4.8 | Compatível com Laravel 13; build CSP-safe |
| Fortify | 1.42 | Autenticação oficial headless (senha, e-mail, 2FA) |
| Sanctum | 4.4 | Tokens revogáveis com abilities para integrações |
| Horizon | 5.51 | Supervisão de filas Redis |
| Pest | 5.3 (PHPUnit 13.4) | Testes; mutação e arquitetura integradas |
| Larastan / PHPStan | 3.15 / 2.3 | Análise estática nível 8, sem baseline |
| Dusk | 8.8 | Testes de navegador com JavaScript real |
| brick/money + brick/math | 0.15 / 1.0 | Aritmética decimal exata |
| PostgreSQL | 18.7 | RLS, exclusion constraints, `NULLS NOT DISTINCT` |
| Redis | 8.8.4 | Filas, cache e locks |
| Node (build) | 26.13.1 LTS | Vite 8.3, Tailwind 4.3 |
| Nginx | 1.31.7 (unprivileged) | Linha mainline: correções de segurança (HTTP/2, HTTP/3, TLS) sem versão estável equivalente publicada na imagem sem privilégios |

Imagens Docker e GitHub Actions são fixadas por versão exata (actions por
SHA de commit). Nenhuma tag `latest`.

**Consequências.** Atualizações passam por PR com a suíte completa.
`composer.lock` e `package-lock.json` são versionados.

---

## ADR-002 — Monólito modular sobre as convenções do Laravel

**Status:** aceita

**Decisão.** Um único deploy, com contextos delimitados como
subnamespaces dentro dos diretórios convencionais do Laravel:

```
app/Domain/<Contexto>     regras puras, value objects, sem framework
app/Actions/<Contexto>    casos de uso transacionais
app/Models/<Contexto>     Eloquent
app/Policies/<Contexto>   autorização por objeto
app/Http/...              controllers, Form Requests, Resources (API v1)
app/Livewire/<Área>       componentes de interface
app/Jobs/<Contexto>       processamento assíncrono
app/Integrations/<Prov.>  adaptadores externos
app/Support/...           infraestrutura técnica (tenancy, outbox, idempotência)
```

Fronteiras verificadas por testes de arquitetura (Pest `arch`): o domínio
não depende de Laravel, controllers não acessam o banco diretamente, etc.

**Consequências.** Sem repositórios genéricos: abstrações apenas onde
protegem fronteiras (gateway de pagamento, relógio, envio de webhooks).

---

## ADR-003 — Multitenancy em banco compartilhado com RLS

**Status:** aceita

**Contexto.** Cada distribuidor é um tenant. Dados comerciais não podem
vazar entre distribuidores, mesmo que vendam para a mesma empresa.

**Decisão.** Defesa em profundidade, em quatro camadas independentes:

1. **Escopo explícito**: `BelongsToTenant` aplica `TenantScope`, preenche
   `tenant_id` a partir do contexto e recusa tenant divergente. Sem
   contexto, a consulta lança exceção (não retorna dados).
2. **Policies** verificam a propriedade de cada objeto.
3. **Constraints**: toda tabela de tenant tem `unique (tenant_id, id)`; as
   chaves estrangeiras entre tabelas de tenant são compostas
   `(tenant_id, coluna) → (tenant_id, id)`. O banco recusa referência a
   registro de outro tenant.
4. **RLS** habilitada e **forçada** em toda tabela com `tenant_id`:
   `tenant_id = app_current_tenant()`; tabelas de comprador recebem uma
   política *restritiva* adicional por empresa compradora.

O papel `fornevia_app` não é superuser, não tem `BYPASSRLS` e não é dono
das tabelas (dono: `fornevia_owner`, usado só por migrations).

O contexto é aplicado com `set_config(..., true)`, **local à transação**,
por `TenantDatabase::run()`. No `COMMIT`/`ROLLBACK` os valores deixam de
existir; uma conexão reaproveitada (worker) volta ao estado sem contexto,
em que nenhuma linha protegida é visível (fail-closed).

Testes de schema garantem que toda tabela com `tenant_id` tenha RLS
forçada e que nenhuma FK entre tabelas de tenant omita `tenant_id`.

**Consequências.**
- Toda consulta de dados de tenant ocorre dentro de transação com
  contexto. Requisições web autenticadas rodam em uma transação curta com
  o contexto validado contra a associação (membership) ativa.
- Chamadas externas não ocorrem dentro dessas transações: jobs dividem o
  trabalho em fases (ler/registrar → chamar provedor → registrar resultado).
- Tabelas de identidade (`users`, `organizations`, `api_clients`, tokens,
  sessões) ficam fora da RLS por serem necessárias antes da escolha do
  contexto; não contêm dados comerciais e são acessadas via policies.
- A RLS pressupõe que o código da aplicação é confiável para definir o
  contexto; o contexto nunca é lido de entrada do cliente sem validação
  contra a identidade autenticada.

---

## ADR-004 — Dinheiro e quantidades

**Status:** aceita

**Decisão.**
- Moeda única na versão 1: BRL.
- Valores monetários persistidos em **centavos inteiros** (`bigint`).
- Cálculos intermediários com `brick/math` (`BigDecimal`), nunca `float`;
  colunas `numeric` são lidas como string e convertidas para `BigDecimal`.
- Quantidades em `numeric(18,3)`; fatores de conversão em `numeric(18,6)`.
- Percentuais em pontos-base (1 bp = 0,01%) inteiros.
- Arredondamento: `HALF_UP` para centavos no **preço unitário líquido**;
  o total da linha é `preço unitário arredondado × quantidade`, arredondado
  `HALF_UP` ao centavo. Totais do documento são somas das linhas (sem
  rateio implícito).

**Consequências.** Valores exibidos e persistidos reconciliam sem resíduos;
qualquer rateio (frete, descontos de cabeçalho) é explícito e documentado.

---

## ADR-005 — Ledger com projeções mantidas pelo banco (estoque e crédito)

**Status:** aceita

**Contexto.** Saldos de estoque e exposição de crédito não podem ser
editados sem registro correspondente nem divergir do histórico.

**Decisão.**
- Movimentos (`stock_movements`, `credit_movements`) são append-only:
  o papel da aplicação não tem `UPDATE`/`DELETE`.
- Cada movimento carrega deltas explícitos (`on_hand_delta`,
  `reserved_delta`; `committed_delta`, `receivable_delta`).
- A projeção (`stock_levels`, `credit_accounts`) é atualizada por trigger
  no `INSERT` do movimento, na mesma transação. A aplicação não tem
  `INSERT`/`UPDATE` nas colunas de saldo da projeção.
- Invariantes em `CHECK`: `on_hand >= 0`, `reserved >= 0`,
  `reserved <= on_hand`, `committed >= 0`, `receivable >= 0`.
- A decisão de negócio (há saldo? há limite?) é tomada explicitamente na
  aplicação, sob `SELECT ... FOR UPDATE`, antes de inserir o movimento.
  As constraints são a rede de segurança.

**Consequências.** O trigger é um aplicador de deltas sem regra de negócio
oculta; regras ficam em Actions testáveis. Correções são movimentos
compensatórios.

---

## ADR-006 — Ordem de aquisição de locks

**Status:** aceita

Toda operação que bloqueia mais de um recurso segue a ordem:

0. gestão de acessos: associações de proprietário ativas (por `id`) antes
   da associação alvo; assinatura do tenant (limites do plano) antes de
   inserir convites ou filiais;
1. chave de idempotência, quando a operação tem uma (o checkout grava e
   bloqueia a chave antes de bloquear o carrinho);
2. documento de origem e, depois dele, as linhas do próprio documento
   (itens, níveis de aprovação, versões e revisão comercial da cotação):
   carrinho → cotação → versões da cotação (numa única consulta ordenada
   por `id`). Operações que partem de um pedido gerado de cotação bloqueiam
   o pedido e, logo depois, a cotação e a versão de origem; nenhuma
   operação que parte da cotação bloqueia um pedido existente;
3. regras de aprovação da empresa: lock consultivo compartilhado na
   escolha da regra (submissão e reavaliação), exclusivo na alteração;
   delegações do aprovador, em modo compartilhado, na decisão;
4. saldos de estoque, ordenados por `(warehouse_id, sku_id)`, incluindo
   numa mesma consulta os saldos das reservas liberadas na operação (troca
   ou transferência da reserva de uma cotação); depois, as reservas;
5. conta de crédito da empresa compradora;
6. orçamento do centro de custo;
7. contador de uso do plano;
8. contadores de numeração do tenant (pedidos e cotações).

Operações que tocam saldos de vários itens (reserva, cancelamento) bloqueiam
todos os saldos envolvidos de uma vez, na ordem do item 4, antes de
percorrer os itens. A decisão de aprovação, o cancelamento, a nova
tentativa e a reatribuição bloqueiam o pedido (item 2) antes dos níveis e
decisões. A alteração de regras e a revogação de delegações não bloqueiam
pedidos, estoque, crédito nem orçamento.

Ordem consistente evita deadlocks entre confirmações concorrentes. O
teste de concorrência `LastOwnerRaceTest` reproduziu um deadlock quando a
associação alvo era bloqueada antes do conjunto de proprietários; a ordem
acima corrigiu o problema.

---

## ADR-007 — Idempotência persistida no PostgreSQL

**Status:** aceita

Chave por `(tenant_id, escopo, chave)`, com fingerprint SHA-256 do corpo
canônico, status e resposta armazenada. Reutilização com fingerprint
diferente gera `409 idempotency_key_reused`. Escopos em uso:
`order.place`, `quote.request`, `quote.version`, `quote.counteroffer` e
`quote.accept`. A chave é gravada na mesma
transação do efeito. Proteções independentes de expiração permanecem:
`unique` em `orders.source_cart_id`, `orders.source_quote_version_id`,
estados terminais verificados sob lock.

---

## ADR-008 — Transactional Outbox

**Status:** aceita

Eventos de negócio são gravados em `outbox_messages` na mesma transação da
alteração. Um relay dedicado (papel `fornevia_relay`, com acesso apenas às
tabelas da outbox) reivindica mensagens com `FOR UPDATE SKIP LOCKED` e
lease, despacha jobs para o Redis e registra a publicação. Consumidores são
idempotentes (`outbox_consumptions` com unique por mensagem e handler).
Entrega é *at-least-once*; não há promessa de exatamente uma vez.
Relay, consumo e notificações: ADR-026.

---

## ADR-009 — Estados separados no pedido

**Status:** aceita

O pedido possui estados independentes: comercial, aprovação interna do
comprador, atendimento logístico e financeiro. Quantidades por item
(pedida, cancelada, expedida, entregue, devolvida) são colunas com
`CHECK` de consistência.

---

## ADR-010 — Faixas de quantidade aplicam-se a todas as unidades

**Status:** aceita

A faixa atingida pela quantidade total da linha define o preço unitário de
**todas** as unidades (preço por volume), não apenas das unidades dentro da
faixa. É o modelo usual em distribuição e é o exibido ao comprador.

---

## ADR-011 — Precedência de preços

**Status:** aceita

1. Condição negociada vigente para a empresa e o SKU;
2. tabela específica da empresa compradora;
3. tabela do grupo de clientes;
4. tabela padrão.

Dentro do mesmo nível: maior `priority`, depois `valid_from` mais recente,
depois a tabela criada primeiro (UUIDv7). Sobreposição de vigência de
condições negociadas para o mesmo par empresa/SKU é proibida por
*exclusion constraint*.

---

## ADR-012 — Ambiente local em worktree e engine Docker nativo

**Status:** aceita (nota operacional)

O repositório pode residir em sistemas de arquivos sem suporte a symlinks
ou bits de execução (ex.: vfat). Nesses casos, recomenda-se trabalhar em um
`git worktree` em sistema de arquivos POSIX. O `compose.yaml` usa bind
mount do diretório do projeto; o Docker Desktop precisa ter o diretório
compartilhado nas configurações de *File sharing*.

---

## ADR-013 — Contexto revalidado a cada requisição

**Status:** aceita

**Decisão.** A sessão guarda apenas a referência do contexto escolhido
(membership, tenant, tipo, empresa compradora). `EstablishRequestContext`
roda antes do route model binding e, dentro da transação da requisição,
recarrega a associação exigindo: mesmo id, mesmo usuário, status ativo,
tipo e empresa idênticos aos da sessão e organização com acesso permitido.
Qualquer divergência limpa o contexto e redireciona para a seleção.

A requisição autenticada roda em uma transação curta com o contexto local.
Como o pipeline do Laravel converte exceções em respostas antes de devolver
ao middleware, uma resposta que carrega exceção provoca rollback explícito
(`RollbackWithResponse`). Chamadas externas não ocorrem nessa transação.

**Consequências.** Revogação de acesso e suspensão de organização valem na
requisição seguinte, sem depender de expiração de sessão. A troca de
contexto regenera o identificador da sessão.

---

## ADR-014 — Elevação controlada para contextos do mesmo usuário

**Status:** aceita (revisada após testes: a primeira versão só permitia
elevar a partir do contexto `User`, o que quebrava troca de contexto,
aceite de convite e onboarding quando já havia contexto ativo na sessão)

**Contexto.** Seleção de contexto, aceite de convite e onboarding precisam
ler ou gravar dados de tenants aos quais o próprio usuário pertence (ou
passará a pertencer), inclusive quando a requisição já roda no contexto de
outro tenant do mesmo usuário.

**Decisão.** Dentro de uma transação aberta, `TenantDatabase::run()` só
aceita outro contexto se ambos tiverem o **mesmo usuário (não nulo)**. A
entrada ocorre em savepoint; ao final, as configurações do contexto anterior
são reaplicadas (em falha, o rollback do savepoint as restaura). Contextos
sem usuário (jobs, sistema) e contextos de outro usuário são recusados com
`TenantContextConflict`.

Salvaguardas: `Access::can()` nega qualquer permissão quando o contexto
ativo não é o da associação vinculada (portanto, nada é autorizado "por
herança" durante uma elevação), e a auditoria registra a associação do
contexto da transação, nunca a do contexto externo.

---

## ADR-015 — Assinatura da plataforma separada das vendas do tenant

**Status:** aceita

**Decisão.** Planos, assinaturas, módulos e auditoria da plataforma usam
`organization_id` e tabelas próprias, administradas pela plataforma. Não há
tabela compartilhada com pedidos, faturas comerciais ou contas a receber do
distribuidor. Limites efetivos: usuários da equipe (ativos + convites
pendentes) e filiais sob lock da assinatura; pedidos por mês por incremento
condicional; módulos incluídos ou adicionais. Valores comerciais iniciais
são hipóteses de produto configuráveis.

---

## ADR-016 — Autenticação com Fortify e 2FA para perfis privilegiados

**Status:** aceita

**Decisão.** Fortify fornece login, cadastro, verificação de e-mail,
redefinição de senha e TOTP com confirmação. Perfis com permissões
`organization.manage`, `members.manage`, `credit.manage`, `finance.manage`,
`integrations.manage` ou `subscription.manage`, e administradores da
plataforma, só acessam o contexto com 2FA confirmado (`RequireTwoFactor`).
Senhas: mínimo de 12 caracteres com letras e números e verificação de
vazamento (desligável por configuração em testes).

---

## ADR-017 — Medição de cobertura

**Status:** aceita

**Decisão.**
- **Linhas** (meta ≥ 90% em `app/Domain` + `app/Actions`): suíte completa
  com PCOV na CI, relatório Cobertura e `scripts/coverage-gate.php --lines`.
- **Branches** (meta ≥ 80% nos caminhos críticos): regras de domínio de
  preços, catálogo, pedidos, crédito, estoque, aprovações e financeiro
  (`app/Domain/...`), medidas com Xdebug `--path-coverage` sobre os testes
  unitários puros, em job próprio, e `coverage-gate.php --branches`.
- O gate falha se houver código no núcleo e o relatório não contiver linhas
  dele; exclusões só por lista explícita e justificada no script.

**Motivo.** Cobertura de caminhos com Xdebug sobre a suíte de integração
multiplicava o tempo do job; as regras de decisão críticas ficam no
domínio puro, onde a medição de branches é rápida e significativa.

---

## ADR-018 — Entrada numérica localizada e limites de magnitude

**Status:** aceita

**Decisão.**
- `Money` e `Quantity` recusam valores além das colunas que os persistem
  (centavos até 10¹⁵; quantidades com até 15 dígitos inteiros) com
  `AmountOutOfRange`, antes de qualquer conversão para inteiro.
- Telas de preço e o portal interpretam quantidades em pt-BR
  (`Quantity::parseLocalized`): ponto agrupando milhares em blocos de três
  dígitos, com primeiro grupo sem zero à esquerda, é separador de milhar;
  vírgula é decimal; `0.500` é decimal.
- O cadastro de SKU mantém entrada estrita (vírgula decimal, sem ponto),
  porque fatores de conversão com três casas (`1.000`) seriam ambíguos.

**Motivo.** "1.000" lido como 1 em uma faixa de preço publicava o preço de
volume para qualquer quantidade; valores enormes chegavam ao banco como
estouro numérico (erro 500) em vez de erro de campo.

---

## ADR-019 — Embalagem do SKU fixa após existir preço

**Status:** aceita

**Decisão.** Unidade de venda e fator de conversão de um SKU não mudam
quando há item de tabela de preço ou condição negociada ativa para ele.
Uma nova embalagem é cadastrada como novo SKU. As Actions de preço leem o
SKU com lock compartilhado, serializando com a alteração do SKU, e recebem
a embalagem vista quando o formulário foi aberto: se ela mudou nesse
intervalo (SKU ainda sem preço), a gravação é recusada.

**Motivo.** Preços, faixas e condições estão na unidade de venda; trocar a
embalagem mudaria silenciosamente o significado comercial de todos eles.

---

## ADR-020 — Dados comerciais internos fora do alcance do comprador no banco

**Status:** aceita

**Decisão.** Dados internos (custos, política comercial do SKU e margem
mínima padrão) ficam em tabelas com política restritiva `internal_only`,
nunca em colunas de tabelas que o portal lê: `pricing_policies` separada de
`pricing_settings` (que o portal lê para a versão de preços). A auditoria
do tenant não é legível no contexto de comprador. O cache de preços, que
não passa pelo RLS, recusa cálculo para outra empresa nesse contexto.

**Motivo.** O RLS é a segunda barreira: mesmo com um defeito de
autorização na aplicação, a conexão do portal não deve alcançar dados
internos ou de outras empresas.

---

## ADR-021 — Exceções explícitas na auditoria de dependências npm

**Status:** aceita

**Decisão.** A CI reprova qualquer alerta npm de severidade moderada ou
maior, exceto os registrados em `.github/npm-audit-exceptions.json` com
alerta, pacote, escopo, justificativa e data de revisão. O gate
(`scripts/npm-audit-gate.mjs`) também reprova exceção vencida, exceção que
não corresponde mais a nenhum alerta e relatório com erro.

**Motivo.** Alertas sem versão corrigida publicada em ferramentas de build
(caso do `picomatch`, usado por Vite e pelo plugin do Laravel) bloqueariam
toda entrega sem reduzir risco: essas dependências não fazem parte da
imagem de produção, que contém apenas os assets compilados. A exceção é
pontual, justificada e revista periodicamente; qualquer outro alerta
continua reprovando a CI.

---

## ADR-022 — Numeração de pedidos por distribuidor

**Status:** aceita

**Decisão.** O número do pedido (`PED-0000001`) vem de um contador por
tenant (`order_number_counters`), incrementado no fim da confirmação, depois
de estoque, crédito e contador de uso do plano. Até lá o pedido usa um
número provisório único.

**Motivo.** Uma sequência global revelaria a cada distribuidor o volume de
pedidos dos demais. Incrementar o contador no fim mantém o lock por tenant
apenas no trecho final, que já é serializado pelo contador de uso do plano,
sem nova contenção nas etapas de estoque e crédito.

---

## ADR-023 — Aprovação interna de compras e orçamento por centro de custo

**Status:** aceita

**Decisão.**
- A regra aplicável é a regra ativa de maior prioridade (empate: a criada
  primeiro) cujos critérios informados são todos atendidos: valor mínimo,
  centro de custo, unidade, categoria de algum item e perfil do solicitante.
- Com regra aplicável, o checkout grava o pedido aguardando aprovação, com
  os níveis e aprovadores retratados (editar a regra não muda pedidos já
  submetidos), reserva o orçamento do centro de custo e atribui o número.
  Estoque e crédito só são reservados na confirmação, porque a aprovação
  interna não os garante (RN-056).
- Níveis são decididos em sequência; cada um exige quórum de titulares
  distintos; o solicitante nunca conta, nem como delegado; rejeição em
  qualquer nível encerra o pedido e libera o orçamento.
- Delegação com prazo: o delegado decide em nome do titular, registrando
  ator e titular. Aprovador revogado ou sem permissão deixa de ser
  elegível; nível sem aprovadores elegíveis suficientes é reatribuído pela
  administração da empresa (`portal.approval_rules`).
- Na aprovação final o pedido é reavaliado (RN-054, RF-061). Empresa sem
  permissão de compra, condição de pagamento não mais permitida, endereço
  inativo, item invisível para a empresa, inativo ou fora das regras de
  compra, ou item sem preço vigente deixam o pedido aprovado aguardando
  confirmação, com o motivo. Preço vigente diferente do aprovado, definição
  da condição de pagamento alterada (prazos ou exigência de crédito) ou
  pedido diferente da impressão gravada na submissão invalidam as
  aprovações (histórico preservado): o pedido é reprecificado, a regra é
  escolhida de novo pelo novo total e, se outra regra passar a valer, os
  níveis são substituídos pelos dela (o retrato anterior fica marcado como
  substituído). Se nenhuma regra valer, os níveis retratados continuam
  exigidos: a reavaliação não dispensa aprovação.
- A decisão e a reatribuição valem para a versão do pedido exibida a
  quem decide (nível em análise e impressão do pedido): se o pedido foi
  reavaliado ou outro nível entrou em análise desde a exibição, são
  recusadas e exigem nova revisão. A impressão decidida fica registrada em
  cada decisão.
- Recusa de estoque, crédito, orçamento ou cota na confirmação deixa o
  pedido aprovado, sem confirmação, com o motivo registrado e nova
  tentativa (auditada).
- Cancelamento durante a aprovação encerra a aprovação (situação
  "encerrada") e os níveis sem decisão; cancelamento de pedido confirmado
  estorna o consumo do orçamento, proporcional ao valor cancelado.
- Orçamento mensal por centro de custo mantido por movimentos (reserva,
  liberação, consumo, estorno de consumo) com projeção por trigger e
  reservado + consumido <= valor garantido no banco.
- Integridade no banco: chaves estrangeiras compostas incluem a empresa
  (nenhuma regra, nível, decisão, orçamento ou movimento aponta para
  registro de outra empresa do mesmo distribuidor); decisão em nome de
  outro titular só com delegação; a invalidação de uma decisão é definitiva
  e única; o nível retratado não muda de pedido, número nem quórum, e
  situações encerradas não voltam.

**Motivo.** Separar a aprovação interna da confirmação com o distribuidor
evita reservar estoque e crédito por dias à espera de aprovadores e deixa
claro ao comprador o que a aprovação garante. O retrato dos níveis e a
reavaliação tornam o aceite rastreável e vinculado ao que foi aprovado.

---

## ADR-024 — Pedidos e aprovação somente leitura no contexto de comprador

**Status:** aceita

**Decisão.** Pedidos, itens e eventos, e todas as tabelas da aprovação
interna (regras, níveis e aprovadores das regras, delegações, níveis e
decisões do pedido, orçamentos e movimentos de orçamento), são somente
leitura nos contextos de comprador e de usuário (políticas restritivas de
inserção, alteração e exclusão). As Actions do portal autorizam no
contexto de comprador (aprovador, solicitante ou administração da empresa,
conforme a permissão) e gravam no contexto de sistema do mesmo usuário
(`TenantDatabase::asSystem`, ADR-014); dentro dele as consultas filtram a
empresa explicitamente. O `SELECT ... FOR UPDATE` dessas tabelas também
acontece no contexto de sistema, porque a política de alteração se aplica
ao bloqueio. A auditoria continua no contexto de quem agiu, com a
associação do comprador.

**Motivo.** A aprovação interna e a confirmação decidem pela situação do
pedido, pelo solicitante, pelos níveis e pelas decisões; regras,
delegações e orçamentos decidem quem aprova, inclusive pedidos já
submetidos (delegação vigente e nova escolha de regra na reavaliação), e
quanto cabe no mês. Com essas tabelas graváveis pela conexão do portal, um
único defeito de autorização bastaria para autoaprovar, aprovar no lugar
de outro, liberar orçamento ou confirmar sem aprovação. Como em estoque e
crédito (ADR-020), o banco é a segunda barreira.

**Consequência.** As associações da empresa compradora continuam
graváveis no contexto de comprador (gestão de usuários do portal); a
mesma restrição para elas fica para a revisão de identidade e acesso. As
tabelas de cotação (ADR-025) seguem a mesma regra.

---

## ADR-025 — Cotações com versões imutáveis, aceite que gera o pedido, aprovação comercial e reserva com prazo

**Status:** aceita

**Decisão.**
- A proposta é uma versão da equipe, imutável desde a inserção: os
  privilégios de coluna permitem alterar apenas a situação, e os itens são
  append-only e conferidos com os totais no COMMIT.
- Por cotação há no máximo uma versão em preparo, uma com o comprador e
  uma aceita. A numeração (v1, v2...) só é atribuída no envio, sem lacunas.
- Mudança relevante só acontece por nova versão, que substitui a vigente
  e encerra a reserva dela.
- Solicitação e contraproposta são registros append-only do comprador,
  com itens e quantidades, nunca preço. O preço vem do `PricingService`
  mais o desconto informado pela equipe, recalculado no servidor.
- Aceitar é converter: na mesma transação, a versão exibida (id,
  impressão e situação da reserva, conferidos sob o lock da cotação) fica
  aceita e o pedido nasce pelo caminho do checkout, com aprovação interna,
  orçamento, estoque, crédito, cota e numeração.
- O pedido guarda `source_quote_version_id` (único, com chave por empresa
  e trigger) e os preços da versão. A aprovação interna não reprecifica
  pedidos de cotação. A cláusula "invalida aceite anterior" da RN-040 é
  atendida porque a versão aceita encerra a cotação e uma versão
  substituída não pode ser aceita.
- A aprovação comercial é exigida por qualquer violação da política de
  desconto (limite do papel do autor, preço mínimo, custo, margem). Fica em
  registro interno 1:1 com a versão. Decide quem tem `pricing.approve` com
  limite maior ou igual ao maior desconto. A autoaprovação, só na
  submissão e com justificativa, fica registrada.
- A reserva de estoque é opcional, tudo ou nada, com prazo até a validade
  e no máximo 7 dias, no ledger (`stock_reservations.quote_item_id`,
  `expires_at`). É liberada por prazo, substituição, contraproposta,
  recusa, encerramento ou fim do pedido sem confirmação, e transferida na
  confirmação sob o mesmo lock dos saldos.
- As tabelas de cotação são somente leitura no contexto de comprador
  (ADR-024). A validade é conferida sob lock em toda ação; o job de
  expiração só registra o vencimento e libera reservas, com `SKIP LOCKED`.

**Motivo.** RN-040 a RN-044 exigem rastrear exatamente o que foi aceito e
aprovado mesmo com o catálogo mudando. Versões mutáveis permitiriam
aceitar uma coisa e converter outra; a reprecificação na aprovação final
apagaria o desconto negociado. Aceitar convertendo elimina o estado
"aceita sem pedido" e reaproveita a atomicidade do checkout (RF-061,
RN-060). Política e custo ficam fora do alcance do comprador (ADR-020), e o
ledger mantém um só saldo verdadeiro (ADR-005).


---

## ADR-026 — Relay da outbox, consumidores idempotentes e notificações

**Status:** aceita

**Decisão.**
- O relay (`php artisan outbox:relay`, papel `fornevia_relay`) reivindica
  lotes de mensagens pendentes e disponíveis com `FOR UPDATE SKIP LOCKED`,
  gravando lease (dono e prazo) e tentativa numa transação curta. Fora dela,
  despacha um job por mensagem para a fila declarada pelos consumidores do
  tipo e marca a mensagem como publicada, conferindo o dono do lease. Lease
  vencido volta a ser reivindicado; mensagem sem consumidor é marcada como
  publicada sem despacho.
- Falha ao despachar fica em `outbox_failures` (etapa `publish`), com nova
  disponibilidade por backoff exponencial limitado. Depois do máximo de
  tentativas, a mensagem fica `dead`, consultável e reenviável por comando
  (`outbox:retry`).
- O consumo roda no worker, no contexto de sistema do tenant da mensagem.
  Cada consumidor roda em transação própria e registra
  `outbox_consumptions` (único por mensagem e consumidor) antes de agir:
  repetição da mensagem não repete o efeito. Falha desfaz o consumo; o job
  tenta de novo com backoff e, esgotado, registra a falha (etapa
  `consume`) e deixa a mensagem `dead`, reenviável por `outbox:retry`.
- Destinatário precisa ainda ter a permissão da tela do aviso; aprovadores
  que já decidiram o nível e os delegados deles não são avisados.
- Notificações são um consumidor: os destinatários são calculados no
  consumo, pelo estado atual (aprovadores do nível pendente com delegações
  vigentes, solicitante da cotação, representante da carteira ou quem tem a
  permissão). A notificação no sistema (`user_notifications`, única por
  mensagem e associação) é visível só ao próprio usuário (RLS) e só tem a
  data de leitura gravável. O e-mail sai depois do commit, uma vez por
  notificação criada, salvo se a pessoa desligou o e-mail no contexto.
- Eventos do pedido que pedem ação de alguém (envio para aprovação, nível
  aprovado, reavaliação, reatribuição, rejeição, confirmação pendente) são
  registrados também na outbox, na mesma transação do evento.
- O próprio relay apaga, periodicamente, mensagens publicadas além do prazo
  de retenção; mensagens `dead` ficam até serem reenviadas ou analisadas.

**Motivo.** O ADR-008 garante que nenhum evento se perde entre o commit e a
publicação, mas sem relay e consumidores os eventos não chegam a ninguém.
Lease com `SKIP LOCKED` permite mais de um relay sem publicação dupla no
caminho normal; a entrega continua pelo menos uma vez, e a idempotência
fica no consumidor. Calcular destinatários no consumo evita notificar quem
perdeu a permissão, e a tabela de notificações do próprio sistema mantém o
isolamento por tenant e por pessoa.

---

## ADR-027 — Remessas que consomem a reserva, entrega por eventos idempotentes, devolução com inspeção e frete explícito

**Status:** aceita

**Decisão.**
- A remessa pertence a um pedido confirmado e a um depósito. Nasce em
  separação, com itens e quantidades; a conferência registra a quantidade
  conferida de cada item; a expedição grava transportadora e rastreamento.
- Na expedição, sob o lock do pedido e dos saldos (ADR-006), a quantidade
  conferida consome as reservas ativas do item naquele depósito
  (movimento `shipment`, que baixa físico e reservado juntos). Quantidade
  sob encomenda sem reserva precisa ser reservada antes, pelo caminho
  normal da reserva. A remessa expedida não volta a ser expedida (situação
  e trigger) e o pedido passa a parcialmente expedido ou expedido.
- A soma das remessas não canceladas de um item nunca passa do pedido
  menos o cancelado (trigger), e o cancelamento de itens só alcança o que
  ainda não está em remessa.
- Entrega e ocorrências chegam como eventos da remessa, com identificador
  externo único por remessa: evento repetido é ignorado. A situação só
  avança (expedida → entregue); evento mais antigo que o último aplicado
  fica no histórico sem mudar a situação. A entrega soma a quantidade
  entregue de cada item.
- Devolução é pedida pelo comprador sobre o que foi entregue (descontado o
  que já está em devolução aberta), autorizada ou recusada pela equipe,
  recebida no depósito e, quando a inspeção é exigida, só volta ao
  disponível depois de aprovada (movimento `return_receipt`). Item
  reprovado não volta ao estoque. O valor a devolver fica registrado para o
  financeiro.
- Frete é explícito no pedido (`freight_cents`), calculado no checkout por
  regra do distribuidor (por UF do endereço de entrega, com valor fixo e
  isenção a partir de um subtotal) ou por condição negociada da empresa
  compradora (isento ou valor fixo), e exibido como frete do distribuidor,
  nunca como cotação de transportadora. Na reavaliação da aprovação
  interna, que reprecifica os itens, o frete é recalculado pela regra
  vigente para o novo subtotal. Pedido de cotação usa o frete da proposta
  (zero quando não negociado).
- O cancelamento de itens não libera a parte da reserva comprometida com
  remessas em separação de cada depósito.
- Tabelas de expedição e devolução são somente leitura no contexto de
  comprador (ADR-024); o pedido de devolução do comprador é gravado no
  contexto de sistema do mesmo usuário.

**Motivo.** RN-073 exige que a expedição consuma a reserva e reduza o
físico atomicamente, sem duplicidade; consumir a reserva no ledger mantém
um único saldo verdadeiro (ADR-005). RN-090 é garantida no banco pelos
CHECKs por item e pelas somas das remessas. Eventos de transportadora
chegam repetidos e fora de ordem (RN-091): identificador único e avanço
monotônico da situação evitam regressões. A inspeção antes do retorno ao
disponível (RF-091) impede vender item avariado. O frete calculado no
servidor e rotulado como do distribuidor atende RF-092 sem prometer preço
de transportadora.

---

## ADR-028 — Contas a receber por lançamentos, gateway sem cobrança repetida e webhooks deduplicados

**Status:** aceita

**Decisão.**
- A fatura é comercial (não fiscal) e cobre o expedido ainda não faturado
  de um pedido confirmado, com o frete na primeira fatura. As parcelas
  seguem a condição de pagamento retratada no pedido, com a diferença de
  centavos na última.
- Na mesma transação da fatura, o comprometimento de crédito do valor
  faturado vira recebível num único movimento (RN-081). Só pagamento
  confirmado reduz o recebível (RN-083).
- Parcela e fatura guardam valor, ajustes, pago e creditado. Toda mudança
  passa por um único serviço, que grava o lançamento financeiro (somente
  inclusão), move o recebível no crédito e recalcula a situação financeira
  do pedido. Correção é sempre lançamento compensatório: crédito concedido
  ou ajuste com motivo (RN-100).
- Reembolso só de pagamento confirmado. O valor é reservado como pendente
  sob o lock do pagamento antes de qualquer chamada ao gateway. A soma dos
  pendentes e efetivados nunca passa do pago, por regra e por CHECK
  (RN-101). Efetivado, o valor volta a ser devido na parcela.
- O gateway é escolhido por distribuidor: Asaas ou, só fora de produção, o
  simulador local. A chave fica cifrada e o token do webhook, só como hash.
  Cada pagamento continua no gateway que o criou: reembolso, cancelamento e
  conciliação usam esse gateway, e o token vale mesmo com outro ativo.
- A cobrança pendente é gravada na transação da requisição, com o próprio id
  como referência externa. A chamada ao gateway roda num job depois do
  commit, sem segurar locks. O Asaas é consultado pela referência antes de
  criar e de novo quando a criação esgota o tempo: timeout depois de criada
  não gera outra cobrança (RF-101). Gateway indisponível deixa o pagamento
  pendente com o motivo, e a conciliação cria a cobrança ou aplica a
  situação depois.
- Uma cobrança pendente por parcela, do saldo em aberto e na forma escolhida.
  Mudou o saldo ou a forma, ela é cancelada aqui e no gateway. Vencida
  continua pendente, porque ainda pode ser paga. Juros e multa recebidos
  entram como ajuste da parcela. O que for pago além do saldo, ou numa
  parcela já quitada, fica como valor não aplicado, reembolsável sem reabrir
  a parcela.
- O reembolso pelo gateway também roda em job depois do commit. Recusado,
  a reserva é desfeita; sem resposta, fica em verificação com a reserva
  mantida, até a equipe concluir depois de conferir no painel do gateway.
- Cartão é pago na página do gateway; nenhum dado de cartão chega ao
  Fornevia (RS-100).
- Eventos recebidos (webhook do Asaas, autenticado pelo token do
  distribuidor, e página do simulador) são gravados uma vez por provedor e
  identificador e processados pela fila. Pagamento já confirmado ou
  encerrado não muda com evento repetido.
- Lock: pedido → fatura → parcela → pagamento → conta de crédito →
  contador.

**Motivo.** Saldos derivados de lançamentos tornam cada centavo rastreável e
impedem correções destrutivas. Reservar o reembolso antes do gateway fecha
a janela de dois reembolsos simultâneos. Procurar pela referência antes de
criar é a única forma de tolerar timeout sem cobrar duas vezes, já que o
gateway pode ter criado a cobrança sem responder. A configuração por
distribuidor mantém o isolamento entre tenants também no recebimento.

## ADR-029 — API por cliente com permissões próprias, webhooks assinados contra SSRF e importação CSV em duas etapas

**Status:** aceita

**Decisão.**
- Cada sistema externo é um cliente da API do distribuidor, com as
  permissões concedidas a ele (só leitura de pedidos, compradores,
  catálogo, estoque e financeiro, e o registro de eventos de remessa). O
  token do Sanctum (`id|fvk_…`, guardado só como hash SHA-256) tem prazo
  de 1 a 365 dias e é mostrado uma única vez. A busca do token, antes de
  haver contexto, usa uma função `security definer` que devolve só o
  necessário para conferi-lo; depois, a requisição roda no contexto de
  integração do tenant, com as permissões do cliente. Limite de 300
  requisições por minuto por cliente.
- A API v1 é só de leitura, com filtros declarados por recurso (filtro
  desconhecido é erro 422), paginação de até 100 registros e erros no
  mesmo formato das telas. O contrato fica em `docs/openapi.json`, e um
  teste confere rotas e enums contra ele.
- Webhooks enviados saem da outbox: um consumidor cria uma entrega por
  evento e endpoint (identificador estável, sem duplicar) e a fila
  `integrations` envia. O corpo traz só os campos públicos de cada evento.
  A assinatura é `t=<carimbo>,v1=HMAC-SHA256(segredo, "carimbo.corpo")`,
  com o segredo cifrado no banco e mostrado só na criação ou na troca.
- Contra SSRF, o endereço precisa ser HTTPS (HTTP só fora de produção),
  sem credenciais, e o nome é resolvido antes do envio: rede privada,
  loopback, link-local e faixas reservadas são recusadas. O envio usa o
  IP já conferido (`CURLOPT_RESOLVE`) e não segue redirecionamentos.
- Falha agenda nova tentativa com espera crescente (1 min a 24 h, até 8
  tentativas); 20 falhas seguidas desativam o endpoint, com o motivo. A
  tentativa é reservada antes do envio, para que a varredura de pendentes
  não envie a mesma entrega duas vezes.
- A importação CSV tem duas etapas. Na prévia, o arquivo (UTF-8, até 2 MB
  e 5.000 linhas) é lido e cada linha é validada pelas regras do cadastro,
  com duplicidade no arquivo e no cadastro, e o resultado é gravado por
  linha. Na confirmação, a política escolhida decide: "só as válidas"
  aplica cada linha na própria transação, e a recusada fica com o motivo;
  "tudo ou nada" recusa se houver linha inválida e aplica tudo numa
  transação, que uma recusa desfaz.
- A aplicação roda em fila, com a associação de quem confirmou: as próprias
  Actions de compradores, catálogo e estoque conferem de novo permissões e
  regras. Linha aplicada guarda o registro criado e não é aplicada de
  novo, por isso reprocessar é seguro.
- Exportações CSV (pedidos filtrados, na carteira de quem exporta) saem
  com separador `;`, BOM, datas no fuso comercial e textos iniciados por
  `=`, `+`, `-`, `@`, tabulação ou retorno prefixados com apóstrofo
  (RS-110). Ficam na auditoria e têm limite configurável de linhas.

**Alternativas consideradas.**
- Tokens de usuário para integrações: misturariam pessoas e sistemas e
  dariam à integração as permissões do usuário, não as do sistema.
- Webhooks enviados direto da transação de negócio: chamada externa
  segurando locks e perdendo o evento num rollback; a outbox já garante a
  entrega depois do commit.
- Bloquear endereços internos só pela URL digitada: o nome pode resolver
  para um IP interno (DNS rebinding); por isso o IP conferido é o usado na
  conexão.
- Importação aplicada na própria requisição: arquivos grandes estourariam
  o tempo da requisição e não haveria prévia nem reprocessamento seguro.

**Consequências.**
- O distribuidor controla o que cada sistema acessa, e revogar um cliente
  invalida todos os tokens dele na hora.
- O receptor confere a assinatura e o carimbo e deduplica pelo id da
  entrega; eventos podem chegar mais de uma vez e fora de ordem.
- A importação de catálogo cria produto pelo nome quando não existe e
  reaproveita o existente; preço e custo não entram pelo CSV.

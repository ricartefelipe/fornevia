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
2. documento de origem (carrinho, cotação, pedido) e, depois dele, as
   linhas do próprio documento (itens, níveis de aprovação);
3. regras de aprovação da empresa: lock consultivo compartilhado na
   escolha da regra (submissão e reavaliação), exclusivo na alteração;
   delegações do aprovador, em modo compartilhado, na decisão;
4. saldos de estoque, ordenados por `(warehouse_id, sku_id)`;
5. conta de crédito da empresa compradora;
6. orçamento do centro de custo;
7. contador de uso do plano;
8. contador de numeração de pedidos do tenant.

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
diferente gera `409 idempotency_key_reused`. A chave é gravada na mesma
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
- Recusa de estoque, crédito, orçamento ou cota na confirmação deixa o
  pedido aprovado, sem confirmação, com o motivo registrado e nova
  tentativa.
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
mesma restrição para elas fica para a revisão de identidade e acesso.


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
| Nginx | 1.30.6 (unprivileged) | Linha estável |

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

1. documento de origem (carrinho, cotação, pedido);
2. chave de idempotência;
3. saldos de estoque, ordenados por `(warehouse_id, sku_id)`;
4. conta de crédito da empresa compradora;
5. orçamento do centro de custo.

Ordem consistente evita deadlocks entre confirmações concorrentes.

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

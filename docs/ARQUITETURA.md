# Arquitetura

Visão técnica do Fornevia. Decisões e alternativas estão em
[DECISOES.md](DECISOES.md); requisitos em [REQUISITOS.md](REQUISITOS.md).

## 1. Contexto (C4 nível 1)

```mermaid
flowchart LR
    subgraph Pessoas
        PA[Administrador da plataforma]
        EQ[Equipe do distribuidor<br/>vendas, estoque, crédito, financeiro]
        CO[Usuários da empresa compradora<br/>comprador, solicitante, aprovador]
    end

    F((Fornevia))

    ERP[ERP do distribuidor<br/>via API v1 e webhooks]
    PG[Asaas<br/>gateway de pagamentos]
    MAIL[Servidor SMTP]

    PA -->|planos, organizações| F
    EQ -->|catálogo, preços, cotações, pedidos| F
    CO -->|portal: catálogo, carrinho, aprovações| F
    F <-->|REST + webhooks assinados| ERP
    F <-->|cobranças, webhooks| PG
    F -->|notificações| MAIL
```

## 2. Containers (C4 nível 2)

```mermaid
flowchart TB
    U[Navegador] -->|HTTPS| WEB[web<br/>Nginx 1.30]
    INT[Integrações] -->|HTTPS /api/v1| WEB
    WEB -->|FastCGI index.php| APP[app<br/>PHP-FPM 8.5 + Laravel 13<br/>Blade, Livewire, API]
    APP -->|papel fornevia_app<br/>RLS| DB[(PostgreSQL 18)]
    APP -->|cache e filas| R[(Redis 8)]
    HZ[horizon<br/>workers de fila] --> R
    HZ -->|papel fornevia_app| DB
    OB[outbox<br/>relay da outbox] -->|papel fornevia_relay| DB
    OB -->|despacha jobs| R
    SC[scheduler<br/>schedule:work] --> R
    SC --> DB
    MG[migrate<br/>processo avulso] -->|papel fornevia_owner| DB
    HZ -->|HTTP| EXT[Asaas, webhooks de clientes]
    APP -->|SMTP| MP[Mailpit / SMTP]
    APP --> FS[(Disco privado<br/>storage/app/private)]
```

| Container | Processo | Papel no banco |
|---|---|---|
| `web` | Nginx: arquivos estáticos e encaminhamento de `index.php` | — |
| `app` | PHP-FPM: interface Blade/Livewire e API v1 | `fornevia_app` |
| `horizon` | Workers Redis supervisionados | `fornevia_app` |
| `outbox` | `php artisan outbox:relay` | `fornevia_relay` |
| `scheduler` | `php artisan schedule:work` | `fornevia_app` |
| `migrate` | `php artisan migrate --force` (avulso) | `fornevia_owner` |

Migrations nunca rodam na inicialização de `app`, `horizon`, `scheduler`
ou `outbox`. O readiness (`/health/ready`) falha enquanto houver migration
pendente.

## 3. Organização do código

| Camada | Local | Responsabilidade |
|---|---|---|
| Domínio | `app/Domain/<Contexto>` | Regras puras e value objects (Money, Quantity, PriceResolver). Sem Laravel; testadas sem banco |
| Casos de uso | `app/Actions/<Contexto>` | Orquestram transação, locks, validações de negócio, ledger e outbox |
| Persistência | `app/Models/<Contexto>` | Eloquent com `BelongsToTenant`; sem regras de negócio extensas |
| Autorização | `app/Policies/<Contexto>` + Gates | Permissão do contexto e propriedade do objeto |
| HTTP | `app/Http` | Controllers finos, Form Requests, Resources da API v1 |
| Interface | `app/Livewire`, `resources/views` | Componentes que chamam Actions e Policies |
| Assíncrono | `app/Jobs` | Jobs idempotentes com identificadores (sem models serializados) |
| Integrações | `app/Integrations` | Adaptadores (pagamentos, webhooks) |
| Infraestrutura | `app/Support` | Tenancy, idempotência, outbox, observabilidade, HTTP |

## 4. Estratégia de multitenancy

```mermaid
sequenceDiagram
    participant B as Navegador
    participant M as Middleware de contexto
    participant DB as PostgreSQL (fornevia_app)
    B->>M: requisição autenticada (sessão)
    M->>DB: BEGIN
    M->>DB: set_config('app.user_id', u, true)
    M->>DB: SELECT membership ativa do usuário (RLS por usuário)
    M->>M: valida associação, organização e tipo de contexto
    M->>DB: set_config('app.tenant_id' | 'app.buyer_company_id' | 'app.context_kind', ..., true)
    M->>M: route model binding, policies, Actions
    M->>DB: COMMIT (configurações locais descartadas)
```

- `CurrentContext` é *scoped*: descartado ao fim de cada requisição e antes
  de cada job pelo worker.
- `TenantDatabase::run()` é o único caminho para aplicar contexto. Aninhar
  o mesmo contexto reaproveita a transação; aninhar contexto diferente lança
  `TenantContextConflict`.
- Políticas de RLS: `tenant_isolation` (permissiva) em toda tabela com
  `tenant_id`; `buyer_isolation` (restritiva) em tabelas de comprador, que
  só exibem linhas da empresa do contexto quando o tipo é `buyer`.
- Funções: `app_current_tenant()`, `app_current_buyer()`,
  `app_current_user()`, `app_context_kind()`, `app_sees_all_buyers()`.

## 5. Papéis do banco

| Papel | Uso | Privilégios |
|---|---|---|
| `fornevia_owner` | migrations, manutenção | dono do schema; sujeito à RLS forçada |
| `fornevia_app` | aplicação, workers, scheduler | DML via default privileges; sem `UPDATE`/`DELETE` em ledgers; sem escrita em colunas de saldo |
| `fornevia_relay` | relay da outbox | apenas tabelas da outbox |

Provisionamento: `docker/postgres/init/01-roles.sh` (superusuário, uma vez
por cluster). Senhas por variáveis de ambiente.

## 6. Filas

| Fila | Supervisor Horizon | Uso |
|---|---|---|
| `critical` | `operacao` | eventos da outbox que afetam operação |
| `default` | `operacao` | processamento geral |
| `notifications` | `operacao` | e-mails e notificações |
| `integrations` | `integracoes` | provedores externos e webhooks enviados |

Timeouts dos supervisores (90 s e 120 s) ficam abaixo do `retry_after` da
conexão Redis (330 s). Jobs despachados dentro de transação só são
publicados após o commit (`after_commit`). O painel do Horizon exige
autenticação, e-mail verificado e 2FA confirmado, em qualquer ambiente, para
administradores da plataforma ou e-mails em `HORIZON_ALLOWED_EMAILS`.

## 6.1 Observabilidade

- Logs JSON em stderr (`LOG_STACK=json`), com `correlation_id`,
  `tenant_id`, `user_id` e `context_kind` vindos do `Context` do Laravel,
  propagados para jobs. Campos sensíveis são redigidos
  (`RedactSensitiveData`).
- `X-Request-Id` aceito em formato restrito ou gerado (UUIDv7).
- `/health/live` e `/health/ready` sem sessão nem cookies.
- `/metrics` no formato Prometheus (texto 0.0.4), só com
  `Authorization: Bearer <METRICS_TOKEN>`; sem token configurado, o
  endpoint responde 404. Limite de 60 coletas por minuto. Os rótulos não
  trazem dados de tenant.

| Série | Tipo | Origem |
|---|---|---|
| `fornevia_http_requests_total{kind,status}` | contador | toda requisição, por tipo (`web`, `livewire`, `api`, `webhooks`, `health`) e classe de status (`2xx`…`5xx`) |
| `fornevia_http_request_duration_seconds{kind}` | histograma | duração das mesmas requisições (limites em `observability.latency_buckets`) |
| `fornevia_db_conflicts_total{reason}` | contador | exceção reportada cuja cadeia contém um conflito do PostgreSQL: `deadlock` (40P01), `serialization` (40001), `lock_not_available` (55P03), inclusive a `DeadlockException` de transações aninhadas |
| `fornevia_jobs_failed_total{queue}` | contador | evento `JobFailed` |
| `fornevia_queue_size{queue}` | medida | tamanho das filas de `observability.queues` |
| `fornevia_outbox_pending`, `fornevia_outbox_oldest_pending_age_seconds`, `fornevia_outbox_dead` | medidas | situação da outbox (`outbox:status`) |
| `fornevia_failed_jobs` | medida | jobs guardados em `failed_jobs` |
| `fornevia_metrics_collection_errors` | medida | medidas que não puderam ser lidas na coleta |

Contadores ficam no Redis (conexão `metrics`, tempo limite de 0,25 s) e
somam todos os processos; falha ao gravar uma métrica gera aviso no log e
suspende as gravações do processo por 30 s, sem afetar a operação. O
middleware de métricas envolve toda a pilha global, então respostas de
manutenção, corpo grande e host recusado também entram na conta. Todo
limite do histograma é exportado, inclusive os vazios. O coletor usa o host
de `APP_URL` ou um dos hosts internos de `METRICS_SCRAPE_HOSTS`.

Auditoria: tela `/auditoria` (`audit.view`), somente leitura, com filtros
por ação ou grupo de ações, autor, registro afetado e período no fuso
comercial; as opções de ação e autor ficam em cache por 5 minutos. Os registros são somente inclusão no banco e isolados por
tenant.

## 7. Identidade, contextos e permissões

### 7.1 Modelo

```mermaid
erDiagram
    users ||--o{ memberships : "participa"
    organizations ||--o{ memberships : "tenant"
    organizations ||--o{ branches : "filiais"
    organizations ||--o{ roles : "papéis do tenant"
    roles ||--o{ memberships : "concede"
    buyer_companies ||--o{ memberships : "usuários compradores"
    customer_groups ||--o{ buyer_companies : "segmenta"
    memberships ||--o| buyer_companies : "representante responsável"
    organizations ||--o{ invitations : "convites"
    organizations ||--|| subscriptions : "assinatura da plataforma"
    plans ||--o{ subscriptions : "plano"
```

- **Usuário** (`users`) é identidade global, sem permissões próprias.
- **Associação** (`memberships`) liga o usuário a um contexto: equipe do
  distribuidor (`staff`) ou usuário de uma empresa compradora (`buyer`). Um
  mesmo usuário pode ser equipe no distribuidor A e comprador no B.
- **Papel** (`roles`) é do tenant, com lista de permissões e limite de
  desconto. Onze papéis padrão são criados no onboarding.
- **Convite** guarda apenas o hash SHA-256 do token; vale 7 dias; reenvio
  revoga o pendente anterior.
- Revogação mantém o registro (status `revoked`, autor, data e motivo).

### 7.2 Seleção de contexto e validação por requisição

```mermaid
sequenceDiagram
    participant U as Usuário
    participant C as ContextController
    participant S as SelectContext
    participant DB as PostgreSQL
    U->>C: POST /contextos (membership_id)
    C->>S: handle(user, id não confiável)
    S->>DB: contexto User: membership ativa do próprio usuário (RLS own_memberships)
    S->>DB: organização permite acesso?
    S-->>C: membership
    C->>C: session()->migrate(true) e grava contexto na sessão
    Note over U,DB: requisições seguintes
    U->>DB: EstablishRequestContext revalida id, usuário, tipo, empresa e situação da organização
```

A elevação temporária para outro contexto do **mesmo usuário** ocorre em
savepoint (seleção de contexto, aceite de convite, onboarding); o contexto
anterior é reaplicado ao final. Trocas para contextos sem usuário ou de
outro usuário lançam `TenantContextConflict` (ADR-014).

### 7.2.1 Proteções da sessão e da autenticação

- Sessões de outros dispositivos deixam de valer quando a senha muda
  (`AuthenticateSession`); a redefinição de senha apaga todas as sessões
  persistidas do usuário.
- Links enviados por e-mail usam `APP_URL` (`URL::forceRootUrl`), e apenas o
  host configurado é aceito fora de ambiente local (proteção contra
  envenenamento do cabeçalho Host).
- A página de segurança exige confirmação recente de senha, responde com
  `Cache-Control: no-store` e fica acessível com e-mail ainda não
  verificado (para corrigir endereço digitado errado).
- Desafio 2FA limitado por conta (5/min) e por IP (20/min).
- Middlewares de bloqueio interrompem o pipeline por exceção ou redirect;
  autenticação, e-mail verificado, contexto, permissão e 2FA são
  reaplicados nas ações Livewire (middleware persistente).
- Identificadores recebidos em ações Livewire fora do formato UUID são
  tratados como registro inexistente (`UntrustedId`).

### 7.3 Matriz de permissões (papéis padrão)

Gerada a partir de `App\Enums\Identity\SystemRole`. Permissões marcadas com
(2FA) exigem autenticação em dois fatores confirmada para acessar o contexto.

| Permissão | Proprietário | Administrador | Gerente comercial | Representante comercial | Estoque e expedição | Financeiro e crédito | Consulta |
|---|---|---|---|---|---|---|---|
| `organization.manage` Gerenciar organização e filiais (2FA) | ✓ | ✓ |  |  |  |  |  |
| `members.manage` Gerenciar equipe e convites (2FA) | ✓ | ✓ |  |  |  |  |  |
| `buyers.view` Ver empresas compradoras | ✓ | ✓ | ✓ | ✓ |  | ✓ | ✓ |
| `buyers.manage` Gerenciar empresas compradoras | ✓ | ✓ | ✓ |  |  |  |  |
| `catalog.view` Ver catálogo | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ |
| `catalog.manage` Gerenciar catálogo | ✓ | ✓ |  |  |  |  |  |
| `catalog.view_costs` Ver custos e margens | ✓ | ✓ | ✓ |  |  |  |  |
| `pricing.view` Ver tabelas de preço | ✓ | ✓ | ✓ | ✓ |  |  | ✓ |
| `pricing.manage` Gerenciar tabelas de preço | ✓ | ✓ | ✓ |  |  |  |  |
| `pricing.approve` Aprovar descontos e exceções de preço | ✓ | ✓ | ✓ |  |  |  |  |
| `quotes.view` Ver cotações | ✓ | ✓ | ✓ | ✓ |  |  | ✓ |
| `quotes.manage` Elaborar propostas | ✓ | ✓ | ✓ | ✓ |  |  |  |
| `orders.view` Ver pedidos | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `orders.manage` Gerenciar pedidos | ✓ | ✓ | ✓ |  |  |  |  |
| `inventory.view` Ver estoque | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ |
| `inventory.manage` Movimentar estoque | ✓ | ✓ |  |  | ✓ |  |  |
| `credit.view` Ver crédito comercial | ✓ | ✓ | ✓ | ✓ |  | ✓ | ✓ |
| `credit.manage` Gerenciar limites e exceções de crédito (2FA) | ✓ | ✓ |  |  |  | ✓ |  |
| `fulfillment.view` Ver expedição | ✓ | ✓ | ✓ |  | ✓ |  | ✓ |
| `fulfillment.manage` Operar separação e expedição | ✓ | ✓ |  |  | ✓ |  |  |
| `returns.view` Ver devoluções | ✓ | ✓ | ✓ |  | ✓ | ✓ | ✓ |
| `returns.manage` Operar devoluções | ✓ | ✓ |  |  | ✓ |  |  |
| `finance.view` Ver financeiro | ✓ | ✓ |  |  |  | ✓ | ✓ |
| `finance.manage` Operar financeiro (2FA) | ✓ | ✓ |  |  |  | ✓ |  |
| `integrations.manage` Gerenciar integrações (2FA) | ✓ | ✓ |  |  |  |  |  |
| `audit.view` Ver auditoria | ✓ | ✓ | ✓ |  |  | ✓ |  |
| `reports.view` Ver indicadores | ✓ | ✓ | ✓ |  |  | ✓ | ✓ |
| `subscription.manage` Gerenciar assinatura do Fornevia (2FA) | ✓ |  |  |  |  |  |  |
| Limite de desconto | 100% | 100% | 15% | 5% | 0% | 0% | 0% |

| Permissão | Administrador da empresa | Comprador | Solicitante | Aprovador |
|---|---|---|---|---|
| `portal.catalog` Ver catálogo e preços | ✓ | ✓ | ✓ | ✓ |
| `portal.cart` Montar carrinho e listas | ✓ | ✓ | ✓ |  |
| `portal.orders.place` Confirmar pedidos | ✓ | ✓ |  |  |
| `portal.orders.view_all` Ver todos os pedidos da empresa | ✓ | ✓ |  | ✓ |
| `portal.quotes` Solicitar cotações | ✓ | ✓ | ✓ |  |
| `portal.approvals` Aprovar compras | ✓ |  |  | ✓ |
| `portal.budgets` Gerenciar orçamentos | ✓ |  |  |  |
| `portal.approval_rules` Gerenciar regras e delegações de aprovação | ✓ |  |  |  |
| `portal.users` Gerenciar usuários da empresa | ✓ |  |  |  |

Regras adicionais:

- Permissões `portal.*` só têm efeito em contexto de comprador; as demais,
  em contexto de equipe (`Access::can`).
- Somente proprietários concedem, retiram ou revogam o papel de proprietário;
  a organização mantém ao menos um proprietário ativo (`OwnershipGuard`).
- Usuários de comprador com `portal.users` gerenciam apenas a própria empresa.
- Ocultar itens de navegação não é controle de acesso: rotas, middleware
  persistente do Livewire e Actions validam no servidor.

### 7.4 Limites do plano

| Recurso | Verificação |
|---|---|
| Usuários da equipe | ativos + convites pendentes, com a assinatura bloqueada (`FOR UPDATE`) |
| Filiais | contagem com a assinatura bloqueada |
| Pedidos por mês | incremento condicional em `tenant_usage_counters` na transação da confirmação |
| Módulos | incluídos no plano ou contratados como adicionais ativos |
| Trial expirado ou assinatura cancelada | operações de escrita recusadas (`SubscriptionInactive`) |

## 8. Compradores, catálogo e preços

### 8.1 Empresas compradoras

Cadastro com CNPJ validado, grupo de clientes, representante responsável,
unidades, endereços (um padrão por finalidade), centros de custo, contatos,
condições de pagamento permitidas (com uma padrão) e histórico montado a
partir da auditoria do tenant. As tabelas de detalhes usam RLS `required`
(o comprador lê apenas a própria empresa) e são somente leitura no contexto
de comprador (o cadastro é mantido pela equipe).

O histórico filtra a auditoria pelo assunto (empresa, associações e
convites) e pela coluna gerada `audit_logs.buyer_company_id` (extraída de
`data->>'buyer_company_id'` quando é UUID), indexada. Uma expressão sobre
`jsonb` não seria usada como índice sob RLS (operador não *leakproof*);
a comparação `uuid = uuid` é. Condições negociadas só aparecem para quem
tem `pricing.view`.

No banco, o comprador não lê a auditoria do tenant e só grava registros em
nome do próprio usuário, sem atribuí-los a outra empresa.

### 8.2 Catálogo

| Conceito | Regra |
|---|---|
| Unidade de medida | código único, 0 a 3 casas decimais; reduzir casas é recusado se algum SKU vendido na unidade, ou de produto que a usa como unidade base, deixaria de ter mínimo, múltiplo, faixa de preço ou quantidade convertida representáveis |
| Produto | unidade base fixa depois que existe SKU; situação (rascunho, ativo, inativo, arquivado); visibilidade pública ou restrita |
| SKU | unidade de venda, fator de conversão (`numeric(18,6)`), mínimo e múltiplo nas casas da unidade de venda; mínimo × fator e múltiplo × fator exatos na unidade base (RN-021) |
| Embalagem com preço | SKU com item de tabela ou condição negociada ativa não troca unidade de venda nem fator: os preços estão na unidade de venda; nova embalagem é um novo SKU. Formulários de preço guardam a embalagem vista ao abrir e recusam a gravação se ela mudou |
| Limites de quantidade | mínimo, múltiplo e quantidade por linha até 999.999.999; quantidades convertidas para a unidade base dentro de `numeric(18,3)` |
| Custos e política comercial | `sku_costs` (histórico imutável) e `sku_commercial_policies` com política `internal_only`: invisíveis ao comprador no banco; exibição exige `catalog.view_costs` |
| Mídias | disco privado, tipo verificado pelo conteúdo, download por rota autorizada; o envio temporário do Livewire só é aceito para quem tem `catalog.manage` |

Visibilidade para o comprador, verificada no banco
(`app_buyer_sees_product`): produto ativo e público, ou restrito em
catálogo privado ativo atribuído à empresa ou ao grupo dela. Os próprios
catálogos privados, seus produtos e atribuições seguem o mesmo critério:
o comprador não descobre catálogos de outros grupos.

Inclusão e remoção de produtos de catálogo privado pela tela são
incrementais e calculadas sob o lock do catálogo, sem desfazer alterações
concorrentes.

### 8.3 Preços

```mermaid
flowchart LR
    T[Tela ou documento] --> S[PricingService]
    S -->|cache por tenant, versão, empresa, grupo, data, SKU e quantidade| C[(Redis)]
    S --> L[PriceCandidateLoader]
    L --> N[condições negociadas]
    L --> P[tabelas: padrão, grupo, empresa]
    S --> R[PriceResolver - domínio]
    Sim[Simulador da equipe] --> S
    Sim --> L
```

- Precedência e faixas: ADR-010 e ADR-011, implementadas no domínio puro
  (`PriceResolver`).
- `PriceCandidateLoader` é a única consulta de candidatos, usada pelo
  cálculo e pelo simulador (que explica a ordem aplicando o próprio
  resolvedor sucessivamente).
- Invalidação: `pricing_settings.price_version` é incrementada na mesma
  transação de qualquer alteração que afete preço (itens, faixas,
  ativação, arquivamento, condições negociadas, grupo da empresa,
  política comercial do SKU); a versão faz parte da chave do cache.
- O cache não passa pelo RLS: no contexto de comprador, o serviço recusa
  calcular para outra empresa (evita ler ou gravar entradas de terceiros).
- `pricing_settings` (versão de preços) é legível pelo comprador e somente
  leitura nesse contexto; a margem mínima padrão fica em `pricing_policies`,
  invisível ao comprador no banco (`internal_only`) e enviada ao navegador
  apenas de quem tem `pricing.manage`.
- Itens, faixas e condições negociadas herdam também a visibilidade do SKU:
  o comprador não vê preço de produto restrito fora do seu catálogo.
- Faixas aceitam quantidade mínima apenas com as casas decimais da unidade
  de venda.

### 8.4 Entrada numérica e limites

| Entrada | Interpretação |
|---|---|
| Valores em reais | `1.234,56`, `1234,56` ou `1234.56`; até 2 casas |
| Quantidades nas telas de preço e no portal | pt-BR: `1.000` = mil, `1.250,5`, `2,5`; ponto seguido de 1 ou 2 dígitos, ou após zero inicial, é decimal (`2.5`, `0.500`) |
| Quantidades e fatores no cadastro de SKU | apenas vírgula decimal (`0,5`); ponto recusado para evitar ambiguidade |
| Limites | dinheiro até R$ 10 trilhões em centavos; quantidades até 15 dígitos inteiros (`numeric(18,3)`) |

Valores fora do intervalo geram `AmountOutOfRange` (regra de negócio,
código `amount_out_of_range`), exibida no campo, nunca como erro 500. No
portal, um total que excederia o limite não é calculado: a tela pede
redução da quantidade.
Datas comerciais digitadas aceitam de 2000-01-01 a 2099-12-31.

## 9. Estoque, crédito e pedidos

### 9.1 Ledgers e projeções (ADR-005)

| Ledger (append-only) | Projeção (por trigger) | Invariantes no banco |
|---|---|---|
| `stock_movements` (entrada, ajuste, transferência, reserva, liberação, saída) | `stock_levels` (físico, reservado por depósito e SKU) | sinais por tipo; físico ≥ 0; reservado ≤ físico |
| `credit_movements` (comprometimento, liberação, faturamento, pagamento, nota de crédito, ajuste) | `credit_accounts` (comprometido, recebível) | sinais por tipo; saldos ≥ 0; conta e empresa coerentes |

A aplicação não altera saldos diretamente: o papel `fornevia_app` não tem
`INSERT` nos saldos nem `UPDATE` nas colunas de saldo. O trigger de estoque
atualiza a linha existente antes de inserir: em `INSERT ... ON CONFLICT` o
PostgreSQL avalia o `CHECK` sobre a linha proposta antes do conflito.

Posição de crédito (RN-080): `exposição = comprometido + recebível`;
`disponível = limite + exceções vigentes − exposição`. Bloqueio e títulos
vencidos acima da tolerância recusam compra a prazo.

### 9.2 Confirmação do pedido (RN-060)

```mermaid
sequenceDiagram
    participant P as Portal (contexto comprador)
    participant A as PlaceOrder
    participant S as Contexto de sistema (mesmo usuário)
    P->>A: checkout (chave de idempotência)
    A->>A: lock do carrinho, registro da chave
    A->>A: preços e regra de compra recalculados no servidor
    A->>A: pedido e itens (snapshot), número por sequência
    A->>S: custos internos, reservas (lock dos saldos), crédito (lock da conta), cota do plano, outbox
    A->>A: evento, carrinho convertido, auditoria
    A-->>P: pedido (ou recusa sem efeito parcial)
```

Ordem de locks (ADR-006): chave de idempotência → carrinho → saldos de
estoque (depósito, SKU) → conta de crédito → contador do plano. Gravações
em estoque, crédito, custos, contador e outbox usam o contexto de sistema do
mesmo usuário (ADR-014), porque o contexto de comprador só lê essas tabelas.

Cancelamento (RF-062): itens não expedidos, liberando primeiro a parte
encomendada, depois reservas, e o crédito proporcional (o restante inteiro
no cancelamento total).

### 9.3 Garantias verificadas com processos concorrentes

| Cenário | Resultado esperado |
|---|---|
| Duas compras pela última unidade | uma confirma; a outra recebe `InsufficientStock` |
| Quatro compras disputando saldo parcial | reservado = soma das reservas ativas; sem negativo |
| Duas compras no mesmo limite de crédito | apenas o que cabe no disponível é comprometido |
| Envios simultâneos com a mesma chave | um único pedido; o segundo recebe o mesmo resultado |

### 9.4 Telas e permissões

| Área | Rotas | Permissões |
|---|---|---|
| Portal: carrinho, pedido rápido, listas, recompra, checkout | `portal.cart`, `portal.quick-order`, `portal.lists`, `portal.lists.show` | `portal.cart`; confirmação exige `portal.orders.place` |
| Portal: meus pedidos | `portal.orders`, `portal.orders.show`, `portal.orders.confirmation` | próprios pedidos; com `portal.orders.view_all`, os da empresa |
| Equipe: pedidos | `orders.index`, `orders.show` | `orders.view`; cancelamento com `orders.manage`; custo e margem com `catalog.view_costs` |
| Equipe: estoque | `inventory.index`, `inventory.movements`, `inventory.warehouses`, `inventory.operations` | `inventory.view`; operações com `inventory.manage` |
| Equipe: crédito | `credit.index`, `credit.show` | `credit.view`; alterações com `credit.manage` |
| Portal: aprovações | `portal.approvals`, `portal.approvals.show` | `portal.approvals` (aprovador do nível, delegado vigente ou quem já decidiu); bloqueados e reatribuição com `portal.approval_rules` |
| Portal: regras e delegações | `portal.approval-rules`, `portal.approval-rules.create`, `portal.approval-rules.edit`, `portal.delegations` | regras com `portal.approval_rules`; delegações com `portal.approval_rules` (todas) ou `portal.approvals` (as próprias) |
| Portal: orçamentos | `portal.budgets`, `portal.budgets.show` | `portal.budgets` |

- O representante comercial (papel `sales_rep`) vê apenas as empresas da
  própria carteira (`buyer_companies.sales_rep_membership_id`) e os dados
  ligados a elas em todas as telas da equipe: empresas e grupos (contagem),
  pedidos, cotações, crédito, condições negociadas, tabelas de preço por
  empresa, frete por empresa, catálogos privados atribuídos, simulador de
  preço e painel. Empresa fora da carteira equivale a inexistente (404). A
  regra fica em `SalesPortfolio`, usada por todas essas consultas; os demais
  papéis veem todas as empresas. Atribuições de catálogos privados seguem
  a carteira (lista e contagem); produtos, saldos de estoque e movimentos
  não têm recorte, e os movimentos mostram só o número do documento de
  origem, sem dados da empresa. Notificações de cotação vão ao
  representante atual da empresa; sem ele, à equipe que elabora propostas,
  exceto representantes.
- O checkout exibe o total calculado no servidor e o envia como valor
  esperado: se preços mudarem até a confirmação, ela é recusada
  (`PricesChanged`). A chave de idempotência é gerada no servidor a cada
  tentativa.
- Números de pedido são sequenciais por distribuidor (`PED-0000001`),
  atribuídos no fim da confirmação (ADR-022).
- Reservas, movimentações de estoque e dados de crédito não são legíveis no
  contexto de comprador, nem os da própria empresa (ADR-020).

## 10. Aprovação de compras e orçamento

```mermaid
stateDiagram-v2
    [*] --> AguardandoAprovacao: checkout com regra aplicável
    [*] --> Confirmado: checkout sem regra
    AguardandoAprovacao --> AguardandoAprovacao: aprovação parcial / reavaliação
    AguardandoAprovacao --> Rejeitado: rejeição em qualquer nível
    AguardandoAprovacao --> Cancelado: cancelamento
    AguardandoAprovacao --> Confirmado: último nível aprovado e confirmação aceita
    AguardandoAprovacao --> AprovadoSemConfirmacao: confirmação recusada (estoque, crédito, orçamento)
    AprovadoSemConfirmacao --> Confirmado: nova tentativa aceita
    AprovadoSemConfirmacao --> Cancelado: cancelamento
```

| Etapa | Orçamento | Estoque e crédito |
|---|---|---|
| Submissão para aprovação | reserva do total | não reservados |
| Rejeição ou cancelamento | liberação da reserva | — |
| Reavaliação (novo total) | liberação e nova reserva | — |
| Confirmação | consumo (reserva convertida; diferença precisa caber) | reservados e comprometidos |
| Checkout sem aprovação | consumo direto | reservados e comprometidos |
| Cancelamento após a confirmação | estorno do consumo, proporcional | liberados, proporcionais |

- Regra aplicável, níveis, quórum, delegação, perda de acesso e
  reavaliação: ADR-023.
- A decisão bloqueia o pedido antes de ler níveis e decisões: aprovações
  simultâneas que completam o quórum geram uma única confirmação, e a
  aprovação final que concorre com o cancelamento encontra o pedido
  cancelado (verificado com processos concorrentes, esperando o lock da
  linha do pedido); submissões simultâneas não ultrapassam o orçamento
  (bloqueio do orçamento antes da reserva).
- Tabelas da aprovação usam RLS `required` (a empresa compradora vê apenas
  as suas) e chaves estrangeiras compostas que incluem a empresa. Todas,
  como pedidos, itens e eventos, são somente leitura no contexto de
  comprador: as Actions autorizam a administração da empresa (regras,
  delegações, orçamentos), o aprovador ou o solicitante e gravam no
  contexto de sistema do mesmo usuário (ADR-024). Decisões são append-only,
  com invalidação definitiva em coluna própria e delegação conferida pelo
  par titular e delegado; o nível retratado não muda de quórum, situações
  encerradas não voltam e delegação revogada não volta a valer (triggers).
- A escolha da regra (submissão e reavaliação) toma o lock consultivo das
  regras da empresa em modo compartilhado; a alteração de regras, em modo
  exclusivo (ADR-006). A decisão por delegação bloqueia a delegação em
  modo compartilhado: a revogação concorrente termina antes ou espera.

## 11. Cotações e negociação

```mermaid
stateDiagram-v2
    [*] --> Solicitada: comprador solicita (carrinho)
    Solicitada --> Proposta: equipe envia versão
    Proposta --> Proposta: nova versão (anterior substituída)
    Proposta --> Solicitada: pedido de revisão / retirada
    Proposta --> Vencida: validade (job ou ação sob lock)
    Vencida --> Proposta: nova versão
    Vencida --> Solicitada: pedido de revalidação
    Proposta --> Aceita: aceite gera o pedido
    Proposta --> Recusada: comprador recusa
    Solicitada --> Cancelada: cancelamento / encerramento
    Vencida --> Cancelada: cancelamento / encerramento
    Proposta --> Cancelada: encerramento pela equipe
```

| Etapa | Versão | Reserva de estoque | Pedido |
|---|---|---|---|
| Submissão dentro da política | pronta e enviada na mesma transação | criada no envio, se pedida (tudo ou nada) | — |
| Submissão fora da política | em aprovação comercial (só a equipe vê) | — | — |
| Aprovação comercial | pronta; enviada se "aprovar e enviar" | criada no envio | — |
| Nova versão enviada | anterior substituída | anterior liberada, nova criada sob o mesmo lock | — |
| Revisão, recusa, retirada, encerramento | encerrada | liberada com o motivo | — |
| Aceite | aceita, cotação encerrada | mantida até o prazo (com aprovação interna) ou transferida | nasce pelo caminho do checkout |
| Pedido rejeitado ou cancelado antes da confirmação | — | liberada | — |

- Versões imutáveis (privilégio de coluna), itens conferidos com os totais
  no COMMIT, numeração só no envio e no máximo uma versão em preparo, uma
  com o comprador e uma aceita por cotação (ADR-025).
- Preço de referência sempre do `PricingService`; a equipe informa o
  desconto e a avaliação comercial aplica limite do papel, preço mínimo,
  custo e margem (RN-032, RN-033, RN-041). A avaliação e as notas internas
  não são legíveis pelo comprador (ADR-020).
- Aceite, nova versão, aprovação e expiração bloqueiam a cotação primeiro;
  operações do pedido bloqueiam o pedido e depois a cotação de origem
  (ADR-006). A expiração usa `SKIP LOCKED` e nunca bloqueia pedidos;
  verificado com processos concorrentes (`tests/Concurrency/Quotes`).
- Telas: portal em `/portal/cotacoes` (`portal.quotes`; aceitar exige
  `portal.orders.place`); equipe em `/cotacoes` (`quotes.view`, elaborar
  com `quotes.manage`) e `/cotacoes/aprovacoes` (`pricing.approve`).
- O comando `quotes:expire` roda a cada minuto no serviço `scheduler`.


## 12. Outbox e notificações

O relay (`php artisan outbox:relay`, container `outbox`, papel
`fornevia_relay`) publica os eventos gravados na outbox (ADR-008, ADR-026):

| Etapa | Onde | Garantia |
|---|---|---|
| Reivindicação | relay, transação curta | `FOR UPDATE SKIP LOCKED` com lease (dono e prazo) e tentativa; mais de um relay não pega a mesma mensagem |
| Publicação | relay, fora da transação | um job `ProcessOutboxMessage` por mensagem, na fila declarada pelos consumidores do tipo (`critical` prevalece); marcação conferindo o dono do lease |
| Falha de publicação | relay | `outbox_failures` (etapa `publish`), backoff de 5 s a 1 h, `dead` após 10 tentativas; `outbox:retry` reenvia |
| Consumo | worker, contexto de sistema do tenant | um consumidor por transação, com `outbox_consumptions` único por mensagem e consumidor; falha desfaz consumo e efeito; esgotadas as tentativas, `outbox_failures` (etapa `consume`) e a mensagem fica `dead`, reenviável sem repetir os consumidores que já agiram |
| Retenção | relay, a cada hora | publicadas há mais de 30 dias são apagadas em lotes; `dead` ficam |

`outbox:status` mostra pendentes, idade da mais antiga, reivindicadas e
`dead` (`--json` para coleta de métricas).

Notificações são um consumidor (`ActivityNotifier`, fila `notifications`).
Os destinatários são calculados no consumo, pelo estado atual, e nunca
incluem quem causou o evento:

| Evento | Quem é avisado | Tela |
|---|---|---|
| Solicitação, pedido de revisão, aceite, recusa, cancelamento e comentário do comprador | representante da carteira que elabora propostas; sem ele, quem tem `quotes.manage` | cotação na equipe |
| Aprovação comercial pendente | quem tem `pricing.approve` com limite que cobre o maior desconto, exceto o autor | cotação na equipe |
| Decisão comercial | autor da versão | cotação na equipe |
| Proposta enviada, retirada, vencida, fim da reserva, encerramento e comentário do distribuidor | solicitante da cotação | cotação no portal |
| Envio para aprovação, nível aprovado, reavaliação e reatribuição | aprovadores do nível pendente ainda elegíveis e delegados vigentes, sem quem já decidiu nem quem fez o pedido | fila de aprovação |
| Rejeição, confirmação pendente, confirmação e cancelamento | quem fez o pedido | pedido no portal |

A notificação no sistema (`user_notifications`) é única por mensagem e
associação, visível só à própria pessoa (RLS) e só tem a data de leitura
gravável; o link é sempre um caminho interno. O e-mail sai depois do
commit, uma vez por notificação criada, para e-mail confirmado e pessoa
ativa, salvo preferência desligada no contexto (`notification_settings`).
A central fica em `/notificacoes` (equipe) e `/portal/notificacoes`
(portal), com a contagem de não lidas no cabeçalho.

## 13. Expedição, entrega, devoluções e frete

Remessas, eventos e devoluções seguem a ADR-027:

| Etapa | Quem | Garantia |
|---|---|---|
| Separação | `fulfillment.manage` | quantidade até o que falta separar (pedido − cancelado − expedido − em separação) e até a reserva ativa do item no depósito, descontadas as outras separações do mesmo depósito; número `EXP-` por distribuidor |
| Conferência | `fulfillment.manage` | quantidade conferida por item, de zero até a separada; só o conferido é expedido |
| Expedição | `fulfillment.manage` | sob o lock do pedido e da remessa, consome as reservas do depósito (`shipment` baixa físico e reservado), soma o expedido e grava transportadora e rastreamento; a remessa expedida não volta a ser expedida |
| Entrega e ocorrências | `fulfillment.manage` ou transportadora | evento único por identificador externo; a situação só avança de expedida para entregue; evento repetido ou fora de ordem fica no histórico sem efeito |
| Encomenda | `fulfillment.manage` | o saldo sob encomenda é reservado na medida do disponível quando o estoque chega |
| Devolução | comprador com `portal.orders.place`, equipe com `returns.manage` | pedido sobre o entregue sem devolução aberta; autorização com depósito e inspeção, ou recusa; o recebido conta como devolvido; só o aprovado na inspeção volta ao disponível (`return_receipt`); valor a devolver registrado |
| Frete | `pricing.manage` | condição negociada da empresa ou regra ativa de maior prioridade para a UF de entrega, com isenção por subtotal; calculado no servidor no carrinho e no checkout; recalculado pela regra vigente quando a reavaliação da aprovação reprecifica os itens |

No banco, os CHECKs dos itens do pedido garantem cancelado + expedido + em
separação ≤ pedido, entregue ≤ expedido e devolvido + em devolução ≤
entregue; triggers impedem regressão da remessa e alteração de itens fora
da separação. Remessas, eventos, devoluções e frete são somente leitura no
contexto de comprador. A ordem de locks é pedido → remessa ou devolução →
itens → saldos → reservas → contador.

Avisos (ADR-026): quem fez o pedido recebe expedição, entrega e
ocorrências; quem opera devoluções recebe cada nova devolução; o
solicitante recebe a análise e a conclusão com o valor a devolver.

## 14. Contas a receber, gateway e indicadores

Segue a ADR-028.

| Etapa | Quem | Garantia |
|---|---|---|
| Fatura | `finance.manage` | expedido ainda não faturado, frete na primeira fatura; parcelas pela condição do pedido; comprometimento de crédito vira recebível no mesmo movimento; número `FAT-` |
| Pagamento manual | `finance.manage` | confirmado na hora, até o saldo em aberto da parcela |
| Cobrança no gateway | `finance.manage` ou comprador com `portal.orders.place` | uma pendente por parcela, do saldo e na forma escolhida (outra é cancelada); gravada na requisição e criada por job depois do commit; referência externa = id do pagamento; sem cobrança repetida após timeout |
| Confirmação | webhook, simulador ou conciliação | idempotente; vencida não encerra; juros viram ajuste; quita até o saldo em aberto, o resto fica não aplicado e reembolsável; registra a taxa; reduz o recebível |
| Reembolso | `finance.manage` | só de confirmado; reservado sob lock; pelo gateway de origem, em job depois do commit; sem resposta, em verificação até a equipe concluir; pendente + efetivado ≤ pago (CHECK) |
| Crédito e ajuste | `finance.manage` | lançamento compensatório com motivo; o devido nunca fica abaixo do pago e creditado |
| Conciliação | `payments:reconcile`, a cada 15 minutos | todos os pendentes há mais de 10 minutos, primeiro os sem cobrança e os conferidos há mais tempo: cria a cobrança que faltou, cancela a de parcela já quitada ou aplica a situação do gateway de origem |
| Simulador | só com `payments.simulator_enabled` (desligado em produção) | comprador que vê a fatura ou equipe com `finance.view` simula pagamento ou remoção, pelo mesmo caminho do webhook |

O webhook do Asaas fica em `/webhooks/pagamentos/asaas/{organização}`, fora
do grupo web, autenticado pelo token do distribuidor (comparado pelo hash) e
limitado por taxa. Eventos ficam em `gateway_webhook_events` e aparecem na
fila de conciliação do financeiro.

Indicadores (`/indicadores`, `reports.view`), por período em datas do fuso
comercial:

| Indicador | Fórmula |
|---|---|
| Ticket médio | soma dos totais dos pedidos confirmados no período ÷ quantidade desses pedidos |
| Conversão | versões de proposta aceitas ÷ versões enviadas no período |
| Margem | (receita líquida − custo) ÷ receita líquida, nos itens de pedidos confirmados no período com custo registrado na confirmação, sobre a quantidade não cancelada |
| Inadimplência | saldo em aberto das parcelas já vencidas ÷ total das parcelas com vencimento no período |

Sem base (divisor zero), o indicador aparece como indefinido.

## 15. Integrações: API, webhooks e CSV

Segue a ADR-029. Telas em `/integracoes` (`integrations.manage`, com 2FA).

| Recurso | Acesso | Garantia |
|---|---|---|
| API v1 (`/api/v1`) | token do cliente da API, `Authorization: Bearer <id>\|fvk_…` | permissões do cliente; contexto de integração do tenant; 300 req/min por cliente (429 com `Retry-After`); filtros declarados (422 para desconhecido); até 100 por página; contrato em `docs/openapi.json` |
| Clientes da API | `integrations.manage` | permissões da lista permitida; token mostrado uma vez, prazo de 1 a 365 dias; revogação do token ou do cliente |
| Webhooks enviados | consumidor `webhooks` da outbox, fila `integrations` | uma entrega por evento e endpoint; só campos públicos; HMAC-SHA256 com carimbo; HTTPS para IP público conferido, sem redirecionamento; espera crescente até 8 tentativas; 20 falhas seguidas desativam o endpoint |
| Importação CSV | `integrations.manage` e a permissão do tipo | prévia com resultado por linha; UTF-8, até 2 MB e 5.000 linhas; política "só as válidas" ou "tudo ou nada"; aplicação em fila pelas Actions do módulo; cadastro alterado depois da prévia recusa só a linha; importação interrompida fica como falha e pode ser reprocessada; linha aplicada não se repete |
| Exportação de pedidos | `orders.view`, na carteira | filtros da lista; fórmulas neutralizadas; auditada; até `integrations.exports.max_rows` pedidos |

Eventos de webhook:

| Evento | Campos |
|---|---|
| `order.confirmed` | `order_id`, `number`, `buyer_company_id`, `total_cents` |
| `order.cancelled` | `order_id`, `number` |
| `shipment.shipped` | `shipment_id`, `order_id`, `number`, `carrier`, `tracking_code` |
| `shipment.delivered` | `shipment_id`, `order_id`, `number` |
| `invoice.issued` | `invoice_id`, `order_id`, `number`, `total_cents` |
| `payment.confirmed` | `payment_id`, `invoice_id`, `amount_cents` |
| `return.requested` | `return_id`, `order_id`, `number` |

Cabeçalhos de cada entrega: `X-Fornevia-Event`, `X-Fornevia-Delivery` (id
estável da entrega), `X-Fornevia-Timestamp` e `X-Fornevia-Signature`
(`t=<carimbo>,v1=<hex>`, HMAC-SHA256 de `carimbo.corpo`). O comando
`webhooks:retry`, a cada minuto, envia as entregas pendentes cuja espera
venceu e as que não tiveram a primeira tentativa há mais de 2 minutos.

Colunas dos arquivos de importação:

| Tipo | Colunas |
|---|---|
| Clientes | `razao_social`, `nome_fantasia`, `cnpj`, `email`, `telefone` |
| Catálogo | `codigo_sku`, `produto`, `unidade`, `fator_conversao`, `quantidade_minima`, `multiplo`; opcionais `preco` (exige `pricing.manage`; na tabela de `tabela_preco` ou na tabela padrão ativa) e `custo` (exige `catalog.view_costs`) |
| Estoque | `deposito`, `codigo_sku`, `quantidade` (unidade base) |

Os cabeçalhos são comparados sem acento e sem diferenciar maiúsculas;
separador `;` ou `,`, detectado no cabeçalho.

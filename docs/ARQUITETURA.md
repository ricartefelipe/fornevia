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

## 6. Observabilidade

- Logs JSON em stderr (`LOG_STACK=json`), com `correlation_id`,
  `tenant_id`, `user_id` e `context_kind` vindos do `Context` do Laravel,
  propagados para jobs. Campos sensíveis são redigidos
  (`RedactSensitiveData`).
- `X-Request-Id` aceito em formato restrito ou gerado (UUIDv7).
- `/health/live` e `/health/ready` sem sessão nem cookies.

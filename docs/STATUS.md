# Status da implementação

Atualizado a cada incremento. Distingue o que foi **implementado e
verificado** do que está **pendente**. Integrações externas só constam como
exercitadas quando houve chamada real ao provedor.

## Incremento 1 — Fundação da plataforma (`feature/fundacao-plataforma`)

### Implementado

- Esqueleto Laravel 13.42 / PHP 8.5.12 com dependências fixadas
  (`composer.lock`, `package-lock.json`).
- Imagens Docker multi-estágio (`base`, `dev`, `vendor`, `assets`, `prod`,
  `web`) e `compose.yaml` com app, web, horizon, scheduler, outbox,
  postgres, redis, mailpit, migrate (avulso), node (build) e selenium
  (perfil `browser`).
- Provisionamento de papéis do PostgreSQL (`fornevia_owner`,
  `fornevia_app`, `fornevia_relay`) e dos bancos `fornevia`,
  `fornevia_test`, `fornevia_browser`.
- Infraestrutura de tenancy: `ExecutionContext`, `CurrentContext`
  (scoped), `TenantDatabase` (transação + `set_config` local),
  `BelongsToTenant`/`TenantScope`, `TenantSchema` (FKs compostas, RLS
  forçada, tabelas append-only e projeções).
- Funções SQL de contexto e políticas de RLS (tenant e comprador).
- Tabelas `users`, `organizations`, `branches`, `sessions`,
  `password_reset_tokens`, `failed_jobs`, `job_batches`.
- Middleware de correlação (`X-Request-Id`), cabeçalhos de segurança com
  CSP por nonce, proxies confiáveis por configuração.
- Logs JSON com redação de dados sensíveis.
- `/health/live` e `/health/ready` (banco, migrations pendentes, Redis).
- Formato estruturado de erros da API.
- Pint, PHPStan nível 8 (Larastan) sem baseline, Pest 5.
- CI: qualidade, auditorias, segredos (gitleaks), testes com PostgreSQL e
  Redis reais e cobertura (Xdebug, path coverage), meta de cobertura,
  migrations em banco limpo (migrate, rollback, migrate), build das imagens
  e análise com Trivy.

### Verificado localmente (Docker, engine nativo)

| Verificação | Resultado |
|---|---|
| `vendor/bin/pest` | 33 testes, 93 asserções, todos passando |
| `vendor/bin/phpstan analyse` (nível 8) | sem erros |
| `vendor/bin/pint --test` | sem pendências |
| `composer audit` | sem vulnerabilidades conhecidas |
| `npm audit` | 0 vulnerabilidades |
| `docker build --target prod` | sucesso; imagem sem `.env` e sem `tests`, usuário `www-data`, OPcache sem revalidação |

### Pendente neste incremento

- Execução da CI no GitHub (depende do push da branch).

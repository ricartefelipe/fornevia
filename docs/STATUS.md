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

### Concluído

- CI executada no GitHub (PR #1): qualidade, frontend, segredos, testes com
  PostgreSQL e Redis de serviço, migrations com rollback em banco limpo,
  build das imagens e Trivy — todos aprovados após aplicar atualizações de
  segurança do Alpine na imagem web.
- Proteções de `main` e `develop` configuradas no GitHub: PR obrigatório,
  checks obrigatórios, sem force push e sem exclusão, inclusive para
  administradores. Aprovação humana obrigatória **não** está habilitada
  (`required_approving_review_count = 0`): o repositório tem um único
  mantenedor; habilitar quando houver revisor independente.

## Incremento 2 — Identidade e acesso (`feature/identidade-e-acesso`)

### Implementado

- Papéis por tenant (11 padrão), associações por contexto (equipe e
  empresa compradora), convites de uso único (hash do token), revogação
  com motivo e auditoria append-only do tenant.
- RLS das novas tabelas: isolamento por tenant; o usuário enxerga as
  próprias associações em todos os tenants; comprador enxerga apenas a
  própria empresa, colegas e o representante responsável.
- Contexto revalidado a cada requisição (ADR-013) e elevação controlada do
  contexto de usuário (ADR-014); troca de contexto regenera a sessão.
- Fortify: login, cadastro com aceite de termos, verificação de e-mail,
  redefinição de senha, 2FA com confirmação; 2FA obrigatório para perfis
  privilegiados e administradores da plataforma.
- Assinatura da plataforma separada das vendas (ADR-015): planos
  configuráveis, trial, módulos incluídos/adicionais, limites efetivos de
  usuários, filiais e pedidos por mês.
- Telas: seleção de contexto, onboarding, painel, equipe e permissões,
  filiais, segurança da conta, portal do comprador (início), planos e
  organizações da plataforma.
- Harness de concorrência com processos separados; corrida entre dois
  proprietários revogando um ao outro (revelou e levou à correção de um
  deadlock por ordem de locks).

### Verificado localmente (Docker, engine nativo)

| Verificação | Resultado |
|---|---|
| `vendor/bin/pest` (suíte completa) | 655 testes, 3.482 asserções, todos aprovados |
| Cobertura de linhas do núcleo (`app/Domain` + `app/Actions`, PCOV) | 97,96% (433/442) |
| `vendor/bin/phpstan analyse` (nível 8) | sem erros |
| `vendor/bin/pint --test` | sem pendências |
| `composer audit` | sem vulnerabilidades conhecidas |
| Testes de concorrência (processos separados) | corrida entre proprietários; convites simultâneos; aceite simultâneo |

### Defeitos encontrados pela suíte e pela revisão adversarial (corrigidos)

| Defeito | Correção |
|---|---|
| Deadlock ao revogar proprietários em cruz | ordem única de locks (ADR-006) |
| Troca de contexto, aceite de convite e onboarding falhavam com contexto ativo | elevação para contextos do mesmo usuário (ADR-014) |
| Convites de equipe visíveis e graváveis em contexto de comprador | política RLS por empresa |
| Associações de outros tenants visíveis dentro de um tenant | `own_memberships` só no contexto de usuário |
| 2FA, contexto e e-mail verificado não reaplicados em ações Livewire com JSON | bloqueio por exceção ou redirect; middleware persistente |
| Sessões antigas válidas após redefinição de senha | `AuthenticateSession` e remoção das sessões persistidas |
| Links de e-mail gerados a partir do cabeçalho Host | `URL::forceRootUrl` e hosts confiáveis |
| Códigos de recuperação exibidos sem confirmação de senha | `password.confirm` e `no-store` |
| Força bruta distribuída no desafio 2FA | limite por conta e por IP |
| Convites simultâneos ao mesmo destinatário geravam erro de banco | lock consultivo por destinatário |
| Proprietário recém-revogado concluía operação de propriedade | revalidação do ator sob lock |
| Identificadores malformados e entradas em lista geravam erro 500 | tratamento como inexistente / validação |
| Fila de notificações sem consumidor | Horizon com supervisores por fila |
| Conexões de teste esgotavam `max_connections` | fechamento explícito por teste |
| Gate de cobertura não media o núcleo (passava sem dados) | normalização de caminhos e falha sem dados |

### Pendente neste incremento

- Execução da CI no GitHub para a branch (após o push).
- Aprovação humana obrigatória nas proteções de branch: não habilitada
  (mantenedor único).

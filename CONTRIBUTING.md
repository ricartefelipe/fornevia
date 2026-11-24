# Contribuindo com o Fornevia

## Fluxo de branches (GitFlow)

| Branch | Origem | Destino | Uso |
|---|---|---|---|
| `main` | — | — | Versões estáveis de produção; cada merge recebe tag `vX.Y.Z` |
| `develop` | `main` | — | Integração contínua das features |
| `feature/<descricao>` | `develop` | `develop` (PR) | Novas funcionalidades |
| `release/<versao>` | `develop` | `main` (PR) e reintegração em `develop` | Estabilização da versão |
| `hotfix/<versao>` | `main` | `main` (PR), reintegração em `develop` e na release ativa | Correções urgentes de produção |

Regras:

- Não desenvolver diretamente em `main` ou `develop`.
- Criar a branch antes das alterações correspondentes.
- Merges com commit de merge (`--no-ff` ou "Create a merge commit" no
  GitHub), preservando a identificação da branch no histórico.
- Sem force push em branches compartilhadas e sem reescrever histórico
  publicado. Proteções e hooks não são contornados.
- Após resolver conflitos, executar novamente as verificações afetadas.
- Tags anotadas: `git tag -a v0.1.0 -m "Fornevia 0.1.0"`.

## Commits

[Conventional Commits](https://www.conventionalcommits.org/pt-br/), em
português, pequenos e coesos:

```
feat(pedidos): confirmar pedido com reserva de estoque e crédito
fix(estoque): liberar reserva ao cancelar item não expedido
test(concorrencia): disputa pela última unidade com processos separados
docs(arquitetura): fluxo de aprovação por alçada
chore(ci): fixar versão da action de checkout
```

Escopos usuais: `plataforma`, `identidade`, `compradores`, `catalogo`,
`precos`, `cotacoes`, `aprovacoes`, `pedidos`, `estoque`, `credito`,
`expedicao`, `devolucoes`, `financeiro`, `integracoes`, `auditoria`,
`assinaturas`, `ui`, `api`, `infra`, `ci`, `docs`.

Versionamento semântico; `CHANGELOG.md` atualizado em cada release.

## Pull requests

A descrição deve conter:

1. **Problema** — o que motivou a mudança.
2. **Alteração** — o que foi feito e por quê.
3. **Validação** — comandos executados e resultados (testes, análise
   estática, verificação manual).
4. **Limitações** — o que não foi feito ou não foi exercitado.

Merges exigem os checks da CI aprovados e as aprovações configuradas na
proteção da branch.

## Ambiente local

```bash
cp .env.example .env
docker compose build
docker compose up -d postgres redis mailpit
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate   # uma única vez
docker compose run --rm migrate
docker compose run --rm node                           # build dos assets
docker compose up -d
```

## Testes em paralelo

O banco de testes padrão é `fornevia_test`. Para execuções paralelas,
use bancos com sufixo `_test` provisionados pelo script de papéis e
informe-os por variável de ambiente:

```bash
docker compose run --rm -e DB_DATABASE=fornevia_w1_test app vendor/bin/pest tests/Feature
```

Testes de concorrência usam `tests/Support/Concurrency` (processos PHP
separados, portões por advisory lock e verificação de bloqueio por lock).

## Verificações antes do PR

```bash
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse --memory-limit=1G
docker compose run --rm app composer audit
docker compose --profile tools run --rm node sh -c \
  "npm audit --json > build/npm-audit.json; node scripts/npm-audit-gate.mjs build/npm-audit.json"
docker compose run --rm app vendor/bin/pest
docker compose run --rm app composer test:mutation
docker compose --profile browser up -d selenium && sh scripts/browser-tests.sh
```

Testes de navegador (`tests/Browser`, Dusk) rodam contra um servidor da
aplicação sobre o banco `fornevia_browser`, com Chrome real; capturas de
falha ficam em `tests/Browser/screenshots`. Testes de mutação cobrem
`App\Domain` e o CSV de integrações, com nota mínima de 90% no código
coberto pelos testes unitários.

Alertas de dependências npm sem correção publicada só são aceitos por
exceção registrada em `.github/npm-audit-exceptions.json`, com justificativa
e data de revisão (ADR-021). Exceção vencida ou sem alerta correspondente
reprova a CI.

## Convenções de código

- Nomes de arquivos não podem diferir apenas por maiúsculas/minúsculas
  (o projeto precisa funcionar em sistemas de arquivos que não fazem essa
  distinção).

- `declare(strict_types=1)` em todo PHP próprio; tipos em parâmetros e
  retornos; PHPStan nível 8 sem baseline.
- Regras de negócio em `app/Domain` (puras) e `app/Actions` (transacionais).
  Controllers e componentes Livewire apenas coordenam.
- Modelos de tenant usam `BelongsToTenant`; `tenant_id`, papéis, preços e
  limites nunca entram em `$fillable`.
- Toda tabela nova com `tenant_id` usa `TenantSchema::tenant()`,
  `TenantSchema::reference()` para FKs e
  `TenantSchema::enableRowLevelSecurity()`. Os testes de schema falham caso
  contrário.
- Dinheiro em centavos inteiros e `BigDecimal`; nunca `float`.
- Jobs recebem identificadores (tenant e entidade), nunca models.
- Sem chamadas externas dentro de transações.
- Testes de comportamento transacional e de concorrência usam PostgreSQL
  real, sem transação envolvendo o teste.

# Fornevia

Plataforma SaaS B2B multitenant para vendas corporativas de distribuidores e
atacadistas: catálogo e preços por cliente, cotações negociadas, aprovações
de compra, estoque, crédito comercial, expedição, devoluções e financeiro.

## Para quem

- Distribuidores de materiais elétricos.
- Atacadistas de produtos profissionais.
- Distribuidores de suprimentos industriais.
- Fornecedores de materiais para empresas.

Cada distribuidor é um **tenant**. Dentro dele, empresas compradoras
possuem usuários, unidades e centros de custo e compram com autonomia pelo
portal, respeitando as políticas comerciais do distribuidor.

## Arquitetura em resumo

Monólito modular Laravel 13 / PHP 8.5, PostgreSQL 18 com Row-Level
Security forçada, Redis para filas (Horizon) e cache, Blade + Livewire 4 +
Tailwind 4. Detalhes em [docs/ARQUITETURA.md](docs/ARQUITETURA.md) e
decisões em [docs/DECISOES.md](docs/DECISOES.md).

## Executando localmente

Pré-requisitos: Docker com Compose v2.

```bash
cp .env.example .env
docker compose build
docker compose up -d postgres redis mailpit
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate   # gere a chave uma única vez
docker compose run --rm migrate                        # migrations com o papel de manutenção
docker compose run --rm node                           # build dos assets
docker compose up -d
```

Aplicação: <http://localhost:8090> · Mailpit: <http://localhost:8035>.

Dados de demonstração (dois distribuidores com equipe, catálogo,
compradores e pedidos; recusado em produção):

```bash
docker compose run --rm app php artisan demo:seed --password='<senha com 12+ caracteres>'
```

Usuários e perfis em [docs/runbooks/dados-demonstracao.md](docs/runbooks/dados-demonstracao.md).
As portas podem ser alteradas por `FORWARD_WEB_PORT`, `FORWARD_DB_PORT`,
`FORWARD_REDIS_PORT` e `FORWARD_MAILPIT_UI_PORT`.

## Testes

```bash
docker compose run --rm app vendor/bin/pest                 # suíte completa
docker compose run --rm app vendor/bin/pest --testsuite=Unit
docker compose run --rm -e FORNEVIA_TEST_FRESH=1 app vendor/bin/pest   # recria o schema de teste
docker compose run --rm app composer test:mutation         # mutação do domínio
docker compose --profile browser up -d selenium && sh scripts/browser-tests.sh   # navegador
```

Os testes usam o PostgreSQL real (`fornevia_test`) com o papel de
aplicação, sujeito a RLS. O banco é limpo por `TRUNCATE` entre testes, sem
transação envolvendo o teste.

## Implantação

Implantação de referência em um host com `compose.prod.yaml` (imagens de
produção, processos sem privilégios, sistema de arquivos somente leitura,
migrations em processo separado). Procedimentos de implantação, rotação de
chave, backup, outbox, gateway e alertas em [docs/runbooks](docs/runbooks/README.md).

## Documentação

- [Requisitos e critérios de aceite](docs/REQUISITOS.md)
- [Arquitetura](docs/ARQUITETURA.md)
- [Decisões](docs/DECISOES.md)
- [Status da implementação](docs/STATUS.md)
- [Rastreabilidade dos requisitos](docs/RASTREABILIDADE.md)
- [Runbooks de operação](docs/runbooks/README.md)
- [Contrato da API v1 (OpenAPI)](docs/openapi.json)
- [Contribuição e GitFlow](CONTRIBUTING.md)
- [Changelog](CHANGELOG.md)

## Limitações conhecidas

Ver [docs/STATUS.md](docs/STATUS.md).

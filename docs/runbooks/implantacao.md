# Implantação e atualização

## Pré-requisitos

- Host com Docker e Compose v2, proxy reverso com TLS à frente do serviço
  `web` (porta `WEB_PORT`, padrão 8080, publicada só em `WEB_BIND`).
- `.env.production` criado a partir de `.env.production.example`, com
  segredos fortes e exclusivos. `APP_KEY` gerada uma única vez e guardada no
  cofre de segredos.
- `TRUSTED_PROXIES` com o endereço do proxy; `APP_URL` com o endereço
  público em HTTPS.

## Primeira instalação

```bash
fv build
fv up -d postgres redis          # o init cria os papéis owner, app e relay
fv run --rm migrate              # schema com o papel de manutenção
fv up -d                         # app, web, horizon, scheduler, outbox
curl -fsS -H 'Host: app.exemplo.com.br' http://127.0.0.1:8080/health/ready
```

O `Host` precisa ser o de `APP_URL` (ou um de `METRICS_SCRAPE_HOSTS`):
requisições com outro host são recusadas com 400.

`/health/ready` responde 503 enquanto houver migration pendente ou o banco
ou o Redis estiverem indisponíveis.

## Nova versão

1. Confira o CHANGELOG da versão e as migrations novas (`database/migrations`).
2. Gere as imagens com a tag da versão: `FORNEVIA_TAG=1.0.0 fv build`.
3. Faça o backup do banco ([backup-restauracao.md](backup-restauracao.md)).
4. Rode as migrations: `FORNEVIA_TAG=1.0.0 fv run --rm migrate`.
5. Suba os processos: `FORNEVIA_TAG=1.0.0 fv up -d`. O Horizon termina os
   jobs em andamento (60 s) e o relay da outbox devolve as mensagens
   reivindicadas antes de parar.
6. Verifique `/health/ready` (com o `Host` de `APP_URL`), o login e `fornevia_http_requests_total` com
   status `5xx` em [alertas](alertas.md).

## Volta de versão

- Sem migrations novas: suba a tag anterior (`FORNEVIA_TAG=<anterior> fv up -d`).
- Com migrations novas: prefira corrigir para frente. Se for preciso voltar,
  rode `fv run --rm migrate php artisan migrate:rollback --step=<n> --force`
  com a imagem da versão nova (que conhece o `down` dessas migrations) e
  depois suba a tag anterior. Migrations que removem dados exigem
  restauração do backup.

## Manutenção programada

```bash
fv exec app php artisan down --retry=60
# ... procedimento ...
fv exec app php artisan up
```

Com a aplicação em manutenção, `/health/live` continua respondendo e as
métricas contam as respostas 503.

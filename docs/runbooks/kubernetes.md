# Kubernetes com Helm (alta disponibilidade)

Chart em `deploy/helm/fornevia`. Substitui o `compose.prod.yaml` quando o
serviço precisa sobreviver à perda de um nó e ser atualizado sem
indisponibilidade. Decisão registrada na ADR-032.

## O que o chart cria

| Recurso | Réplicas | Observação |
|---|---|---|
| `app` (PHP-FPM) | 2 a 8 (HPA, CPU 70%) | Service `fastcgi` na porta 9000 |
| `web` (nginx) | 2 a 6 (HPA, CPU 70%) | Service `http` na porta 80; destino do Ingress |
| `horizon` | 2 | filas no Redis; workers por pod em `HORIZON_*_MAX_PROCESSES` |
| `outbox` | 2 | o relay reivindica mensagens com `SKIP LOCKED` |
| `scheduler` | 1 | estratégia `Recreate`; tarefas com `onOneServer` |
| Job `migrate` | — | hook `pre-install` e `pre-upgrade`, papel `fornevia_owner` |
| PVC `storage` | — | `storage/app` compartilhado (ReadWriteMany), mantido ao desinstalar |

Dimensionamento por pod em `config`: `FPM_MAX_CHILDREN` (10) e
`HORIZON_OPERACAO_MAX_PROCESSES`/`HORIZON_INTEGRACOES_MAX_PROCESSES` (5 e 2).
A capacidade total é esse valor vezes o número de réplicas; ao aumentar,
ajuste os limites de memória (cerca de 50 MiB por processo).

Também: PodDisruptionBudget (`minAvailable: 1`) para `app`, `web`,
`horizon` e `outbox`; distribuição dos pods entre nós
(`topologySpreadConstraints`); contêineres sem root, sem escalonamento de
privilégios e com raiz somente leitura (diretórios graváveis em `emptyDir`).

Fora do chart, por decisão: **PostgreSQL** e **Redis** (serviço gerenciado
ou operador com réplica e failover), **certificado TLS** (cert-manager ou
Secret próprio) e o **controlador de Ingress**.

## Pré-requisitos

1. Banco provisionado com os três papéis (`docker/postgres/init/01-roles.sh`,
   executado uma vez por um superusuário) e o banco `fornevia`.
2. Redis com senha. Sessões ficam no banco; cache, filas e travas do
   scheduler, no Redis: todas as réplicas usam a mesma instância.
3. StorageClass com `ReadWriteMany` (NFS, EFS, Filestore, Azure Files).
   Com um único nó, `ReadWriteOnce` serve.
4. Imagens publicadas no registro (`fornevia-app` e `fornevia-web`, alvos
   `prod` e `web` do `docker/php/Dockerfile`), com a tag da versão.
5. Secret com os valores sigilosos:

```bash
kubectl -n fornevia create secret generic fornevia-secrets \
  --from-literal=APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" \
  --from-literal=DB_PASSWORD=... --from-literal=DB_OWNER_PASSWORD=... \
  --from-literal=DB_RELAY_PASSWORD=... --from-literal=REDIS_PASSWORD=... \
  --from-literal=METRICS_TOKEN=... --from-literal=MAIL_USERNAME=... \
  --from-literal=MAIL_PASSWORD=...
```

A `APP_KEY` é a mesma em todas as réplicas e entre versões; para trocar,
siga [Rotação da APP_KEY](rotacao-app-key.md) (`APP_PREVIOUS_KEYS` no
mesmo Secret).

## Instalação

Arquivo de valores do ambiente (exemplo mínimo):

```yaml
image:
  app: {repository: registro.exemplo.com.br/fornevia-app, tag: "1.2.0"}
  web: {repository: registro.exemplo.com.br/fornevia-web, tag: "1.2.0"}
config:
  APP_URL: https://app.exemplo.com.br
  DB_HOST: postgres.exemplo.internal
  REDIS_HOST: redis.exemplo.internal
  MAIL_HOST: smtp.exemplo.com.br
  TRUSTED_PROXIES: 10.0.0.0/8
ingress:
  host: app.exemplo.com.br
  tlsSecretName: fornevia-tls
persistence:
  storageClass: nfs
```

```bash
helm upgrade --install fornevia deploy/helm/fornevia -n fornevia -f valores.yaml --wait --timeout 10m
```

O Job de migrations roda antes de qualquer pod da nova versão; se ele
falhar, a instalação para e nada é substituído. Confira com
`kubectl -n fornevia logs job/fornevia-fornevia-migrate-<revisão>` (o Job
é removido quando conclui com sucesso).

`TRUSTED_PROXIES` deve cobrir a rede dos pods do controlador de Ingress,
para que IP do cliente e esquema HTTPS sejam respeitados.

## Atualização

```bash
helm upgrade fornevia deploy/helm/fornevia -n fornevia -f valores.yaml \
  --set image.app.tag=1.2.1,image.web.tag=1.2.1 --wait --timeout 10m
```

- As migrations rodam antes do rollout, enquanto a versão anterior ainda
  atende: toda migration precisa ser compatível com a versão anterior
  (adicionar antes de usar; remover coluna só na versão seguinte à que
  deixou de usá-la).
- Rollout com `maxUnavailable: 0`: cada pod novo só recebe tráfego depois
  de pronto, e os antigos saem com espera de 5 s para o Service deixar de
  enviá-los requisições.
- Workers recebem `SIGTERM` (repassado pelo `tini`, PID 1 da imagem) e
  terminam o job em curso dentro do prazo (`terminationGracePeriodSeconds`:
  150 s no Horizon, acima do maior timeout de job, de 120 s; 45 s no relay).
  PHP-FPM e nginx têm 80 s, acima dos 65 s e 70 s de limite por requisição.

## Volta de versão

```bash
helm history fornevia -n fornevia
helm rollback fornevia <revisão> -n fornevia --wait
```

O rollback não desfaz migrations: como elas são compatíveis com a versão
anterior, a imagem antiga roda sobre o schema novo. Para desfazer uma
migration, use o procedimento de [Implantação e atualização](implantacao.md)
com o Job avulso (`php artisan migrate:rollback --step=1` com
`DB_CONNECTION=pgsql_owner`).

## Verificação

```bash
kubectl -n fornevia get pods,hpa,pdb
kubectl -n fornevia exec deploy/fornevia-fornevia-web -- \
  wget -qO- --header "Host: app.exemplo.com.br" http://127.0.0.1:8080/health/ready
```

`/health/ready` responde só ao host de `APP_URL`. A prontidão do `web`
depende do PHP, do banco e do Redis; a vivacidade do `web` é só a porta do
nginx, para que uma falha do banco não reinicie os proxies.

Métricas: o Prometheus raspa `http://fornevia-fornevia-web/metrics` com o
`METRICS_TOKEN`; inclua o nome do Service em `METRICS_SCRAPE_HOSTS`
(ver [Alertas e métricas](alertas.md)).

## Teste em cluster local

```bash
docker compose -f compose.prod.yaml --env-file .env.production build app web
sh scripts/k8s-smoke.sh
```

Sobe um k3s em contêiner, importa as imagens locais, cria PostgreSQL e
Redis de teste (`deploy/kubernetes/dependencias-teste.yaml`, sem réplica,
só para o teste), instala o chart com `ci/local-values.yaml` e verifica:
migrations pelo Job, réplicas prontas, `/health/ready`, nenhuma requisição
com erro durante a remoção de um pod da aplicação e durante um `helm
upgrade`. `KEEP_CLUSTER=1` mantém o cluster para inspeção
(`docker exec -it fornevia-k3s kubectl -n fornevia get pods`).

Validação estática, sem cluster:

```bash
docker run --rm -v "$PWD/deploy/helm:/apps" -w /apps alpine/helm:3.19.0 lint fornevia --strict
docker run --rm -v "$PWD/deploy/helm:/apps" -w /apps alpine/helm:3.19.0 template fv fornevia \
  | docker run --rm -i ghcr.io/yannh/kubeconform:v0.7.0 -strict -summary -
```

## Problemas comuns

| Sintoma | Causa provável | Ação |
|---|---|---|
| Job `migrate` em `BackoffLimitExceeded` | banco inacessível ou migration com erro | `kubectl logs job/...`; corrigir e repetir o `helm upgrade` |
| Pods `web` não ficam prontos | `APP_URL` com host diferente do usado na sonda, ou `app` indisponível | conferir `config.APP_URL`; `kubectl logs deploy/fornevia-fornevia-app` |
| `APP_KEY ausente` nos logs | Secret sem `APP_KEY` ou com outro nome | conferir `existingSecret` |
| PVC `Pending` | StorageClass sem `ReadWriteMany` | usar uma classe RWX ou `persistence.existingClaim` |
| Sessões perdidas entre pods | `APP_KEY` diferente entre réplicas | uma única `APP_KEY` no Secret |

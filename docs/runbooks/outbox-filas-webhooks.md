# Outbox, filas e webhooks

## Outbox atrasada (`fornevia_outbox_oldest_pending_age_seconds` alto)

1. `fv ps outbox` — o relay deve estar em execução.
2. `fv exec app php artisan outbox:status` — pendentes, mais antiga,
   reivindicadas e sem novas tentativas.
3. Logs: `fv logs --tail=200 outbox`. Falha de conexão com o banco ou o
   Redis aparece aqui.
4. Mensagens sem novas tentativas (`outbox_dead`) precisam de análise: veja o
   erro no log, corrija a causa e reenvie com `php artisan outbox:retry`.

O consumo é idempotente por mensagem e consumidor: reenviar não duplica
notificações nem entregas de webhook.

## Fila acumulada (`fornevia_queue_size`)

1. Horizon em `/horizon` (administradores da plataforma ou
   `HORIZON_ALLOWED_EMAILS`): processos ativos e tempo de espera por fila.
2. Se o Horizon estiver parado: `fv restart horizon`.
3. Fila `integrations` acumulada costuma indicar gateway ou endpoints de
   webhook lentos: confira [gateway](gateway-pagamentos.md) e as entregas
   abaixo.

## Jobs com falha (`fornevia_jobs_failed_total`, `fornevia_failed_jobs`)

- `fv exec app php artisan queue:failed` lista as falhas com a exceção.
- Corrigida a causa, `php artisan queue:retry <id>` ou `queue:retry all`.
  Os jobs do Fornevia são idempotentes (cobrança pela referência externa,
  aplicação de CSV por linha, entrega de webhook pela reserva da tentativa).
- Importação CSV interrompida fica como "Falhou"; o distribuidor usa
  "Reprocessar" em Integrações → Importação CSV.

## Webhooks com falha

- Integrações → Webhooks mostra as entregas recentes, a resposta e o erro.
- Novas tentativas: 1 min, 5 min, 30 min, 2 h, 6 h, 12 h e 24 h (até 8).
  Depois de 20 falhas seguidas o endpoint é desativado com o motivo; o
  distribuidor reativa pela tela após corrigir o destino.
- Endereços internos ou sem TLS são recusados por desenho (proteção contra
  SSRF); não há exceção em produção.

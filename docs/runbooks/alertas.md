# Alertas e métricas

## Coleta

- Defina `METRICS_TOKEN` (valor longo e aleatório) e, se o coletor acessa
  pela rede interna, `METRICS_SCRAPE_HOSTS` com o host usado (por exemplo,
  `web`).
- Configuração do Prometheus:

```yaml
scrape_configs:
  - job_name: fornevia
    metrics_path: /metrics
    authorization:
      type: Bearer
      credentials_file: /etc/prometheus/fornevia-token
    static_configs:
      - targets: ['web:8080']
```

- Regras de alerta de referência: [prometheus-alertas.yml](prometheus-alertas.yml).

## Resposta a cada alerta

| Alerta | Primeira ação |
|---|---|
| `ForneviaErros5xx` | logs JSON do `app` filtrando `level` error pelo `correlation_id`; última implantação |
| `ForneviaLatenciaAlta` | Horizon e banco: consultas lentas (`pg_stat_activity`), locks |
| `ForneviaOutboxAtrasada` | [outbox](outbox-filas-webhooks.md#outbox-atrasada-fornevia_outbox_oldest_pending_age_seconds-alto) |
| `ForneviaOutboxSemTentativas` | `outbox:status` e reenvio após corrigir a causa |
| `ForneviaFilaAcumulada` | [filas](outbox-filas-webhooks.md#fila-acumulada-fornevia_queue_size) |
| `ForneviaJobsComFalha` | `queue:failed` |
| `ForneviaConflitosBanco` | deadlocks recorrentes indicam ordem de locks divergente da ADR-006; colete o `correlation_id` e a consulta no log |
| `ForneviaColetaIncompleta` | Redis, banco ou fila inacessíveis a partir do `app` |

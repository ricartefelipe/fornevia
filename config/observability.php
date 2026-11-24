<?php

declare(strict_types=1);

/*
| Observabilidade (RQ-121): métricas no formato Prometheus em /metrics,
| protegidas por token. Sem token configurado, o endpoint não existe (404).
*/
return [
    'metrics_token' => env('METRICS_TOKEN'),

    // Hosts internos usados pelo coletor (ex.: "app"), aceitos além do de APP_URL.
    'scrape_hosts' => env('METRICS_SCRAPE_HOSTS', ''),

    // Limites (segundos) do histograma de duração das requisições.
    'latency_buckets' => [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10],

    // Métricas por distribuidor (rótulo tenant com o id da organização):
    // desligadas por padrão. Os primeiros distribuidores vistos, até o
    // limite, ganham série própria (sempre a mesma); os demais somam em
    // tenant="outros", e os contadores só crescem.
    'per_tenant' => (bool) env('METRICS_PER_TENANT', false),
    'tenant_series_limit' => max(1, (int) (env('METRICS_TENANT_LIMIT') ?: 20)),

    // Filas cujo tamanho é exposto.
    'queues' => ['critical', 'default', 'notifications', 'integrations'],
];

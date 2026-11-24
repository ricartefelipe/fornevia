<?php

declare(strict_types=1);

/*
| Integrações (ADR-029): webhooks enviados e importações CSV.
*/
return [
    'webhooks' => [
        // Endereços internos (rede privada, loopback, link-local) só com esta opção (desenvolvimento).
        'allow_private_networks' => (bool) env('WEBHOOKS_ALLOW_PRIVATE_NETWORKS', false),
        // HTTP sem TLS só fora de produção.
        'allow_http' => (bool) env('WEBHOOKS_ALLOW_HTTP', env('APP_ENV', 'production') !== 'production'),
        'timeout_seconds' => (int) env('WEBHOOKS_TIMEOUT_SECONDS', 10),
        // Tentativas por entrega e espera antes de cada nova tentativa, em segundos.
        'max_attempts' => 8,
        'backoff_seconds' => [60, 300, 1800, 7200, 21600, 43200, 86400],
        // Falhas seguidas que desativam o endpoint.
        'disable_after_failures' => 20,
    ],
    'imports' => [
        'max_bytes' => 2 * 1024 * 1024,
        'max_rows' => 5000,
    ],

    // Exportações CSV montadas na requisição: acima disso, refinar filtros.
    'exports' => [
        'max_rows' => 10_000,
    ],
];

<?php

declare(strict_types=1);

/*
| Relay e consumo da outbox (ADR-008, ADR-026).
*/
return [
    // Mensagens reivindicadas por rodada do relay.
    'batch' => (int) env('OUTBOX_BATCH', 100),
    // Prazo do lease: mensagem reivindicada e não publicada nesse prazo
    // volta a ser reivindicada.
    'lease_seconds' => 60,
    // Tentativas de publicação antes de a mensagem ficar "dead".
    'max_attempts' => 10,
    // Espera antes da tentativa seguinte, em segundos (a última se repete).
    'backoff_seconds' => [5, 30, 120, 600, 1800, 3600],
    // Pausa do relay quando não há mensagens, em milissegundos.
    'idle_sleep_ms' => (int) env('OUTBOX_IDLE_SLEEP_MS', 1000),
    // Mensagens publicadas são apagadas depois deste prazo, em dias.
    'retention_days' => 30,
    // Intervalo da limpeza feita pelo relay, em segundos.
    'prune_every_seconds' => 3600,
    // Consumidores (OutboxConsumer), na ordem de execução.
    'consumers' => [],
];

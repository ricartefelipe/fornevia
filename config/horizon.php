<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
| Filas do Fornevia (Redis, supervisionadas pelo Horizon):
|   critical       eventos da outbox que afetam operação (pedidos, estoque)
|   default        processamento geral
|   notifications  e-mails e notificações
|   integrations   chamadas a provedores externos e webhooks enviados
|
| O timeout de cada supervisor precisa ser menor que o retry_after da
| conexão redis (config/queue.php), para que um job em execução não seja
| entregue a outro worker.
*/
return [
    'name' => env('HORIZON_NAME'),
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', Str::slug((string) env('APP_NAME', 'fornevia'), '_').'_horizon:'),

    // Painel restrito: autenticação, e-mail verificado e 2FA (administradores
    // da plataforma), além do gate viewHorizon.
    'middleware' => ['web', 'auth', 'verified', 'two-factor'],

    'allowed_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('HORIZON_ALLOWED_EMAILS', ''))))),

    'waits' => [
        'redis:critical' => 30,
        'redis:default' => 60,
        'redis:notifications' => 120,
        'redis:integrations' => 120,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],
    'silenced_tags' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,
    'memory_limit' => 128,

    'defaults' => [
        'operacao' => [
            'connection' => 'redis',
            'queue' => ['critical', 'default', 'notifications'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 3,
            'maxTime' => 3600,
            'maxJobs' => 1000,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 90,
            'nice' => 0,
        ],
        'integracoes' => [
            'connection' => 'redis',
            'queue' => ['integrations'],
            'balance' => 'simple',
            'maxProcesses' => 2,
            'maxTime' => 3600,
            'maxJobs' => 500,
            'memory' => 256,
            'tries' => 5,
            'timeout' => 120,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            // Workers por instância do Horizon; com várias réplicas (chart Helm),
            // cada pod roda a sua parte.
            'operacao' => ['maxProcesses' => (int) env('HORIZON_OPERACAO_MAX_PROCESSES', 10), 'balanceMaxShift' => 1, 'balanceCooldown' => 3],
            'integracoes' => ['maxProcesses' => (int) env('HORIZON_INTEGRACOES_MAX_PROCESSES', 4)],
        ],
        'local' => [
            'operacao' => ['maxProcesses' => 2],
            'integracoes' => ['maxProcesses' => 1],
        ],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        '.env',
    ],
];

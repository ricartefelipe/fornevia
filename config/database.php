<?php

declare(strict_types=1);

use Illuminate\Support\Str;

$pgsql = static fn (string $usernameEnv, string $passwordEnv, string $defaultUser): array => [
    'driver' => 'pgsql',
    'url' => null,
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'fornevia'),
    'username' => env($usernameEnv, $defaultUser),
    'password' => env($passwordEnv, ''),
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => env('DB_SSLMODE', 'prefer'),
    'application_name' => env('DB_APPLICATION_NAME', 'fornevia'),
];

return [

    /*
    | Conexão padrão: papel de aplicação, sem superuser, sem BYPASSRLS e sem
    | propriedade das tabelas. Todas as consultas da aplicação passam por RLS.
    */
    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        // Papel de aplicação (fornevia_app): DML sujeito a RLS.
        'pgsql' => $pgsql('DB_USERNAME', 'DB_PASSWORD', 'fornevia_app'),

        // Papel de manutenção (fornevia_owner): dono do schema; usado apenas
        // por migrations, seeders e tarefas administrativas explícitas.
        'pgsql_owner' => $pgsql('DB_OWNER_USERNAME', 'DB_OWNER_PASSWORD', 'fornevia_owner'),

        // Papel do relay da outbox (fornevia_relay): acesso restrito às
        // tabelas da outbox, atravessando tenants apenas nelas.
        'pgsql_relay' => $pgsql('DB_RELAY_USERNAME', 'DB_RELAY_PASSWORD', 'fornevia_relay'),

    ],

    /*
    | Nomes dos papéis do banco, usados pelas migrations para conceder ou
    | revogar privilégios e criar políticas de RLS específicas.
    */
    'roles' => [
        'app' => env('DB_USERNAME', 'fornevia_app'),
        'owner' => env('DB_OWNER_USERNAME', 'fornevia_owner'),
        'relay' => env('DB_RELAY_USERNAME', 'fornevia_relay'),
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'fornevia')).'-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        // Métricas: tempo limite curto, para Redis indisponível não atrasar requisições.
        'metrics' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'timeout' => (float) env('REDIS_METRICS_TIMEOUT', 0.25),
            'read_timeout' => (float) env('REDIS_METRICS_TIMEOUT', 0.25),
            'max_retries' => 0,
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];

<?php

declare(strict_types=1);

/*
| Pagamentos (ADR-028). O gateway de cada distribuidor fica em
| payment_gateway_settings; aqui ficam os endereços e prazos.
*/
return [
    'asaas' => [
        'base_url' => env('ASAAS_BASE_URL', 'https://api-sandbox.asaas.com/v3'),
        'timeout_seconds' => (int) env('ASAAS_TIMEOUT_SECONDS', 15),
    ],
    // Simulador local: só fora de produção (permite marcar pagamentos sem dinheiro).
    'simulator_enabled' => (bool) env('PAYMENTS_SIMULATOR', env('APP_ENV', 'production') !== 'production'),
    // Pagamentos pendentes no gateway são conferidos depois deste prazo, em minutos.
    'reconcile_after_minutes' => 10,
];

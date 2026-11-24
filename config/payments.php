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
    // Pagamentos pendentes no gateway são conferidos depois deste prazo, em minutos.
    'reconcile_after_minutes' => 10,
];

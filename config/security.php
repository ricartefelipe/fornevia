<?php

declare(strict_types=1);

return [

    'csp' => [
        'enabled' => (bool) env('CSP_ENABLED', true),
        'report_uri' => env('CSP_REPORT_URI'),
    ],

    'passwords' => [
        // Consulta de senhas vazadas (Have I Been Pwned, k-anonymity).
        'breach_check' => (bool) env('PASSWORD_BREACH_CHECK', true),
    ],

    // Perfis que exigem segundo fator (TOTP) antes de acessar o contexto.
    'two_factor' => [
        'enforce' => (bool) env('TWO_FACTOR_ENFORCE', true),
    ],

];

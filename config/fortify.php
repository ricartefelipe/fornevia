<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    // E-mails são citext (comparação sem distinção de caixa) e normalizados
    // nas Actions após a validação; o Fortify não altera a entrada bruta.
    'lowercase_usernames' => false,

    // Após autenticar, o usuário escolhe (ou retoma) o contexto de trabalho.
    'home' => '/contextos',

    'prefix' => '',
    'domain' => null,
    'middleware' => ['web'],

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
    ],

    'views' => true,

    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ],
];

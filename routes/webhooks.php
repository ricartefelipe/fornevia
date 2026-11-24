<?php

declare(strict_types=1);

use App\Http\Controllers\Webhooks\AsaasWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Webhooks recebidos de provedores (RF-112): fora do grupo web (sem sessão
| nem CSRF), autenticados pelo token de cada distribuidor e limitados por
| taxa.
*/

Route::post('/webhooks/pagamentos/asaas/{organization}', AsaasWebhookController::class)
    ->middleware('throttle:240,1')
    ->name('webhooks.asaas');

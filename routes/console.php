<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Vencimento das propostas e das reservas de estoque das cotações (ADR-025).
Schedule::command('quotes:expire')->everyMinute()->withoutOverlapping()->onOneServer();

// Conciliação dos pagamentos pendentes no gateway (ADR-028).
Schedule::command('payments:reconcile')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

// Novas tentativas dos webhooks enviados (ADR-029).
Schedule::command('webhooks:retry')->everyMinute()->withoutOverlapping()->onOneServer();

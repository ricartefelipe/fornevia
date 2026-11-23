<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Vencimento das propostas e das reservas de estoque das cotações (ADR-025).
Schedule::command('quotes:expire')->everyMinute()->withoutOverlapping()->onOneServer();

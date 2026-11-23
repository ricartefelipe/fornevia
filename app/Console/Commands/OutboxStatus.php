<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Outbox\OutboxRelay;
use Illuminate\Console\Command;

/**
 * Situação da outbox para operação: pendentes, idade da mais antiga,
 * reivindicadas e sem novas tentativas. Com --json, para coleta de métricas.
 */
final class OutboxStatus extends Command
{
    protected $signature = 'outbox:status {--json : Saída em JSON}';

    protected $description = 'Mostra a situação da outbox';

    public function handle(OutboxRelay $relay): int
    {
        $stats = $relay->stats();

        if ($this->option('json')) {
            $this->line(json_encode($stats, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(['Pendentes', 'Mais antiga (s)', 'Reivindicadas', 'Sem novas tentativas'], [[
            $stats['pending'], $stats['oldest_pending_seconds'] ?? '—', $stats['leased'], $stats['dead'],
        ]]);

        return self::SUCCESS;
    }
}

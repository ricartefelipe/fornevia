<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DeliverWebhook;
use App\Support\Clock\Clock;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Despacha as novas tentativas de webhook vencidas (agendado a cada minuto).
 */
final class RetryWebhooks extends Command
{
    protected $signature = 'webhooks:retry';

    protected $description = 'Despacha as novas tentativas de entrega de webhooks';

    public function handle(TenantDatabase $db, Clock $clock): int
    {
        $count = 0;

        foreach (DB::table('organizations')->orderBy('id')->pluck('id') as $tenantId) {
            $due = $db->run(ExecutionContext::system((string) $tenantId), static fn (): array => DB::table('webhook_deliveries')
                ->where('status', 'pending')->whereNotNull('next_attempt_at')->where('next_attempt_at', '<=', $clock->now())
                ->orderBy('next_attempt_at')->limit(500)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all());

            foreach ($due as $id) {
                DeliverWebhook::dispatch($id, (string) $tenantId)->onQueue('integrations');
                $count++;
            }
        }

        $this->info("Novas tentativas despachadas: {$count}.");

        return self::SUCCESS;
    }
}

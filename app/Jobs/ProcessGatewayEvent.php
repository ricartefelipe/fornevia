<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Finance\System\GatewayEvents;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processa um evento recebido do gateway no contexto de sistema do tenant.
 */
final class ProcessGatewayEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(
        public readonly string $eventRecordId,
        public readonly string $tenantId,
    ) {}

    public function handle(TenantDatabase $db, GatewayEvents $events): void
    {
        $db->run(ExecutionContext::system($this->tenantId), fn (): string => $events->process($this->eventRecordId));
    }

    public function failed(?Throwable $exception): void
    {
        app(TenantDatabase::class)->run(ExecutionContext::system($this->tenantId), fn () => app(GatewayEvents::class)->markFailed($this->eventRecordId, $exception));
    }
}

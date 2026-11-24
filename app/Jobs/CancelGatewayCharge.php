<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Finance\PaymentGatewayKind;
use App\Services\Finance\Gateways\PaymentGateways;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Cancela no gateway uma cobrança substituída ou sem saldo a cobrar.
 */
final class CancelGatewayCharge implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(
        public readonly string $tenantId,
        public readonly PaymentGatewayKind $gateway,
        public readonly string $chargeId,
    ) {}

    public function handle(TenantDatabase $db, PaymentGateways $gateways): void
    {
        $gateway = $db->run(ExecutionContext::system($this->tenantId), fn () => $gateways->for($this->gateway));
        $gateway->cancel($this->chargeId);
    }
}

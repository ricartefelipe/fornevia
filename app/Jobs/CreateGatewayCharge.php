<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Finance\CreateCharge;
use App\Domain\Finance\Exceptions\GatewayUnavailable;
use App\Models\Finance\Payment;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Cria no gateway a cobrança de um pagamento pendente, fora da transação
 * da requisição. Gateway indisponível: o motivo fica no pagamento e a
 * conciliação tenta de novo.
 */
final class CreateGatewayCharge implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly string $paymentId, public readonly string $tenantId) {}

    public function handle(TenantDatabase $db, CreateCharge $charges): void
    {
        $payment = $db->run(ExecutionContext::system($this->tenantId), fn (): ?Payment => Payment::query()->find($this->paymentId));

        if ($payment === null) {
            return;
        }

        try {
            $charges->charge($payment);
        } catch (GatewayUnavailable) {
            // Registrado no pagamento; a conciliação retoma.
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Finance\System;

use App\Domain\Finance\Exceptions\PaymentNotAllowed;
use App\Enums\Finance\PaymentGatewayKind;
use App\Enums\Finance\PaymentStatus;
use App\Models\Finance\Payment;
use App\Support\Http\UntrustedId;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Str;

/**
 * Página do simulador local: quem vê a cobrança simula o pagamento ou a
 * recusa, gerando o mesmo evento que um webhook do gateway (com taxa
 * simulada de 1%), processado pela mesma fila.
 */
final readonly class SimulatePayment
{
    public function __construct(private TenantDatabase $db, private GatewayEvents $events) {}

    public function visible(mixed $paymentId): Payment
    {
        $payment = Payment::query()->findOrFail(UntrustedId::uuid($paymentId, Payment::class));

        if ($payment->gateway !== PaymentGatewayKind::Simulator) {
            throw new PaymentNotAllowed('Esta cobrança não é do simulador.');
        }

        return $payment;
    }

    public function handle(mixed $paymentId, bool $paid): void
    {
        $payment = $this->visible($paymentId);

        if ($payment->status !== PaymentStatus::Pending || $payment->gateway_charge_id === null) {
            throw new PaymentNotAllowed('A cobrança não está aguardando pagamento.');
        }

        $this->db->asSystem(fn (): bool => $this->events->record($payment->tenant_id, PaymentGatewayKind::Simulator, 'sim-'.Str::uuid7(), $paid ? 'PAYMENT_RECEIVED' : 'PAYMENT_OVERDUE', $payment->gateway_charge_id, [
            'value_cents' => $payment->amount_cents->cents,
            'fee_cents' => intdiv($payment->amount_cents->cents, 100),
        ]));
    }
}

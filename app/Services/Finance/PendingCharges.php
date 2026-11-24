<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Finance\PaymentSource;
use App\Enums\Finance\PaymentStatus;
use App\Jobs\CancelGatewayCharge;
use App\Models\Finance\Payment;
use App\Models\Finance\ReceivableInstallment;

/**
 * Cobranças pendentes no gateway deixam de valer quando o saldo da parcela
 * muda ou outra cobrança as substitui: ficam canceladas aqui e são
 * canceladas no gateway depois do commit. Se alguma for paga mesmo assim,
 * a confirmação registra o valor como não aplicado e reembolsável.
 */
final class PendingCharges
{
    public function cancelFor(ReceivableInstallment $installment, ?string $exceptPaymentId, string $reason): int
    {
        $pending = Payment::query()
            ->where('installment_id', $installment->id)
            ->where('source', PaymentSource::Gateway->value)
            ->where('status', PaymentStatus::Pending->value)
            ->when($exceptPaymentId !== null, static fn ($q) => $q->whereKeyNot($exceptPaymentId))
            ->lockForUpdate()
            ->get();

        foreach ($pending as $payment) {
            $payment->forceFill(['status' => PaymentStatus::Cancelled, 'failure_reason' => $reason])->save();

            if ($payment->gateway !== null && $payment->gateway_charge_id !== null) {
                CancelGatewayCharge::dispatch($payment->tenant_id, $payment->gateway, $payment->gateway_charge_id)->onQueue('critical')->afterCommit();
            }
        }

        return $pending->count();
    }
}

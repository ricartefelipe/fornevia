<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Shared\Money;
use App\Enums\Finance\RefundStatus;
use App\Models\Finance\PaymentRefund;
use App\Services\Audit\AuditRecorder;
use App\Support\Outbox\Outbox;

/**
 * Conclui um reembolso na transação do chamador: libera a reserva e, se
 * efetivado, consome primeiro o valor não aplicado do pagamento (que não
 * quitava nada) e reabre na parcela só o restante.
 */
final readonly class RefundSettlement
{
    public function __construct(
        private FinanceLocks $locks,
        private Receivables $receivables,
        private Outbox $outbox,
        private AuditRecorder $audit,
    ) {}

    public function finish(string $refundId, bool $done, ?string $gatewayRefundId): PaymentRefund
    {
        $paymentId = PaymentRefund::query()->findOrFail($refundId)->payment_id;
        [, $invoice, $installment, $payment] = $this->locks->payment($paymentId);
        $refund = PaymentRefund::query()->where('payment_id', $payment->id)->lockForUpdate()->findOrFail($refundId);

        if (! in_array($refund->status, [RefundStatus::Pending, RefundStatus::Verifying], true)) {
            return $refund;
        }

        $amount = $refund->amount_cents;
        $fromUnapplied = $done ? Money::min($amount, $payment->unapplied_cents) : Money::zero();
        $payment->forceFill([
            'refund_pending_cents' => $payment->refund_pending_cents->minus($amount),
            'refunded_cents' => $done ? $payment->refunded_cents->plus($amount) : $payment->refunded_cents,
            'unapplied_cents' => $payment->unapplied_cents->minus($fromUnapplied),
        ])->save();
        $refund->forceFill(['status' => $done ? RefundStatus::Done : RefundStatus::Failed, 'gateway_refund_id' => $gatewayRefundId ?? $refund->gateway_refund_id])->save();

        if ($done) {
            $reopen = $amount->minus($fromUnapplied);

            if ($reopen->isPositive()) {
                $this->receivables->refund($installment, $payment, $reopen, $refund->reason);
            }

            $this->outbox->record('payment.refunded', 'payment', $payment->id, ['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount_cents' => $amount->cents]);
        }

        $this->audit->record($done ? 'payment.refunded' : 'payment.refund_failed', $payment, ['amount_cents' => $amount->cents, 'buyer_company_id' => $payment->buyer_company_id], $refund->reason);

        return $refund;
    }

    /**
     * Sem resposta do gateway: em verificação, com a reserva mantida. Marca
     * o início da verificação e se o pedido levou a referência do reembolso
     * (só então a conferência pode concluir sozinha um reembolso sem registro).
     */
    public function verifying(string $refundId, string $reason, bool $referenceSent = false): void
    {
        PaymentRefund::query()->whereKey($refundId)->where('status', RefundStatus::Pending->value)->update([
            'status' => RefundStatus::Verifying->value,
            'verification_started_at' => now(),
            'gateway_reference_sent' => $referenceSent,
        ]);
        $this->audit->record('payment.refund_unverified', PaymentRefund::query()->findOrFail($refundId), [], $reason);
    }
}

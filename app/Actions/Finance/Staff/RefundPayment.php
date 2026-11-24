<?php

declare(strict_types=1);

namespace App\Actions\Finance\Staff;

use App\Domain\Finance\Exceptions\GatewayUnavailable;
use App\Domain\Finance\Exceptions\InvalidPayment;
use App\Domain\Finance\Exceptions\RefundNotAllowed;
use App\Domain\Finance\RefundLimit;
use App\Enums\Finance\PaymentSource;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Finance\RefundStatus;
use App\Enums\Identity\Permission;
use App\Models\Finance\PaymentRefund;
use App\Services\Audit\AuditRecorder;
use App\Services\Finance\FinanceLocks;
use App\Services\Finance\Gateways\PaymentGateways;
use App\Services\Finance\Receivables;
use App\Support\Auth\Access;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Reembolso de pagamento confirmado (RF-100, RN-101). O valor é reservado
 * como pendente sob o lock do pagamento antes de qualquer chamada ao
 * gateway: a soma dos pendentes e efetivados nunca passa do pago (também
 * garantido por CHECK). Efetivado, o valor volta a ser devido na parcela;
 * recusado pelo gateway, a reserva é desfeita.
 */
final readonly class RefundPayment
{
    public function __construct(
        private Access $access,
        private FinanceLocks $locks,
        private Receivables $receivables,
        private PaymentGateways $gateways,
        private Outbox $outbox,
        private AuditRecorder $audit,
    ) {}

    public function handle(mixed $paymentId, string $amount, string $reason): PaymentRefund
    {
        $this->access->authorize(Permission::FinanceManage);
        $value = RecordPayment::money($amount);
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new InvalidPayment('Informe o motivo do reembolso (de 5 a 500 caracteres).', ['field' => 'reason']);
        }

        [$refund, $payment] = DB::transaction(function () use ($paymentId, $value, $reason): array {
            [, , , $payment] = $this->locks->payment($paymentId);

            if ($payment->status !== PaymentStatus::Confirmed) {
                throw new RefundNotAllowed('Só pagamentos confirmados são reembolsados.');
            }

            $available = RefundLimit::available($payment->amount_cents, $payment->refunded_cents, $payment->refund_pending_cents);

            if ($value->greaterThan($available)) {
                throw new RefundNotAllowed("O reembolso passa do valor elegível ({$available->format()}).", ['field' => 'amount']);
            }

            $payment->forceFill(['refund_pending_cents' => $payment->refund_pending_cents->plus($value)])->save();
            $refund = new PaymentRefund;
            $refund->forceFill([
                'buyer_company_id' => $payment->buyer_company_id,
                'payment_id' => $payment->id,
                'amount_cents' => $value,
                'status' => RefundStatus::Pending,
                'reason' => $reason,
                'created_by_user_id' => $this->access->userId(),
            ])->save();

            return [$refund, $payment];
        });

        $gatewayRefundId = null;

        if ($payment->source === PaymentSource::Gateway && $payment->gateway_charge_id !== null) {
            try {
                $gatewayRefundId = $this->gateways->current()->refund($payment->gateway_charge_id, $value);
            } catch (GatewayUnavailable $e) {
                $this->finish($refund, false, null);

                throw $e;
            }
        }

        return $this->finish($refund, true, $gatewayRefundId);
    }

    private function finish(PaymentRefund $refund, bool $done, ?string $gatewayRefundId): PaymentRefund
    {
        return DB::transaction(function () use ($refund, $done, $gatewayRefundId): PaymentRefund {
            [, $invoice, $installment, $payment] = $this->locks->payment($refund->payment_id);
            $refund = PaymentRefund::query()->lockForUpdate()->findOrFail($refund->id);

            $payment->forceFill([
                'refund_pending_cents' => $payment->refund_pending_cents->minus($refund->amount_cents),
                'refunded_cents' => $done ? $payment->refunded_cents->plus($refund->amount_cents) : $payment->refunded_cents,
            ])->save();
            $refund->forceFill(['status' => $done ? RefundStatus::Done : RefundStatus::Failed, 'gateway_refund_id' => $gatewayRefundId])->save();

            if ($done) {
                $this->receivables->refund($installment, $payment, $refund->amount_cents, $refund->reason);
                $this->outbox->record('payment.refunded', 'payment', $payment->id, ['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount_cents' => $refund->amount_cents->cents]);
            }

            $this->audit->record($done ? 'payment.refunded' : 'payment.refund_failed', $payment, ['amount_cents' => $refund->amount_cents->cents, 'buyer_company_id' => $payment->buyer_company_id], $refund->reason);

            return $refund;
        });
    }
}

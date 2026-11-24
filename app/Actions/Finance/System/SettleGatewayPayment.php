<?php

declare(strict_types=1);

namespace App\Actions\Finance\System;

use App\Domain\Shared\Money;
use App\Enums\Finance\InstallmentStatus;
use App\Enums\Finance\PaymentStatus;
use App\Services\Audit\AuditRecorder;
use App\Services\Finance\FinanceLocks;
use App\Services\Finance\Gateways\ChargeResult;
use App\Services\Finance\Receivables;
use App\Support\Clock\Clock;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Aplica a situação informada pelo gateway (webhook ou conciliação).
 *
 * - Paga: vale para pagamento pendente, recusado ou cancelado (cobrança paga
 *   depois de vencida ou substituída). O recebido fica no pagamento; juros e
 *   multa (pago acima do valor original) entram como ajuste da parcela; o
 *   que passar do saldo em aberto, ou a parcela já quitada, fica não
 *   aplicado e reembolsável.
 * - Removida no gateway: o pagamento pendente fica recusado.
 * - Já confirmado não muda: o mesmo aviso de novo não tem efeito.
 *
 * Roda no contexto de sistema do tenant. Ordem de locks (ADR-006): pedido →
 * fatura → parcela → pagamento → conta de crédito.
 */
final readonly class SettleGatewayPayment
{
    public function __construct(
        private FinanceLocks $locks,
        private Receivables $receivables,
        private Outbox $outbox,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    /** @return bool se o pagamento mudou */
    public function handle(string $paymentId, ChargeResult $result): bool
    {
        return DB::transaction(function () use ($paymentId, $result): bool {
            [, $invoice, $installment, $payment] = $this->locks->payment($paymentId);

            if ($payment->status === PaymentStatus::Confirmed || $result->status === ChargeResult::PENDING) {
                return false;
            }

            if ($result->status === ChargeResult::FAILED) {
                if ($payment->status !== PaymentStatus::Pending) {
                    return false;
                }

                $payment->forceFill(['status' => PaymentStatus::Failed, 'failure_reason' => 'Cobrança removida no gateway sem pagamento.'])->save();
                $this->audit->record('payment.failed', $payment, ['invoice_id' => $invoice->id, 'buyer_company_id' => $payment->buyer_company_id]);

                return true;
            }

            $paid = $result->paidAmount ?? $payment->amount_cents;
            $open = $installment->status === InstallmentStatus::Open ? Receivables::open($installment) : Money::zero();

            // Juros e multa de pagamento em atraso: acréscimo do devido.
            if ($open->isPositive() && $result->originalAmount !== null && $paid->greaterThan($result->originalAmount)) {
                $interest = $paid->minus($result->originalAmount);
                $this->receivables->adjust($installment, $interest, 'Juros e multa recebidos no gateway', $payment->id);
                $open = $open->plus($interest);
            }

            $applied = Money::min($paid, $open);
            $payment->forceFill([
                'amount_cents' => $paid,
                'unapplied_cents' => $paid->minus($applied),
                'fee_cents' => Money::min($result->fee ?? Money::zero(), $paid),
                'status' => PaymentStatus::Confirmed,
                'confirmed_at' => $this->clock->now(),
                'failure_reason' => $applied->equals($paid) ? null : 'Parte do valor recebido não quitou a parcela; está disponível para reembolso.',
            ])->save();

            $this->receivables->pay($installment, $payment);
            $this->outbox->record('payment.confirmed', 'payment', $payment->id, ['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount_cents' => $paid->cents]);
            $this->audit->record('payment.confirmed', $payment, ['invoice_id' => $invoice->id, 'amount_cents' => $paid->cents, 'unapplied_cents' => $paid->minus($applied)->cents, 'buyer_company_id' => $payment->buyer_company_id]);

            return true;
        });
    }
}

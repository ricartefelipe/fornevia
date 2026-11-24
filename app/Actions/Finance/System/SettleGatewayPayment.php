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
 * Aplica a situação informada pelo gateway (webhook ou conciliação) a um
 * pagamento pendente: confirmado quita a parcela com o valor efetivamente
 * pago e registra a taxa; falhou encerra a cobrança. Pagamento já
 * confirmado ou encerrado não muda: o mesmo aviso recebido de novo não tem
 * efeito (RN-091 aplicado ao financeiro).
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

            if ($payment->status !== PaymentStatus::Pending || $result->status === ChargeResult::PENDING) {
                return false;
            }

            if ($result->status === ChargeResult::FAILED) {
                $payment->forceFill(['status' => PaymentStatus::Failed, 'failure_reason' => 'Cobrança encerrada pelo gateway sem pagamento.'])->save();
                $this->audit->record('payment.failed', $payment, ['invoice_id' => $invoice->id, 'buyer_company_id' => $payment->buyer_company_id]);

                return true;
            }

            // Vale o que foi pago, até o saldo em aberto; o excedente não quita outra parcela.
            $paid = $result->paidAmount ?? $payment->amount_cents;
            $open = Receivables::open($installment);
            $amount = Money::min($paid, $open);

            if (! $amount->isPositive() || $installment->status !== InstallmentStatus::Open) {
                $payment->forceFill(['status' => PaymentStatus::Cancelled, 'failure_reason' => 'Parcela já quitada quando o pagamento foi confirmado; devolva o valor ao comprador.'])->save();
                $this->audit->record('payment.unapplied', $payment, ['invoice_id' => $invoice->id, 'paid_cents' => $paid->cents, 'buyer_company_id' => $payment->buyer_company_id]);

                return true;
            }

            $payment->forceFill([
                'amount_cents' => $amount,
                'fee_cents' => Money::min($result->fee ?? Money::zero(), $amount),
                'status' => PaymentStatus::Confirmed,
                'confirmed_at' => $this->clock->now(),
            ])->save();

            $this->receivables->pay($installment, $payment);
            $this->outbox->record('payment.confirmed', 'payment', $payment->id, ['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount_cents' => $amount->cents]);
            $this->audit->record('payment.confirmed', $payment, ['invoice_id' => $invoice->id, 'amount_cents' => $amount->cents, 'buyer_company_id' => $payment->buyer_company_id]);

            return true;
        });
    }
}

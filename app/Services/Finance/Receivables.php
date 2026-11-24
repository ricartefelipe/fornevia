<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Finance\FinancialProgress;
use App\Domain\Shared\Money;
use App\Enums\Credit\CreditMovementType;
use App\Enums\Finance\FinanceEntryType;
use App\Enums\Finance\InstallmentStatus;
use App\Enums\Finance\InvoiceStatus;
use App\Models\Finance\Invoice;
use App\Models\Finance\Payment;
use App\Models\Finance\ReceivableInstallment;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use App\Services\Credit\CreditLedger;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Único caminho que altera o saldo de uma parcela (RN-100): cada mudança
 * grava o lançamento financeiro, atualiza a parcela e a fatura, move o
 * recebível no crédito (RN-081, RN-083) e recalcula a situação financeira
 * do pedido, na transação do chamador, que já bloqueou pedido, fatura e
 * parcela nessa ordem (ADR-006).
 */
final readonly class Receivables
{
    public function __construct(
        private CreditLedger $credit,
        private PendingCharges $charges,
        private Access $access,
        private Clock $clock,
    ) {}

    /**
     * Pagamento confirmado: quita a parcela com a parte aplicada (o recebido
     * menos o não aplicado, que fica reembolsável).
     */
    public function pay(ReceivableInstallment $installment, Payment $payment): void
    {
        $applied = $payment->amount_cents->minus($payment->unapplied_cents);

        if ($applied->isPositive()) {
            $this->change($installment, paid: $applied, exceptPaymentId: $payment->id);
            $this->entry($installment, $payment, FinanceEntryType::Payment, $applied->negated(), $applied, null);
            $this->credit->receivable($installment->buyer_company_id, CreditMovementType::PaymentSettlement, 'payment', $payment->id, $applied->negated());
        }

        if ($payment->fee_cents->isPositive()) {
            $this->entry($installment, $payment, FinanceEntryType::Fee, Money::zero(), $payment->fee_cents, 'Taxa do gateway');
        }
    }

    /** Reembolso efetivado: o valor devolvido volta a ser devido na parcela. */
    public function refund(ReceivableInstallment $installment, Payment $payment, Money $amount, string $reason): void
    {
        $this->change($installment, paid: $amount->negated(), exceptPaymentId: $payment->id);
        $this->entry($installment, $payment, FinanceEntryType::Refund, $amount, $amount, $reason);
        $this->credit->receivable($installment->buyer_company_id, CreditMovementType::ReceivableAdjustment, 'payment', $payment->id, $amount, $reason);
    }

    /** Crédito concedido ao comprador (por exemplo, devolução): reduz o devido. */
    public function credit(ReceivableInstallment $installment, Money $amount, string $reason): void
    {
        $this->change($installment, credited: $amount);
        $this->entry($installment, null, FinanceEntryType::CreditNote, $amount->negated(), $amount, $reason);
        $this->credit->receivable($installment->buyer_company_id, CreditMovementType::CreditNote, 'receivable_installment', $installment->id, $amount->negated(), $reason);
    }

    /** Ajuste compensatório do devido (juros, multa, correção), positivo ou negativo. */
    public function adjust(ReceivableInstallment $installment, Money $delta, string $reason, ?string $exceptPaymentId = null): void
    {
        $this->change($installment, adjusted: $delta, exceptPaymentId: $exceptPaymentId);
        $this->entry($installment, null, FinanceEntryType::Adjustment, $delta, Money::ofCents(abs($delta->cents)), $reason);
        $this->credit->receivable($installment->buyer_company_id, CreditMovementType::ReceivableAdjustment, 'receivable_installment', $installment->id, $delta, $reason);
    }

    /** Saldo em aberto da parcela. */
    public static function open(ReceivableInstallment $installment): Money
    {
        return $installment->amount_cents->plus($installment->adjusted_cents)->minus($installment->paid_cents)->minus($installment->credited_cents);
    }

    /**
     * Situação financeira do pedido: devido (itens não cancelados e frete),
     * faturado e quitado (pago ou creditado).
     */
    public function refreshOrder(Order $order): void
    {
        $items = OrderItem::query()->where('order_id', $order->id)->get();
        $due = $order->freight_cents;

        foreach ($items as $item) {
            $due = $due->plus($item->unit_net_price_cents->times($item->quantity->minus($item->quantity_cancelled)));
        }

        $invoices = Invoice::query()->where('order_id', $order->id)->get(['total_cents', 'paid_cents', 'credited_cents', 'adjusted_cents']);
        $invoiced = Money::sum($invoices->map(static fn (Invoice $i): Money => $i->total_cents->plus($i->adjusted_cents))->all());
        $settled = Money::sum($invoices->map(static fn (Invoice $i): Money => $i->paid_cents->plus($i->credited_cents))->all());
        $status = FinancialProgress::of($due, $invoiced, $settled);

        if ($order->financial_status !== $status) {
            $order->forceFill(['financial_status' => $status])->save();
        }
    }

    private function change(ReceivableInstallment $installment, ?Money $paid = null, ?Money $credited = null, ?Money $adjusted = null, ?string $exceptPaymentId = null): void
    {
        $paid ??= Money::zero();
        $credited ??= Money::zero();
        $adjusted ??= Money::zero();
        $newPaid = $installment->paid_cents->plus($paid);
        $newCredited = $installment->credited_cents->plus($credited);
        $newAdjusted = $installment->adjusted_cents->plus($adjusted);
        $settled = $newPaid->plus($newCredited)->equals($installment->amount_cents->plus($newAdjusted));

        $installment->forceFill([
            'paid_cents' => $newPaid,
            'credited_cents' => $newCredited,
            'adjusted_cents' => $newAdjusted,
            'status' => $settled ? InstallmentStatus::Paid : InstallmentStatus::Open,
        ])->save();

        // Cobrança pendente com o saldo antigo deixa de valer.
        $this->charges->cancelFor($installment, $exceptPaymentId, 'O saldo da parcela mudou; gere nova cobrança.');

        $invoice = Invoice::query()->whereKey($installment->invoice_id)->lockForUpdate()->firstOrFail();
        $invoicePaid = $invoice->paid_cents->plus($paid);
        $invoiceCredited = $invoice->credited_cents->plus($credited);
        $invoiceAdjusted = $invoice->adjusted_cents->plus($adjusted);
        $invoice->forceFill([
            'paid_cents' => $invoicePaid,
            'credited_cents' => $invoiceCredited,
            'adjusted_cents' => $invoiceAdjusted,
            'status' => $invoicePaid->plus($invoiceCredited)->equals($invoice->total_cents->plus($invoiceAdjusted)) ? InvoiceStatus::Paid : InvoiceStatus::Open,
        ])->save();

        $this->refreshOrder(Order::query()->findOrFail($invoice->order_id));
    }

    private function entry(ReceivableInstallment $installment, ?Payment $payment, FinanceEntryType $type, Money $delta, Money $amount, ?string $reason): void
    {
        DB::table('finance_entries')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $installment->tenant_id,
            'buyer_company_id' => $installment->buyer_company_id,
            'invoice_id' => $installment->invoice_id,
            'installment_id' => $installment->id,
            'payment_id' => $payment?->id,
            'type' => $type->value,
            'receivable_delta' => $delta->cents,
            'amount_cents' => $amount->cents,
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
            'actor_user_id' => $this->access->userId(),
            'created_at' => $this->clock->now(),
        ]);
    }
}

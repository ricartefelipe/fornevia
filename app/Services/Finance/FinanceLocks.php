<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Finance\Invoice;
use App\Models\Finance\Payment;
use App\Models\Finance\ReceivableInstallment;
use App\Models\Ordering\Order;
use App\Support\Http\UntrustedId;

/**
 * Locks do financeiro na ordem do ADR-006: pedido → fatura → parcela →
 * pagamento. Os ids são lidos sem lock só para descobrir o caminho; tudo é
 * relido sob lock.
 */
final class FinanceLocks
{
    /**
     * @return array{Order, Invoice, ReceivableInstallment}
     */
    public function installment(mixed $installmentId): array
    {
        $id = UntrustedId::uuid($installmentId, ReceivableInstallment::class);
        $invoiceId = ReceivableInstallment::query()->findOrFail($id)->invoice_id;
        $orderId = Invoice::query()->findOrFail($invoiceId)->order_id;
        $order = Order::query()->lockForUpdate()->findOrFail($orderId);
        $invoice = Invoice::query()->where('order_id', $order->id)->lockForUpdate()->whereKey($invoiceId)->firstOrFail();
        $installment = ReceivableInstallment::query()->where('invoice_id', $invoice->id)->lockForUpdate()->whereKey($id)->firstOrFail();

        return [$order, $invoice, $installment];
    }

    /**
     * @return array{Order, Invoice, ReceivableInstallment, Payment}
     */
    public function payment(mixed $paymentId): array
    {
        $id = UntrustedId::uuid($paymentId, Payment::class);
        [$order, $invoice, $installment] = $this->installment(Payment::query()->findOrFail($id)->installment_id);
        $payment = Payment::query()->where('installment_id', $installment->id)->lockForUpdate()->whereKey($id)->firstOrFail();

        return [$order, $invoice, $installment, $payment];
    }
}

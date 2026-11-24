<?php

declare(strict_types=1);

namespace App\Actions\Finance\Staff;

use App\Domain\Finance\Exceptions\InvoiceNotAllowed;
use App\Domain\Finance\InstallmentSchedule;
use App\Domain\Shared\Money;
use App\Enums\Finance\FinanceEntryType;
use App\Enums\Finance\InstallmentStatus;
use App\Enums\Finance\InvoiceStatus;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Finance\Invoice;
use App\Models\Finance\InvoiceItem;
use App\Models\Finance\ReceivableInstallment;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use App\Services\Audit\AuditRecorder;
use App\Services\Credit\CreditLedger;
use App\Services\Finance\FinanceNumbering;
use App\Services\Finance\Receivables;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Emite a fatura comercial (não fiscal) do que foi expedido e ainda não
 * faturado (RF-100), com o frete na primeira fatura do pedido, e as
 * parcelas pela condição de pagamento retratada no pedido. Na mesma
 * transação, o comprometimento de crédito do valor faturado vira recebível
 * (RN-081).
 *
 * Ordem de locks (ADR-006): chave de idempotência → pedido → itens →
 * conta de crédito → contador de numeração.
 */
final readonly class IssueInvoice
{
    public function __construct(
        private Access $access,
        private IdempotencyGuard $idempotency,
        private CreditLedger $credit,
        private Receivables $receivables,
        private FinanceNumbering $numbering,
        private Outbox $outbox,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(mixed $orderId, string $idempotencyKey): Invoice
    {
        $this->access->authorize(Permission::FinanceManage);
        $orderId = UntrustedId::uuid($orderId, Order::class);

        $result = $this->idempotency->run('invoice.issue', $idempotencyKey, ['order' => $orderId], function () use ($orderId): array {
            $invoice = $this->issue($orderId);

            return ['resource_type' => 'invoice', 'resource_id' => $invoice->id, 'response' => ['number' => $invoice->number]];
        });

        return Invoice::query()->findOrFail($result->resourceId);
    }

    private function issue(string $orderId): Invoice
    {
        $order = Order::query()->lockForUpdate()->findOrFail($orderId);

        if (! in_array($order->commercial_status, [CommercialStatus::Confirmed, CommercialStatus::PartiallyCancelled, CommercialStatus::Cancelled], true)) {
            throw new InvoiceNotAllowed('Só pedidos confirmados são faturados.');
        }

        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('line_number')->lockForUpdate()->get();
        $lines = [];
        $itemsTotal = Money::zero();

        foreach ($items as $item) {
            $quantity = $item->quantity_shipped->minus($item->quantity_invoiced);

            if ($quantity->isPositive()) {
                $amount = $item->unit_net_price_cents->times($quantity);
                $lines[] = [$item, $quantity, $amount];
                $itemsTotal = $itemsTotal->plus($amount);
            }
        }

        $freight = ! $order->freight_invoiced && $lines !== [] ? $order->freight_cents : Money::zero();
        $total = $itemsTotal->plus($freight);

        if ($lines === [] || ! $total->isPositive()) {
            throw new InvoiceNotAllowed('Não há quantidade expedida a faturar neste pedido.');
        }

        $today = $this->clock->now()->setTimezone((string) config('app.business_timezone'));
        $term = $order->payment_term;
        /** @var list<int> $days */
        $days = array_values(array_map('intval', (array) ($term['installment_days'] ?? [0])));

        $invoice = new Invoice;
        $invoice->forceFill([
            'buyer_company_id' => $order->buyer_company_id,
            'order_id' => $order->id,
            'number' => 'PEND-'.Str::upper(Str::random(15)),
            'status' => InvoiceStatus::Open,
            'items_cents' => $itemsTotal,
            'freight_cents' => $freight,
            'total_cents' => $total,
            'payment_term' => $term,
            'issue_date' => $today->toDateString(),
            'issued_by_user_id' => $this->access->userId(),
        ])->save();

        foreach ($lines as [$item, $quantity, $amount]) {
            (new InvoiceItem)->forceFill([
                'buyer_company_id' => $order->buyer_company_id,
                'invoice_id' => $invoice->id,
                'order_item_id' => $item->id,
                'quantity' => $quantity,
                'amount_cents' => $amount,
            ])->save();
            $item->forceFill(['quantity_invoiced' => $item->quantity_invoiced->plus($quantity)])->save();
        }

        foreach (InstallmentSchedule::split($total, $days, $today) as $part) {
            $installment = new ReceivableInstallment;
            $installment->forceFill([
                'buyer_company_id' => $order->buyer_company_id,
                'invoice_id' => $invoice->id,
                'sequence' => $part['sequence'],
                'due_date' => $part['due_date']->toDateString(),
                'amount_cents' => $part['amount'],
                'status' => InstallmentStatus::Open,
            ])->save();

            DB::table('finance_entries')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $order->tenant_id, 'buyer_company_id' => $order->buyer_company_id,
                'invoice_id' => $invoice->id, 'installment_id' => $installment->id, 'payment_id' => null,
                'type' => FinanceEntryType::InvoiceIssued->value, 'receivable_delta' => $part['amount']->cents, 'amount_cents' => $part['amount']->cents,
                'reason' => null, 'actor_user_id' => $this->access->userId(), 'created_at' => $this->clock->now(),
            ]);
        }

        // O comprometimento do valor faturado vira recebível (RN-081).
        $released = Money::min($order->credit_committed_cents, $total);
        $this->credit->invoice($order->buyer_company_id, $invoice->id, $released, $total);
        $order->forceFill([
            'credit_committed_cents' => $order->credit_committed_cents->minus($released),
            'freight_invoiced' => $order->freight_invoiced || $freight->isPositive(),
        ])->save();

        $invoice->forceFill(['number' => $this->numbering->next($order->tenant_id)])->save();
        $this->receivables->refreshOrder($order);
        $this->outbox->record('invoice.issued', 'invoice', $invoice->id, ['invoice_id' => $invoice->id, 'order_id' => $order->id, 'number' => $invoice->number, 'total_cents' => $total->cents]);
        $this->audit->record('invoice.issued', $invoice, ['order_id' => $order->id, 'total_cents' => $total->cents, 'buyer_company_id' => $order->buyer_company_id]);

        return $invoice;
    }
}

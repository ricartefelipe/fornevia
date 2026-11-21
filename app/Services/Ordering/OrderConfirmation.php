<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Data\Inventory\ReservationRequest;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Catalog\SkuCost;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use App\Services\Approvals\BudgetLedger;
use App\Services\Credit\CreditLedger;
use App\Services\Inventory\StockLedger;
use App\Services\Platform\PlanLimits;
use App\Support\Clock\Clock;
use App\Support\Outbox\Outbox;
use App\Support\Tenancy\CurrentContext;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Confirmação de um pedido já gravado (RN-060): custos internos, reservas
 * de estoque, compromisso de crédito, consumo do orçamento, cota do plano,
 * número definitivo, evento e outbox, na transação do chamador, que já
 * bloqueou o pedido. Usada no checkout sem aprovação e na confirmação após
 * a aprovação interna.
 *
 * Ordem de locks (ADR-006): saldos de estoque (depósito, SKU) → conta de
 * crédito → orçamento → contador de uso do plano → contador de numeração.
 * Estoque, crédito, custos, contadores e outbox são gravados no contexto de
 * sistema do mesmo usuário (ADR-014).
 */
final readonly class OrderConfirmation
{
    public function __construct(
        private CurrentContext $context,
        private TenantDatabase $db,
        private StockLedger $stock,
        private CreditLedger $credit,
        private BudgetLedger $budgets,
        private PlanLimits $limits,
        private OrderNumbering $numbering,
        private Outbox $outbox,
        private Clock $clock,
    ) {}

    public function confirm(Order $order): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A confirmação do pedido exige transação aberta.');
        }

        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('line_number')->get();
        $execution = $this->context->require();

        $this->db->run(ExecutionContext::system($execution->requireTenantId(), $execution->userId), function () use ($order, $items): void {
            $this->snapshotCosts($items);

            $backorder = DB::table('skus')
                ->join('products', 'products.id', '=', 'skus.product_id')
                ->whereIn('skus.id', $items->pluck('sku_id'))
                ->pluck('products.allow_backorder', 'skus.id');

            $outcomes = $this->stock->reserve(array_values($items->map(static fn (OrderItem $item): ReservationRequest => new ReservationRequest(
                $item->id,
                $item->sku_id,
                $item->sku_code,
                $item->base_quantity,
                (bool) ($backorder[$item->sku_id] ?? false),
            ))->all()), 'order', $order->id);

            foreach ($items as $item) {
                $outcome = $outcomes[$item->id];
                $item->forceFill([
                    'quantity_reserved' => $outcome->reserved,
                    'quantity_backordered' => $outcome->backordered,
                ])->save();
            }

            if ((bool) ($order->payment_term['requires_credit'] ?? false)) {
                $this->credit->commitForOrder($order->buyer_company_id, $order->id, $order->total_cents);
                $order->forceFill(['credit_committed_cents' => $order->total_cents]);
            }

            $this->budgets->consume($order);
            $this->limits->consumeOrderQuota();
            $this->numbering->assign($order);

            $order->forceFill([
                'commercial_status' => CommercialStatus::Confirmed,
                'confirmed_at' => $this->clock->now(),
                'confirmation_issue' => null,
            ])->save();

            $this->outbox->record('order.confirmed', 'order', $order->id, [
                'order_id' => $order->id,
                'number' => $order->number,
                'buyer_company_id' => $order->buyer_company_id,
                'total_cents' => $order->total_cents->cents,
                'payment_term' => $order->payment_term['code'] ?? null,
                'items' => array_values($items->map(static fn (OrderItem $item): array => [
                    'order_item_id' => $item->id,
                    'sku_id' => $item->sku_id,
                    'quantity' => $item->quantity->toDatabase(),
                    'unit_net_price_cents' => $item->unit_net_price_cents->cents,
                ])->all()),
            ]);
        });

        $this->event($order, 'order.confirmed', ['total_cents' => $order->total_cents->cents, 'items' => $items->count()]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function event(Order $order, string $type, array $data): void
    {
        DB::table('order_events')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'buyer_company_id' => $order->buyer_company_id,
            'type' => $type,
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'actor_user_id' => $this->context->require()->userId,
            'correlation_id' => Context::get('correlation_id'),
            'created_at' => $this->clock->now(),
        ]);
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     */
    private function snapshotCosts(Collection $items): void
    {
        $costs = SkuCost::query()
            ->whereIn('sku_id', $items->pluck('sku_id'))
            ->where('effective_from', '<=', $this->clock->now())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->unique('sku_id')
            ->keyBy('sku_id');

        foreach ($items as $item) {
            DB::table('order_item_costs')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->context->tenantId(),
                'order_item_id' => $item->id,
                'unit_cost_cents' => $costs->get($item->sku_id)?->cost_cents->cents,
                'created_at' => $this->clock->now(),
            ]);
        }
    }
}

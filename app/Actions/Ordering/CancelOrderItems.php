<?php

declare(strict_types=1);

namespace App\Actions\Ordering;

use App\Domain\Ordering\Exceptions\OrderNotCancellable;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use App\Services\Audit\AuditRecorder;
use App\Services\Credit\CreditLedger;
use App\Services\Inventory\StockLedger;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Outbox\Outbox;
use App\Support\Tenancy\CurrentContext;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cancelamento total ou parcial de itens ainda não expedidos (RF-062).
 *
 * Libera primeiro a parte encomendada (sem estoque) e depois as reservas;
 * libera o comprometimento de crédito proporcional ao valor cancelado e,
 * ao cancelar tudo, o saldo comprometido restante (sem resíduos de
 * arredondamento). Itens expedidos exigem devolução.
 */
final readonly class CancelOrderItems
{
    public function __construct(
        private Access $access,
        private CurrentContext $context,
        private TenantDatabase $db,
        private StockLedger $stock,
        private CreditLedger $credit,
        private Outbox $outbox,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, Quantity>|null  $quantities  order_item_id => quantidade (unidade de venda); null = tudo o que for cancelável
     */
    public function handle(Order $order, ?array $quantities, string $reason): Order
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw new OrderNotCancellable('Informe o motivo do cancelamento (ao menos 5 caracteres).', ['field' => 'reason']);
        }

        $this->authorize($order);

        return DB::transaction(function () use ($order, $quantities, $reason): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! in_array($locked->commercial_status, [CommercialStatus::Confirmed, CommercialStatus::PartiallyCancelled], true)) {
                throw new OrderNotCancellable('O pedido não está em situação que permita cancelamento.');
            }

            $items = OrderItem::query()->where('order_id', $locked->id)->orderBy('line_number')->lockForUpdate()->get()->keyBy('id');
            $requests = $this->requests($items, $quantities);
            $execution = $this->context->require();
            $cancelledValue = Money::zero();
            $changes = [];

            $this->db->run(ExecutionContext::system($execution->requireTenantId(), $execution->userId), function () use ($requests, $locked, $reason, &$cancelledValue, &$changes): void {
                foreach ($requests as ['item' => $item, 'quantity' => $quantity]) {
                    $base = $quantity->convertedBy($item->conversion_factor);
                    $fromBackorder = Quantity::min($base, $item->quantity_backordered);
                    $fromReservation = $base->minus($fromBackorder);

                    $released = $fromReservation->isPositive()
                        ? $this->stock->releaseForOrderItem($item->id, $fromReservation, $reason, 'order', $locked->id)
                        : Quantity::zero();

                    $item->forceFill([
                        'quantity_cancelled' => $item->quantity_cancelled->plus($quantity),
                        'quantity_backordered' => $item->quantity_backordered->minus($fromBackorder),
                        'quantity_reserved' => $item->quantity_reserved->minus($released),
                    ])->save();

                    $cancelledValue = $cancelledValue->plus($item->unit_net_price_cents->times($quantity));
                    $changes[] = ['order_item_id' => $item->id, 'quantity' => $quantity->toDatabase()];
                }
            });

            $remaining = OrderItem::query()->where('order_id', $locked->id)->get();
            $fullyCancelled = $remaining->every(static fn (OrderItem $i): bool => $i->quantity_cancelled->equals($i->quantity));

            if ($locked->credit_committed_cents->isPositive()) {
                $release = $fullyCancelled ? $locked->credit_committed_cents : Money::min($cancelledValue, $locked->credit_committed_cents);

                $this->db->run(ExecutionContext::system($execution->requireTenantId(), $execution->userId), function () use ($locked, $release, $reason): void {
                    $this->credit->releaseCommitment($locked->buyer_company_id, $locked->id, $release, $reason);
                });

                $locked->forceFill(['credit_committed_cents' => $locked->credit_committed_cents->minus($release)]);
            }

            $locked->forceFill([
                'commercial_status' => $fullyCancelled ? CommercialStatus::Cancelled : CommercialStatus::PartiallyCancelled,
                'cancelled_at' => $fullyCancelled ? $this->clock->now() : $locked->cancelled_at,
                'cancel_reason' => $reason,
            ])->save();

            DB::table('order_events')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $locked->tenant_id,
                'order_id' => $locked->id,
                'buyer_company_id' => $locked->buyer_company_id,
                'type' => $fullyCancelled ? 'order.cancelled' : 'order.items_cancelled',
                'data' => json_encode(['items' => $changes, 'value_cents' => $cancelledValue->cents, 'reason' => $reason], JSON_THROW_ON_ERROR),
                'actor_user_id' => $execution->userId,
                'correlation_id' => Context::get('correlation_id'),
                'created_at' => $this->clock->now(),
            ]);

            $this->db->run(ExecutionContext::system($execution->requireTenantId(), $execution->userId), fn (): string => $this->outbox->record(
                $fullyCancelled ? 'order.cancelled' : 'order.items_cancelled',
                'order',
                $locked->id,
                ['order_id' => $locked->id, 'number' => $locked->number, 'items' => $changes, 'value_cents' => $cancelledValue->cents],
            ));

            $this->audit->record('order.items_cancelled', $locked, ['items' => $changes, 'value_cents' => $cancelledValue->cents], $reason);

            return $locked;
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<string, OrderItem>  $items
     * @param  array<string, Quantity>|null  $quantities
     * @return list<array{item: OrderItem, quantity: Quantity}>
     */
    private function requests(\Illuminate\Support\Collection $items, ?array $quantities): array
    {
        $requests = [];

        if ($quantities === null) {
            foreach ($items as $item) {
                $open = $item->quantity->minus($item->quantity_cancelled)->minus($item->quantity_shipped);

                if ($open->isPositive()) {
                    $requests[] = ['item' => $item, 'quantity' => $open];
                }
            }
        } else {
            foreach ($quantities as $itemId => $quantity) {
                $item = $items->get(UntrustedId::uuid($itemId, OrderItem::class)) ?? throw new OrderNotCancellable('Item não pertence ao pedido.');
                $open = $item->quantity->minus($item->quantity_cancelled)->minus($item->quantity_shipped);

                if (! $quantity->isPositive() || $quantity->greaterThan($open)) {
                    throw new OrderNotCancellable("Quantidade a cancelar do item {$item->sku_code} excede o saldo não expedido.", [
                        'order_item_id' => $item->id, 'cancellable' => $open->toDatabase(),
                    ]);
                }

                $requests[] = ['item' => $item, 'quantity' => $quantity];
            }
        }

        if ($requests === []) {
            throw new OrderNotCancellable('Não há itens com saldo a cancelar.');
        }

        return $requests;
    }

    private function authorize(Order $order): void
    {
        if ($this->access->isStaff()) {
            $this->access->authorize(Permission::OrdersManage);

            return;
        }

        if ($this->access->isBuyer() && $this->access->can(Permission::PortalOrdersPlace) && $order->buyer_company_id === $this->access->buyerCompanyId()) {
            return;
        }

        throw new AuthorizationException('Sem permissão para cancelar este pedido.');
    }
}

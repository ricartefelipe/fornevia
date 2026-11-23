<?php

declare(strict_types=1);

namespace App\Actions\Fulfillment\Staff;

use App\Data\Inventory\ReservationRequest;
use App\Domain\Fulfillment\Exceptions\ShipmentNotAllowed;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\StockLedger;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Illuminate\Support\Facades\DB;

/**
 * Reserva agora o que o pedido confirmado tinha sob encomenda (RN-072),
 * na medida do disponível: o que não couber continua sob encomenda.
 *
 * Ordem de locks (ADR-006): pedido → itens → saldos → reservas.
 */
final readonly class ReserveBackorder
{
    public function __construct(
        private Access $access,
        private StockLedger $stock,
        private AuditRecorder $audit,
    ) {}

    /**
     * @return array<string, string> order_item_id => quantidade reservada agora (unidade base)
     */
    public function handle(mixed $orderId): array
    {
        $this->access->authorize(Permission::FulfillmentManage);
        $orderId = UntrustedId::uuid($orderId, Order::class);

        return DB::transaction(function () use ($orderId): array {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);

            if (! in_array($order->commercial_status, [CommercialStatus::Confirmed, CommercialStatus::PartiallyCancelled], true)) {
                throw new ShipmentNotAllowed('Só pedidos confirmados têm encomenda a reservar.');
            }

            $items = OrderItem::query()->where('order_id', $order->id)->where('quantity_backordered', '>', 0)->orderBy('id')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw new ShipmentNotAllowed('O pedido não tem itens sob encomenda.');
            }

            $outcomes = $this->stock->reserve(array_values($items->map(static fn (OrderItem $item): ReservationRequest => new ReservationRequest(
                holderId: $item->id,
                skuId: $item->sku_id,
                skuCode: $item->sku_code,
                baseQuantity: $item->quantity_backordered,
                allowBackorder: true,
            ))->all()), 'order', $order->id);
            $reserved = [];

            foreach ($items as $item) {
                $now = isset($outcomes[$item->id]) ? $outcomes[$item->id]->reserved : Quantity::zero();

                if (! $now->isPositive()) {
                    continue;
                }

                $item->forceFill([
                    'quantity_reserved' => $item->quantity_reserved->plus($now),
                    'quantity_backordered' => $item->quantity_backordered->minus($now),
                ])->save();
                $reserved[$item->id] = $now->toDatabase();
            }

            $this->audit->record('order.backorder_reserved', $order, ['items' => $reserved, 'buyer_company_id' => $order->buyer_company_id]);

            return $reserved;
        });
    }
}

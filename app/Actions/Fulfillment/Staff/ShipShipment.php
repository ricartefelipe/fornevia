<?php

declare(strict_types=1);

namespace App\Actions\Fulfillment\Staff;

use App\Domain\Fulfillment\Exceptions\InvalidShipment;
use App\Domain\Fulfillment\Exceptions\ShipmentNotAllowed;
use App\Enums\Fulfillment\ShipmentStatus;
use App\Enums\Identity\Permission;
use App\Models\Fulfillment\Shipment;
use App\Models\Fulfillment\ShipmentItem;
use App\Models\Ordering\OrderItem;
use App\Services\Audit\AuditRecorder;
use App\Services\Fulfillment\OrderFulfillment;
use App\Services\Fulfillment\ShipmentLocks;
use App\Services\Inventory\StockLedger;
use App\Services\Ordering\OrderConfirmation;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Expedição da remessa conferida (RN-073): na mesma transação, a
 * quantidade conferida consome as reservas do item no depósito (físico e
 * reservado baixam juntos), o pedido soma o expedido e a remessa fica
 * expedida com transportadora e rastreamento. A remessa expedida não volta
 * a ser expedida.
 *
 * Ordem de locks (ADR-006): pedido → remessa → itens → saldos → reservas.
 */
final readonly class ShipShipment
{
    public function __construct(
        private Access $access,
        private ShipmentLocks $locks,
        private StockLedger $stock,
        private OrderFulfillment $fulfillment,
        private OrderConfirmation $orders,
        private Outbox $outbox,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(mixed $shipmentId, string $carrier, ?string $trackingCode): Shipment
    {
        $this->access->authorize(Permission::FulfillmentManage);
        $carrier = trim($carrier);
        $trackingCode = $trackingCode !== null && trim($trackingCode) !== '' ? trim($trackingCode) : null;

        if (mb_strlen($carrier) < 2 || mb_strlen($carrier) > 80) {
            throw new InvalidShipment('Informe a transportadora (de 2 a 80 caracteres).', ['field' => 'carrier']);
        }

        if ($trackingCode !== null && mb_strlen($trackingCode) > 80) {
            throw new InvalidShipment('O código de rastreamento deve ter até 80 caracteres.', ['field' => 'tracking_code']);
        }

        return DB::transaction(function () use ($shipmentId, $carrier, $trackingCode): Shipment {
            [$order, $shipment] = $this->locks->lock($shipmentId);

            if ($shipment->status !== ShipmentStatus::Preparing) {
                throw new ShipmentNotAllowed('Esta remessa já foi expedida ou cancelada.');
            }

            if ($shipment->checked_at === null) {
                throw new ShipmentNotAllowed('Confira a remessa antes de expedir.');
            }

            $lines = ShipmentItem::query()->where('shipment_id', $shipment->id)->orderBy('order_item_id')->get();
            $items = OrderItem::query()->whereKey($lines->pluck('order_item_id')->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->stock->lockLevelsForOrderItems(array_values($items->keys()->map(static fn (mixed $id): string => (string) $id)->all()));
            $shipped = [];

            foreach ($lines as $line) {
                $item = $items->get($line->order_item_id);
                $checked = $line->quantity_checked;

                if ($item === null || $checked === null) {
                    continue;
                }

                $base = $checked->convertedBy($item->conversion_factor);

                if ($checked->isPositive()) {
                    $this->stock->consumeForOrderItem($item->id, $shipment->warehouse_id, $base, 'shipment', $shipment->id);
                    $shipped[] = ['order_item_id' => $item->id, 'quantity' => $checked->toDatabase()];
                }

                $item->forceFill([
                    'quantity_picking' => $item->quantity_picking->minus($line->quantity),
                    'quantity_shipped' => $item->quantity_shipped->plus($checked),
                    'quantity_reserved' => $item->quantity_reserved->minus($base),
                ])->save();
            }

            $shipment->forceFill([
                'status' => ShipmentStatus::Shipped,
                'carrier' => $carrier,
                'tracking_code' => $trackingCode,
                'shipped_at' => $this->clock->now(),
                'shipped_by_user_id' => $this->access->userId(),
            ])->save();

            $this->fulfillment->refresh($order);
            $this->orders->event($order, 'order.shipped', ['shipment_id' => $shipment->id, 'number' => $shipment->number, 'carrier' => $carrier, 'tracking_code' => $trackingCode]);
            $this->outbox->record('shipment.shipped', 'shipment', $shipment->id, [
                'shipment_id' => $shipment->id, 'order_id' => $order->id, 'number' => $shipment->number, 'items' => $shipped,
                'carrier' => $carrier, 'tracking_code' => $trackingCode,
            ]);
            $this->audit->record('shipment.shipped', $shipment, ['order_id' => $order->id, 'buyer_company_id' => $order->buyer_company_id]);

            return $shipment;
        });
    }
}

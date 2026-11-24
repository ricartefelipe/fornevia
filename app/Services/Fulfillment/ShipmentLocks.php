<?php

declare(strict_types=1);

namespace App\Services\Fulfillment;

use App\Models\Fulfillment\Shipment;
use App\Models\Ordering\Order;
use App\Support\Http\UntrustedId;

/**
 * Lock da remessa na ordem do ADR-006: pedido → remessa. O pedido é lido
 * sem lock só para descobrir o id; a remessa é relida sob lock.
 */
final class ShipmentLocks
{
    /**
     * @return array{Order, Shipment}
     */
    public function lock(mixed $shipmentId): array
    {
        $id = UntrustedId::uuid($shipmentId, Shipment::class);
        $orderId = (string) Shipment::query()->findOrFail($id)->order_id;
        $order = Order::query()->lockForUpdate()->findOrFail($orderId);
        $shipment = Shipment::query()->where('order_id', $order->id)->lockForUpdate()->whereKey($id)->firstOrFail();

        return [$order, $shipment];
    }
}

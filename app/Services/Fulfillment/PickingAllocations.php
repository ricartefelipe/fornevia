<?php

declare(strict_types=1);

namespace App\Services\Fulfillment;

use App\Domain\Shared\Quantity;
use App\Enums\Fulfillment\ShipmentStatus;
use App\Models\Fulfillment\ShipmentItem;
use App\Models\Ordering\OrderItem;

/**
 * Quanto da reserva de um item as remessas em separação de cada depósito
 * ainda vão consumir na expedição (unidade base). Essa parte da reserva
 * não pode ser liberada nem prometida a outra separação.
 */
final class PickingAllocations
{
    /**
     * @return array<string, Quantity> warehouse_id => quantidade base em separação
     */
    public function byWarehouse(OrderItem $item): array
    {
        $rows = ShipmentItem::query()
            ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
            ->where('shipment_items.order_item_id', $item->id)
            ->where('shipments.status', ShipmentStatus::Preparing->value)
            ->get(['shipment_items.quantity', 'shipments.warehouse_id']);
        $picking = [];

        foreach ($rows as $row) {
            $warehouse = (string) $row->getAttribute('warehouse_id');
            $picking[$warehouse] = ($picking[$warehouse] ?? Quantity::zero())->plus($row->quantity->convertedBy($item->conversion_factor));
        }

        return $picking;
    }
}

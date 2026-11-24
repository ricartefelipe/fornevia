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
use App\Services\Fulfillment\ShipmentLocks;
use App\Services\Ordering\OrderConfirmation;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Cancela a remessa ainda em separação: as quantidades voltam a faltar
 * separar e as reservas continuam com o pedido.
 */
final readonly class CancelShipment
{
    public function __construct(
        private Access $access,
        private ShipmentLocks $locks,
        private OrderConfirmation $orders,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(mixed $shipmentId, string $reason): Shipment
    {
        $this->access->authorize(Permission::FulfillmentManage);
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new InvalidShipment('Informe o motivo do cancelamento (de 5 a 500 caracteres).', ['field' => 'reason']);
        }

        return DB::transaction(function () use ($shipmentId, $reason): Shipment {
            [$order, $shipment] = $this->locks->lock($shipmentId);

            if ($shipment->status !== ShipmentStatus::Preparing) {
                throw new ShipmentNotAllowed('Só a remessa em separação pode ser cancelada.');
            }

            $lines = ShipmentItem::query()->where('shipment_id', $shipment->id)->get();
            $items = OrderItem::query()->whereKey($lines->pluck('order_item_id')->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($lines as $line) {
                $item = $items->get($line->order_item_id);
                $item?->forceFill(['quantity_picking' => $item->quantity_picking->minus($line->quantity)])->save();
            }

            $shipment->forceFill(['status' => ShipmentStatus::Cancelled, 'cancelled_at' => $this->clock->now(), 'cancel_reason' => $reason])->save();
            $this->orders->event($order, 'order.shipment_cancelled', ['shipment_id' => $shipment->id, 'number' => $shipment->number, 'reason' => $reason]);
            $this->audit->record('shipment.cancelled', $shipment, ['buyer_company_id' => $shipment->buyer_company_id], $reason);

            return $shipment;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Fulfillment\Staff;

use App\Domain\Fulfillment\Exceptions\InvalidShipment;
use App\Domain\Fulfillment\Exceptions\ShipmentNotAllowed;
use App\Domain\Shared\Quantity;
use App\Enums\Fulfillment\ShipmentStatus;
use App\Enums\Identity\Permission;
use App\Models\Fulfillment\Shipment;
use App\Models\Fulfillment\ShipmentItem;
use App\Services\Audit\AuditRecorder;
use App\Services\Fulfillment\ShipmentLocks;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Conferência da remessa em separação (RF-090): registra, por item, a
 * quantidade efetivamente embalada (até a separada). Só a quantidade
 * conferida é expedida; o restante volta a faltar separar na expedição.
 */
final readonly class CheckShipment
{
    public function __construct(
        private Access $access,
        private ShipmentLocks $locks,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, string>  $checked  shipment_item_id => quantidade conferida (pt-BR)
     */
    public function handle(mixed $shipmentId, array $checked): Shipment
    {
        $this->access->authorize(Permission::FulfillmentManage);

        return DB::transaction(function () use ($shipmentId, $checked): Shipment {
            [, $shipment] = $this->locks->lock($shipmentId);

            if ($shipment->status !== ShipmentStatus::Preparing) {
                throw new ShipmentNotAllowed('Só a remessa em separação é conferida.');
            }

            $items = ShipmentItem::query()->where('shipment_id', $shipment->id)->orderBy('id')->get();
            $total = Quantity::zero();

            foreach ($items as $item) {
                $value = trim((string) ($checked[$item->id] ?? ''));

                try {
                    $quantity = $value === '' ? $item->quantity : Quantity::parseLocalized($value);
                } catch (InvalidArgumentException) {
                    throw new InvalidShipment('Quantidade conferida inválida.', ['field' => "checked.{$item->id}"]);
                }

                if ($quantity->isNegative() || $quantity->greaterThan($item->quantity)) {
                    throw new InvalidShipment('A quantidade conferida vai de zero até a separada.', ['field' => "checked.{$item->id}"]);
                }

                $item->forceFill(['quantity_checked' => $quantity])->save();
                $total = $total->plus($quantity);
            }

            if (! $total->isPositive()) {
                throw new InvalidShipment('Nada foi conferido. Para desistir, cancele a remessa.', ['field' => 'checked']);
            }

            $shipment->forceFill(['checked_at' => $this->clock->now(), 'checked_by_user_id' => $this->access->userId()])->save();
            $this->audit->record('shipment.checked', $shipment, ['buyer_company_id' => $shipment->buyer_company_id]);

            return $shipment;
        });
    }
}

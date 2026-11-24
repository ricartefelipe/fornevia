<?php

declare(strict_types=1);

namespace Tests\Integration\Fulfillment;

use App\Actions\Fulfillment\Staff\CheckShipment;
use App\Actions\Fulfillment\Staff\CreateShipment;
use App\Actions\Fulfillment\Staff\RecordShipmentEvent;
use App\Actions\Fulfillment\Staff\ShipShipment;
use App\Enums\Fulfillment\ShipmentEventKind;
use App\Models\Fulfillment\Shipment;
use App\Models\Fulfillment\ShipmentEvent;
use App\Models\Identity\Membership;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\OrderingScenario;

/**
 * Fluxo de expedição pelas Actions, para os testes de integração.
 */
final class FulfillmentFlow
{
    public static function item(OrderingScenario $scenario, Order $order, int $line = 1): OrderItem
    {
        return $scenario->system(static fn (): OrderItem => OrderItem::query()->where('order_id', $order->id)->where('line_number', $line)->firstOrFail());
    }

    /**
     * @param  array<string, string>  $lines  order_item_id => quantidade
     */
    public static function create(OrderingScenario $scenario, Membership $staff, Order $order, array $lines, ?string $warehouseId = null): Shipment
    {
        return $scenario->tenant->actingAs($staff, static fn (): Shipment => app(CreateShipment::class)->handle($order->id, $warehouseId ?? $scenario->warehouse->id, $lines, (string) Str::uuid7()));
    }

    /**
     * @param  array<string, string>  $checked  shipment_item_id => quantidade conferida
     */
    public static function check(OrderingScenario $scenario, Membership $staff, Shipment $shipment, array $checked = []): Shipment
    {
        return $scenario->tenant->actingAs($staff, static fn (): Shipment => app(CheckShipment::class)->handle($shipment->id, $checked));
    }

    public static function ship(OrderingScenario $scenario, Membership $staff, Shipment $shipment, string $carrier = 'Transportadora Rápida', ?string $tracking = 'BR123'): Shipment
    {
        return $scenario->tenant->actingAs($staff, static fn (): Shipment => app(ShipShipment::class)->handle($shipment->id, $carrier, $tracking));
    }

    /**
     * Separa, confere tudo e expede a quantidade do primeiro item.
     */
    public static function shipAll(OrderingScenario $scenario, Membership $staff, Order $order, string $quantity): Shipment
    {
        $shipment = self::create($scenario, $staff, $order, [self::item($scenario, $order)->id => $quantity]);
        self::check($scenario, $staff, $shipment);

        return self::ship($scenario, $staff, $shipment);
    }

    public static function event(OrderingScenario $scenario, Membership $staff, Shipment $shipment, ShipmentEventKind $kind, ?string $externalId = null, ?CarbonImmutable $at = null, ?string $description = null): ShipmentEvent
    {
        return $scenario->tenant->actingAs($staff, static fn (): ShipmentEvent => app(RecordShipmentEvent::class)->handle($shipment->id, $kind, $at, $description, $externalId));
    }

    public static function fresh(OrderingScenario $scenario, Shipment $shipment): Shipment
    {
        return $scenario->system(static fn (): Shipment => Shipment::query()->with(['items', 'events'])->findOrFail($shipment->id));
    }

    /**
     * @return array{on_hand: string, reserved: string}
     */
    public static function level(OrderingScenario $scenario, ?string $skuId = null, ?string $warehouseId = null): array
    {
        $row = $scenario->system(static fn () => DB::table('stock_levels')
            ->where('sku_id', $skuId ?? $scenario->sku->id)->where('warehouse_id', $warehouseId ?? $scenario->warehouse->id)->first(['on_hand', 'reserved']));

        return ['on_hand' => (string) $row->on_hand, 'reserved' => (string) $row->reserved];
    }
}

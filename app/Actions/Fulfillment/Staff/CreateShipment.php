<?php

declare(strict_types=1);

namespace App\Actions\Fulfillment\Staff;

use App\Domain\Fulfillment\Exceptions\InvalidShipment;
use App\Domain\Fulfillment\Exceptions\ShipmentNotAllowed;
use App\Domain\Shared\Quantity;
use App\Enums\Fulfillment\ShipmentStatus;
use App\Enums\Identity\Permission;
use App\Enums\Inventory\ReservationStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Fulfillment\Shipment;
use App\Models\Fulfillment\ShipmentItem;
use App\Models\Inventory\StockReservation;
use App\Models\Inventory\Warehouse;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use App\Services\Audit\AuditRecorder;
use App\Services\Fulfillment\FulfillmentNumbering;
use App\Services\Fulfillment\PickingAllocations;
use App\Services\Ordering\OrderConfirmation;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use App\Support\Idempotency\IdempotencyGuard;
use InvalidArgumentException;

/**
 * Abre uma remessa em separação (RF-090): itens do pedido confirmado e
 * quantidades na unidade de venda, a partir de um depósito. Cada
 * quantidade cabe no que ainda não está cancelado, expedido ou em outra
 * separação, e na reserva ativa do item naquele depósito descontado o que
 * outras separações do mesmo depósito já vão consumir.
 *
 * Ordem de locks (ADR-006): chave de idempotência → pedido → itens →
 * reservas → contador de numeração.
 */
final readonly class CreateShipment
{
    public function __construct(
        private Access $access,
        private IdempotencyGuard $idempotency,
        private FulfillmentNumbering $numbering,
        private OrderConfirmation $orders,
        private PickingAllocations $picking,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, string>  $quantities  order_item_id => quantidade (pt-BR); vazio ou zero ignora o item
     */
    public function handle(mixed $orderId, mixed $warehouseId, array $quantities, string $idempotencyKey): Shipment
    {
        $this->access->authorize(Permission::FulfillmentManage);
        $orderId = UntrustedId::uuid($orderId, Order::class);
        $warehouseId = UntrustedId::uuid($warehouseId, Warehouse::class);
        $parsed = self::parse($quantities);
        $payload = ['order' => $orderId, 'warehouse' => $warehouseId, 'lines' => array_map(static fn (Quantity $q): string => $q->toDatabase(), $parsed)];

        $result = $this->idempotency->run('shipment.create', $idempotencyKey, $payload, function () use ($orderId, $warehouseId, $parsed): array {
            $shipment = $this->create($orderId, $warehouseId, $parsed);

            return ['resource_type' => 'shipment', 'resource_id' => $shipment->id, 'response' => ['number' => $shipment->number]];
        });

        return Shipment::query()->findOrFail($result->resourceId);
    }

    /**
     * @param  array<string, Quantity>  $lines
     */
    private function create(string $orderId, string $warehouseId, array $lines): Shipment
    {
        $order = Order::query()->lockForUpdate()->findOrFail($orderId);

        if (! in_array($order->commercial_status, [CommercialStatus::Confirmed, CommercialStatus::PartiallyCancelled], true)) {
            throw new ShipmentNotAllowed('Só pedidos confirmados recebem remessa.');
        }

        $warehouse = Warehouse::query()->where('is_active', true)->findOrFail($warehouseId);
        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('line_number')->lockForUpdate()->get()->keyBy('id');
        $selected = [];

        foreach ($lines as $itemId => $quantity) {
            $item = $items->get($itemId) ?? throw new InvalidShipment('Item não pertence ao pedido.', ['field' => 'quantities']);

            if ($quantity->greaterThan($item->unallocated())) {
                throw new InvalidShipment("A quantidade do item {$item->sku_code} passa do que ainda falta separar ({$item->unallocated()->format()} {$item->unit_code}).", ['field' => "quantities.{$item->id}"]);
            }

            $available = $this->reservedInWarehouse($item, $warehouse->id);

            if ($quantity->convertedBy($item->conversion_factor)->greaterThan($available)) {
                throw new InvalidShipment("O item {$item->sku_code} não tem reserva suficiente no depósito {$warehouse->name}. Reserve o saldo sob encomenda ou escolha outro depósito.", ['field' => "quantities.{$item->id}"]);
            }

            $selected[] = [$item, $quantity];
        }

        if ($selected === []) {
            throw new InvalidShipment('Informe a quantidade de ao menos um item.', ['field' => 'quantities']);
        }

        $shipment = new Shipment;
        $shipment->forceFill([
            'buyer_company_id' => $order->buyer_company_id,
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'number' => $this->numbering->next($order->tenant_id, 'shipment'),
            'status' => ShipmentStatus::Preparing,
            'created_by_user_id' => $this->access->userId(),
        ])->save();

        foreach ($selected as [$item, $quantity]) {
            (new ShipmentItem)->forceFill([
                'buyer_company_id' => $order->buyer_company_id,
                'shipment_id' => $shipment->id,
                'order_item_id' => $item->id,
                'quantity' => $quantity,
            ])->save();
            $item->forceFill(['quantity_picking' => $item->quantity_picking->plus($quantity)])->save();
        }

        $this->orders->event($order, 'order.shipment_created', ['shipment_id' => $shipment->id, 'number' => $shipment->number, 'warehouse' => $warehouse->name]);
        $this->audit->record('shipment.created', $shipment, ['order_id' => $order->id, 'buyer_company_id' => $order->buyer_company_id]);

        return $shipment;
    }

    /**
     * Reserva ativa do item no depósito (unidade base) menos o que as outras
     * separações desse depósito ainda vão consumir.
     */
    private function reservedInWarehouse(OrderItem $item, string $warehouseId): Quantity
    {
        $open = StockReservation::query()
            ->where('order_item_id', $item->id)
            ->where('warehouse_id', $warehouseId)
            ->where('status', ReservationStatus::Active->value)
            ->lockForUpdate()
            ->get()
            ->reduce(static fn (Quantity $sum, StockReservation $r): Quantity => $sum->plus($r->quantity->minus($r->quantity_consumed)->minus($r->quantity_released)), Quantity::zero());

        return $open->minus($this->picking->byWarehouse($item)[$warehouseId] ?? Quantity::zero());
    }

    /**
     * @param  array<string, string>  $quantities
     * @return array<string, Quantity>
     */
    private static function parse(array $quantities): array
    {
        $parsed = [];

        foreach ($quantities as $itemId => $value) {
            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            try {
                $quantity = Quantity::parseLocalized($value);
            } catch (InvalidArgumentException) {
                throw new InvalidShipment('Quantidade inválida.', ['field' => "quantities.{$itemId}"]);
            }

            if ($quantity->isNegative()) {
                throw new InvalidShipment('Quantidade inválida.', ['field' => "quantities.{$itemId}"]);
            }

            if ($quantity->isPositive()) {
                $parsed[UntrustedId::uuid((string) $itemId, OrderItem::class)] = $quantity;
            }
        }

        ksort($parsed);

        return $parsed;
    }
}

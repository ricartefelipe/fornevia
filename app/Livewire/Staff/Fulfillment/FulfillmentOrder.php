<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Fulfillment;

use App\Actions\Fulfillment\Staff\CreateShipment;
use App\Actions\Fulfillment\Staff\ReserveBackorder;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\Permission;
use App\Enums\Inventory\ReservationStatus;
use App\Models\Fulfillment\Shipment;
use App\Models\Inventory\StockReservation;
use App\Models\Inventory\Warehouse;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Separação de um pedido confirmado: o que falta separar por item, reserva
 * por depósito, abertura da remessa e reserva do saldo sob encomenda.
 */
#[Layout('layouts.app')]
#[Title('Separação do pedido')]
final class FulfillmentOrder extends Component
{
    #[Locked]
    public string $orderId = '';

    #[Locked]
    public string $createKey = '';

    public string $warehouseId = '';

    /** @var array<string, string> order_item_id => quantidade a separar */
    public array $quantities = [];

    public string $message = '';

    public string $failure = '';

    public function mount(string $order): void
    {
        $this->orderId = Order::query()->findOrFail(UntrustedId::uuid($order, Order::class))->id;
        $this->createKey = (string) Str::uuid7();
    }

    public function createShipment(CreateShipment $create): mixed
    {
        $this->resetErrorBag();
        $this->failure = '';

        try {
            $shipment = $create->handle($this->orderId, $this->warehouseId, $this->quantities, $this->createKey);
        } catch (BusinessRuleViolation $e) {
            $field = $e->details()['field'] ?? null;
            is_string($field) && str_starts_with($field, 'quantities.') ? $this->addError($field, $e->getMessage()) : $this->failure = $e->getMessage();

            return null;
        }

        return $this->redirectRoute('fulfillment.shipments.show', $shipment->id);
    }

    public function reserveBackorder(ReserveBackorder $reserve): void
    {
        $this->failure = '';

        try {
            $reserved = $reserve->handle($this->orderId);
        } catch (BusinessRuleViolation $e) {
            $this->failure = $e->getMessage();

            return;
        }

        $this->message = $reserved === [] ? 'Ainda não há estoque disponível para o que está sob encomenda.' : 'Saldo sob encomenda reservado na medida do disponível.';
    }

    public function render(Access $access): View
    {
        $order = Order::query()->with('buyerCompany')->findOrFail($this->orderId);
        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('line_number')->get();
        $warehouses = Warehouse::query()->where('is_active', true)->orderByDesc('priority')->orderBy('name')->get();
        $this->warehouseId = $this->warehouseId !== '' ? $this->warehouseId : (string) ($warehouses->first()->id ?? '');
        $reserved = StockReservation::query()
            ->whereIn('order_item_id', $items->pluck('id')->all())
            ->where('status', ReservationStatus::Active->value)
            ->get()
            ->groupBy(static fn (StockReservation $r): string => $r->order_item_id.'|'.$r->warehouse_id)
            ->map(static fn ($group) => $group->reduce(static fn (Quantity $sum, StockReservation $r): Quantity => $sum->plus($r->quantity->minus($r->quantity_consumed)->minus($r->quantity_released)), Quantity::zero()));

        return view('livewire.staff.fulfillment.fulfillment-order', [
            'order' => $order,
            'items' => $items,
            'warehouses' => $warehouses,
            'reserved' => $reserved,
            'shipments' => Shipment::query()->with('warehouse')->where('order_id', $order->id)->orderByDesc('created_at')->get(),
            'canManage' => $access->can(Permission::FulfillmentManage),
            'hasBackorder' => $items->contains(static fn (OrderItem $item): bool => $item->quantity_backordered->isPositive()),
        ])->title("Separação do pedido {$order->number}");
    }
}

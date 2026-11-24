<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Fulfillment;

use App\Enums\Fulfillment\ShipmentStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Fulfillment\Shipment;
use App\Models\Ordering\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Fila da expedição: pedidos confirmados com quantidade a separar,
 * remessas em separação, expedidas aguardando entrega e entregues.
 */
#[Layout('layouts.app')]
#[Title('Expedição')]
final class FulfillmentIndex extends Component
{
    use WithPagination;

    public const array TABS = [
        'separar' => 'A separar',
        'separacao' => 'Em separação',
        'expedidas' => 'Expedidas',
        'entregues' => 'Entregues',
    ];

    #[Url(as: 'fila', except: 'separar')]
    public string $tab = 'separar';

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $tab = array_key_exists($this->tab, self::TABS) ? $this->tab : 'separar';

        return view('livewire.staff.fulfillment.fulfillment-index', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'orders' => $tab === 'separar' ? $this->ordersToPick()->paginate(25) : null,
            'shipments' => $tab !== 'separar' ? $this->shipments($tab)->paginate(25) : null,
            'timezone' => (string) config('app.business_timezone'),
        ]);
    }

    /**
     * @return Builder<Order>
     */
    private function ordersToPick(): Builder
    {
        return Order::query()
            ->with('buyerCompany')
            ->whereIn('commercial_status', [CommercialStatus::Confirmed->value, CommercialStatus::PartiallyCancelled->value])
            ->whereExists(static fn ($items) => $items->from('order_items')
                ->whereColumn('order_items.order_id', 'orders.id')
                ->whereRaw('order_items.quantity - order_items.quantity_cancelled - order_items.quantity_shipped - order_items.quantity_picking > 0'))
            ->orderBy('confirmed_at')
            ->orderBy('id');
    }

    /**
     * @return Builder<Shipment>
     */
    private function shipments(string $tab): Builder
    {
        $status = match ($tab) {
            'separacao' => ShipmentStatus::Preparing,
            'expedidas' => ShipmentStatus::Shipped,
            default => ShipmentStatus::Delivered,
        };

        return Shipment::query()
            ->with(['order.buyerCompany', 'warehouse'])
            ->where('status', $status->value)
            ->orderBy($status === ShipmentStatus::Preparing ? 'created_at' : 'updated_at', $status === ShipmentStatus::Preparing ? 'asc' : 'desc')
            ->orderBy('id');
    }
}

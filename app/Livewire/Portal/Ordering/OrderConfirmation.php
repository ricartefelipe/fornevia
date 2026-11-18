<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Ordering;

use App\Actions\Ordering\Portal\BuyerOrders;
use App\Models\Ordering\Order;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Confirmação do pedido recém-enviado: número, total, entrega, pagamento e
 * situação da reserva de cada item. O pedido é recarregado com as regras
 * de visibilidade do comprador (BuyerOrders).
 */
#[Layout('layouts.app')]
#[Title('Pedido confirmado')]
final class OrderConfirmation extends Component
{
    #[Locked]
    public string $orderId = '';

    public function mount(string $order): void
    {
        $this->orderId = UntrustedId::uuid($order, Order::class);
    }

    public function render(BuyerOrders $orders): View
    {
        $order = $orders->find($orders->actor(), $this->orderId);

        return view('livewire.portal.ordering.order-confirmation', ['order' => $order])
            ->title("Pedido {$order->number} confirmado");
    }
}

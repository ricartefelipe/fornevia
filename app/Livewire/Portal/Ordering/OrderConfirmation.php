<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Ordering;

use App\Actions\Ordering\Portal\BuyerOrders;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Ordering\Order;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Retorno do pedido recém-enviado: número, total, entrega, pagamento e
 * situação de cada item. Pedido confirmado mostra a reserva de cada item;
 * pedido enviado para aprovação interna mostra os níveis e aprovadores e
 * lembra que estoque e crédito só são reservados na confirmação (RN-056).
 * O pedido é recarregado com as regras de visibilidade do comprador
 * (BuyerOrders).
 */
#[Layout('layouts.app')]
#[Title('Pedido confirmado')]
final class OrderConfirmation extends Component
{
    private const array CONFIRMED = [CommercialStatus::Confirmed, CommercialStatus::PartiallyCancelled, CommercialStatus::Completed];

    #[Locked]
    public string $orderId = '';

    public function mount(string $order): void
    {
        $this->orderId = UntrustedId::uuid($order, Order::class);
    }

    public function render(BuyerOrders $orders): View
    {
        $order = $orders->find($orders->actor(), $this->orderId);
        $confirmed = in_array($order->commercial_status, self::CONFIRMED, true);
        $pendingApproval = $order->commercial_status === CommercialStatus::PendingApproval;
        $title = match (true) {
            $pendingApproval => "Pedido {$order->number} enviado para aprovação",
            $confirmed => "Pedido {$order->number} confirmado",
            default => "Pedido {$order->number}",
        };

        return view('livewire.portal.ordering.order-confirmation', [
            'order' => $order,
            'title' => $title,
            'description' => match (true) {
                $pendingApproval => 'Recebemos o seu pedido. Ele segue para a aprovação interna da sua empresa antes de ser confirmado com o distribuidor.',
                $confirmed => 'Recebemos o seu pedido. Os itens disponíveis já estão reservados para você.',
                default => 'Situação atual: '.mb_strtolower($order->commercial_status->label()).'.',
            },
            'pendingApproval' => $pendingApproval,
            'confirmed' => $confirmed,
            'approvalLevels' => OrderShow::approvalLevels($order),
        ])->title($title);
    }
}

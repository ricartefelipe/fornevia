<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Ordering;

use App\Actions\Ordering\Portal\BuyerOrders;
use App\Data\Ordering\Portal\OrderFilters;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Ordering\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Pedidos do comprador: os próprios ou, com portal.orders.view_all, os de
 * toda a empresa. Filtros por situação comercial e período (entrada não
 * confiável, normalizada em OrderFilters) e paginação. A aprovação interna
 * aparece com o nível em análise; pedido aprovado ainda não confirmado com
 * o distribuidor é destacado.
 */
#[Layout('layouts.app')]
#[Title('Pedidos')]
final class OrdersIndex extends Component
{
    use WithPagination;

    #[Url(as: 'situacao', except: '')]
    public string $status = '';

    #[Url(as: 'de', except: '')]
    public string $from = '';

    #[Url(as: 'ate', except: '')]
    public string $until = '';

    #[Url(as: 'meus', except: false)]
    public bool $mine = false;

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'from', 'until', 'mine'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'from', 'until', 'mine');
        $this->resetPage();
    }

    public function paginationView(): string
    {
        return 'livewire.portal.ordering.pagination';
    }

    public function render(BuyerOrders $orders): View
    {
        $actor = $orders->actor();
        $seesAll = $orders->seesAll();
        $filters = OrderFilters::fromInput($this->status, $this->from, $this->until, $seesAll && $this->mine);
        $page = $orders->paginate($actor, $filters, $this->currentPage());

        // Página além da última (filtros mudaram ou URL editada): volta à última existente.
        if ($page->isEmpty() && $page->total() > 0) {
            $this->setPage($page->lastPage());
            $page = $orders->paginate($actor, $filters, $page->lastPage());
        }

        // Valores inválidos deixam de filtrar e saem da URL.
        if ($filters->status === null) {
            $this->status = '';
        }

        $this->from = $filters->from?->format('Y-m-d') ?? '';
        $this->until = $filters->until?->format('Y-m-d') ?? '';

        /** @var list<Order> $items */
        $items = array_values($page->items());
        (new EloquentCollection($items))->load('approvalSteps');

        return view('livewire.portal.ordering.orders-index', [
            'orders' => $page,
            'statuses' => CommercialStatus::cases(),
            'seesAll' => $seesAll,
            'filtered' => $filters->isFiltered(),
            'placedBy' => $seesAll ? $orders->userNames(array_map(static fn (Order $order): string => $order->placed_by_user_id, $items)) : [],
            'membershipId' => $actor->membership->id,
        ]);
    }

    private function currentPage(): int
    {
        $page = filter_var($this->getPage(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($page) ? $page : 1;
    }
}

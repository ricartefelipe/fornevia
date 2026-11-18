<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Portal;

use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Data\Ordering\Portal\BuyerActor;
use App\Data\Ordering\Portal\OrderFilters;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Identity\User;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pedidos visíveis ao comprador: todos os da empresa para quem tem
 * portal.orders.view_all; os próprios (feitos pela associação) para os
 * demais. A RLS restringe à empresa do contexto; o filtro é repetido na
 * consulta. Pedido fora desse alcance equivale a pedido inexistente.
 */
final readonly class BuyerOrders
{
    public const int PER_PAGE = 20;

    public function __construct(private PortalBuyer $buyer) {}

    public function actor(): BuyerActor
    {
        return $this->buyer->require();
    }

    public function seesAll(): bool
    {
        return $this->buyer->can(Permission::PortalOrdersViewAll);
    }

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(BuyerActor $actor, OrderFilters $filters, int $page = 1): LengthAwarePaginator
    {
        $query = $this->visible($actor)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($filters->status !== null) {
            $query->where('commercial_status', $filters->status->value);
        }

        if ($filters->from !== null) {
            $query->where('created_at', '>=', $filters->from->startOfDay()->utc());
        }

        if ($filters->until !== null) {
            $query->where('created_at', '<', $filters->until->startOfDay()->addDay()->utc());
        }

        if ($filters->onlyMine) {
            $query->where('placed_by_membership_id', $actor->membership->id);
        }

        return $query->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }

    /**
     * Pedido com itens (por linha) e eventos (em ordem cronológica).
     *
     * @throws ModelNotFoundException
     */
    public function find(BuyerActor $actor, string $orderId): Order
    {
        return $this->visible($actor)
            ->with([
                'items' => static fn (HasMany $items): HasMany => $items->orderBy('line_number'),
                'events' => static fn (HasMany $events): HasMany => $events->orderBy('created_at')->orderBy('id'),
            ])
            ->whereKey(UntrustedId::uuid($orderId, Order::class))
            ->firstOrFail();
    }

    /**
     * O comprador pode cancelar itens: permissão de confirmar pedidos,
     * situação comercial que admite cancelamento e saldo não expedido. A
     * Action de cancelamento confere tudo de novo sob lock.
     */
    public function canCancel(Order $order): bool
    {
        if (! $this->buyer->can(Permission::PortalOrdersPlace)
            || ! in_array($order->commercial_status, [CommercialStatus::Confirmed, CommercialStatus::PartiallyCancelled], true)) {
            return false;
        }

        return $order->items->contains(static fn (OrderItem $item): bool => self::cancellable($item)->isPositive());
    }

    public static function cancellable(OrderItem $item): \App\Domain\Shared\Quantity
    {
        return $item->quantity->minus($item->quantity_cancelled)->minus($item->quantity_shipped);
    }

    /**
     * Nomes dos usuários (quem fez o pedido, autores de eventos).
     *
     * @param  list<string|null>  $userIds
     * @return array<string, string>
     */
    public function userNames(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter($userIds, is_string(...))));

        return $ids === [] ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @return Builder<Order>
     */
    private function visible(BuyerActor $actor): Builder
    {
        $query = Order::query()->where('buyer_company_id', $actor->company->id);

        if (! $this->seesAll()) {
            $query->where('placed_by_membership_id', $actor->membership->id);
        }

        return $query;
    }
}

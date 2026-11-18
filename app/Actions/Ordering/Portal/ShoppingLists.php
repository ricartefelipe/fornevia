<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Portal;

use App\Actions\Ordering\Portal\Exceptions\InvalidShoppingList;
use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Data\Ordering\Portal\BuyerActor;
use App\Enums\Identity\Permission;
use App\Models\Identity\Membership;
use App\Models\Ordering\ShoppingList;
use App\Support\Http\UntrustedId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Listas de compra visíveis ao comprador: as próprias e as compartilhadas
 * por outros usuários da mesma empresa. Somente quem criou a lista a
 * altera; as compartilhadas podem ser lidas e adicionadas ao carrinho.
 */
final readonly class ShoppingLists
{
    public function __construct(private PortalBuyer $buyer) {}

    public function actor(): BuyerActor
    {
        return $this->buyer->require(Permission::PortalCart);
    }

    /**
     * @return Collection<int, ShoppingList>
     */
    public function index(BuyerActor $actor): Collection
    {
        return $this->visible($actor)
            ->withCount('items')
            ->orderByRaw('case when membership_id = ? then 0 else 1 end', [$actor->membership->id])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @throws ModelNotFoundException lista inexistente, de outra empresa ou pessoal de outro usuário
     */
    public function find(BuyerActor $actor, string $listId): ShoppingList
    {
        return $this->visible($actor)->whereKey(UntrustedId::uuid($listId, ShoppingList::class))->firstOrFail();
    }

    /**
     * Lista do próprio comprador, bloqueada para alteração.
     *
     * @throws ModelNotFoundException
     * @throws InvalidShoppingList lista compartilhada de outro usuário
     */
    public function ownedForUpdate(BuyerActor $actor, string $listId): ShoppingList
    {
        $list = $this->visible($actor)->whereKey(UntrustedId::uuid($listId, ShoppingList::class))->lockForUpdate()->firstOrFail();

        if (! $this->owns($actor, $list)) {
            throw InvalidShoppingList::field('list', 'Somente quem criou a lista pode alterá-la.');
        }

        return $list;
    }

    public function owns(BuyerActor $actor, ShoppingList $list): bool
    {
        return $list->membership_id === $actor->membership->id;
    }

    /**
     * Nomes de quem criou cada lista (usuários da mesma empresa).
     *
     * @param  iterable<ShoppingList>  $lists
     * @return array<string, string> membership_id => nome
     */
    public function ownerNames(iterable $lists): array
    {
        $ids = [];

        foreach ($lists as $list) {
            $ids[$list->membership_id] = true;
        }

        if ($ids === []) {
            return [];
        }

        return Membership::query()
            ->with('user')
            ->whereIn('id', array_keys($ids))
            ->get()
            ->mapWithKeys(static fn (Membership $membership): array => [$membership->id => $membership->user->name])
            ->all();
    }

    /**
     * @return Builder<ShoppingList>
     */
    private function visible(BuyerActor $actor): Builder
    {
        return ShoppingList::query()
            ->where('buyer_company_id', $actor->company->id)
            ->where(static fn (Builder $query): Builder => $query
                ->where('membership_id', $actor->membership->id)
                ->orWhere('is_shared', true));
    }
}

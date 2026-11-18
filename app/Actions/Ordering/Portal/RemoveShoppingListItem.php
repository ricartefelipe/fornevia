<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Portal;

use App\Actions\Ordering\Portal\Exceptions\InvalidShoppingList;
use App\Models\Ordering\ShoppingListItem;
use App\Support\Http\UntrustedId;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Remove um item da lista de compra do próprio comprador.
 */
final readonly class RemoveShoppingListItem
{
    public function __construct(private ShoppingLists $lists) {}

    /**
     * @throws InvalidShoppingList
     * @throws ModelNotFoundException
     */
    public function handle(string $listId, string $itemId): void
    {
        $actor = $this->lists->actor();
        $id = UntrustedId::uuid($itemId, ShoppingListItem::class);

        DB::transaction(function () use ($actor, $listId, $id): void {
            $list = $this->lists->ownedForUpdate($actor, $listId);

            ShoppingListItem::query()->where('shopping_list_id', $list->id)->whereKey($id)->firstOrFail()->delete();
            $list->touch();
        });
    }
}

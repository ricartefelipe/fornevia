<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Portal;

use App\Actions\Ordering\Portal\Exceptions\InvalidShoppingList;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Exclui uma lista de compra do próprio comprador (e seus itens).
 */
final readonly class DeleteShoppingList
{
    public function __construct(private ShoppingLists $lists) {}

    /**
     * @throws InvalidShoppingList lista compartilhada de outro usuário
     * @throws ModelNotFoundException
     */
    public function handle(string $listId): string
    {
        $actor = $this->lists->actor();

        return DB::transaction(function () use ($actor, $listId): string {
            $list = $this->lists->ownedForUpdate($actor, $listId);
            $name = $list->name;
            $list->delete();

            return $name;
        });
    }
}

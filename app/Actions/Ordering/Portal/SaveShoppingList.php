<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Portal;

use App\Domain\Ordering\Exceptions\InvalidShoppingList;
use App\Models\Ordering\ShoppingList;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Cria ou altera (nome e compartilhamento) uma lista de compra do
 * comprador. Listas compartilhadas ficam visíveis aos usuários da mesma
 * empresa com acesso a listas, que podem usá-las mas não alterá-las.
 */
final readonly class SaveShoppingList
{
    public const int MAX_NAME_LENGTH = 120;

    public const int MAX_LISTS_PER_USER = 100;

    public function __construct(private ShoppingLists $lists) {}

    /**
     * @throws InvalidShoppingList
     * @throws ModelNotFoundException
     */
    public function handle(?string $listId, string $name, bool $shared): ShoppingList
    {
        $actor = $this->lists->actor();
        $name = self::name($name);

        return DB::transaction(function () use ($actor, $listId, $name, $shared): ShoppingList {
            if ($listId !== null) {
                $list = $this->lists->ownedForUpdate($actor, $listId);
                $list->forceFill(['name' => $name, 'is_shared' => $shared])->save();

                return $list;
            }

            // Limite contra acúmulo; criações simultâneas do mesmo usuário podem excedê-lo por poucas listas.
            if (ShoppingList::query()->where('membership_id', $actor->membership->id)->count() >= self::MAX_LISTS_PER_USER) {
                throw InvalidShoppingList::field('name', 'Você atingiu o limite de '.self::MAX_LISTS_PER_USER.' listas de compra. Exclua listas que não usa mais.');
            }

            $list = new ShoppingList;
            $list->forceFill([
                'buyer_company_id' => $actor->company->id,
                'membership_id' => $actor->membership->id,
                'name' => $name,
                'is_shared' => $shared,
            ])->save();

            return $list;
        });
    }

    private static function name(string $value): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($name === '') {
            throw InvalidShoppingList::field('name', 'Informe o nome da lista.');
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw InvalidShoppingList::field('name', 'O nome da lista deve ter até '.self::MAX_NAME_LENGTH.' caracteres.');
        }

        return $name;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Portal;

use App\Actions\Ordering\Portal\Support\CartWriter;
use App\Actions\Ordering\Portal\Support\PurchasableSkus;
use App\Domain\Ordering\Exceptions\InvalidCartItem;
use App\Domain\Ordering\Exceptions\InvalidShoppingList;
use App\Models\Ordering\ShoppingListItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Inclui um SKU (pelo código) na lista de compra do próprio comprador, ou
 * altera a quantidade quando ele já está na lista. A quantidade digitada
 * (pt-BR) é conferida pela regra de compra do SKU; preço não é exigido
 * (é calculado quando a lista vai para o carrinho).
 */
final readonly class SaveShoppingListItem
{
    public const int MAX_ITEMS = 500;

    public function __construct(
        private ShoppingLists $lists,
        private PurchasableSkus $skus,
        private CartWriter $writer,
    ) {}

    /**
     * @throws InvalidCartItem código não encontrado (field = code) ou quantidade inválida (field = quantity)
     * @throws InvalidShoppingList
     * @throws ModelNotFoundException
     */
    public function handle(string $listId, string $skuCode, string $quantity): ShoppingListItem
    {
        $actor = $this->lists->actor();
        $code = mb_strtoupper(trim($skuCode));

        if ($code === '' || preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,39}$/D', $code) !== 1) {
            throw InvalidCartItem::because('Informe o código do SKU.', null, 'code');
        }

        return DB::transaction(function () use ($actor, $listId, $code, $quantity): ShoppingListItem {
            $list = $this->lists->ownedForUpdate($actor, $listId);
            $sku = $this->skus->byCodes($actor->company, [$code])[$code]
                ?? throw InvalidCartItem::because("Código {$code} não encontrado entre os produtos disponíveis para a sua empresa.", $code, 'code');

            $parsed = $this->writer->parseQuantity($sku, $quantity);
            $item = ShoppingListItem::query()->where('shopping_list_id', $list->id)->where('sku_id', $sku->id)->first();

            if ($item === null) {
                if (ShoppingListItem::query()->where('shopping_list_id', $list->id)->count() >= self::MAX_ITEMS) {
                    throw InvalidShoppingList::field('code', 'A lista atingiu o limite de '.self::MAX_ITEMS.' itens.');
                }

                $item = new ShoppingListItem;
                $item->forceFill(['shopping_list_id' => $list->id, 'buyer_company_id' => $list->buyer_company_id, 'sku_id' => $sku->id]);
            }

            $item->forceFill(['quantity' => $parsed])->save();
            $list->touch();

            return $item;
        });
    }
}

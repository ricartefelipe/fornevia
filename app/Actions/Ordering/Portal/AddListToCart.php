<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Portal;

use App\Actions\Ordering\Portal\Exceptions\InvalidCartItem;
use App\Actions\Ordering\Portal\Support\CartWriter;
use App\Actions\Ordering\Portal\Support\PurchasableSkus;
use App\Data\Ordering\Portal\CartAdditionReport;
use App\Models\Ordering\ShoppingListItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Adiciona ao carrinho todos os itens de uma lista de compra (própria ou
 * compartilhada da mesma empresa), com preço e disponibilidade vigentes.
 * Itens que deixaram de estar disponíveis são informados; quantidades fora
 * da regra de compra atual são ajustadas para a válida mais próxima.
 */
final readonly class AddListToCart
{
    public function __construct(
        private ShoppingLists $lists,
        private PurchasableSkus $skus,
        private CartWriter $writer,
    ) {}

    /**
     * @throws ModelNotFoundException lista não visível ao comprador
     * @throws InvalidCartItem total do carrinho acima do limite de cálculo
     */
    public function handle(string $listId): CartAdditionReport
    {
        $actor = $this->lists->actor();

        return DB::transaction(function () use ($actor, $listId): CartAdditionReport {
            $list = $this->lists->find($actor, $listId);

            /** @var list<ShoppingListItem> $items */
            $items = ShoppingListItem::query()->where('shopping_list_id', $list->id)->orderBy('created_at')->orderBy('id')->get()->all();

            if ($items === []) {
                throw InvalidCartItem::because('A lista não tem itens para adicionar ao carrinho.', null, 'list');
            }

            $cart = $this->writer->lockOpenCart($actor) ?? throw InvalidCartItem::because('Carrinho indisponível.', null, 'cart');
            $skus = $this->skus->byIds($actor->company, array_map(static fn (ShoppingListItem $item): string => $item->sku_id, $items));
            $lines = [];

            foreach ($items as $item) {
                $sku = $skus[$item->sku_id] ?? null;
                $lines[] = $this->writer->addFromSource($cart, $actor->company, $sku, $item->quantity, $sku->code ?? 'Item da lista');
            }

            return $this->writer->report($cart, $actor->company, $lines);
        });
    }
}

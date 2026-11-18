<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Portal;

use App\Actions\Ordering\Portal\Exceptions\InvalidCartItem;
use App\Actions\Ordering\Portal\Support\CartWriter;
use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Actions\Ordering\Portal\Support\PurchasableSkus;
use App\Enums\Identity\Permission;
use App\Models\Ordering\CartItem;
use App\Support\Http\UntrustedId;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Altera a quantidade de uma linha do carrinho aberto do comprador. A
 * quantidade digitada (pt-BR) é conferida pela regra de compra vigente do
 * SKU; item que deixou de estar disponível só pode ser removido.
 */
final readonly class UpdateCartItem
{
    public function __construct(
        private PortalBuyer $buyer,
        private PurchasableSkus $skus,
        private CartWriter $writer,
    ) {}

    /**
     * @throws InvalidCartItem
     * @throws ModelNotFoundException linha inexistente ou de outro carrinho
     */
    public function handle(string $cartItemId, string $quantity): CartItem
    {
        $actor = $this->buyer->require(Permission::PortalCart);
        $id = UntrustedId::uuid($cartItemId, CartItem::class);

        return DB::transaction(function () use ($actor, $id, $quantity): CartItem {
            $cart = $this->writer->lockOpenCart($actor, create: false) ?? throw (new ModelNotFoundException)->setModel(CartItem::class, [$id]);
            $item = CartItem::query()->where('cart_id', $cart->id)->whereKey($id)->firstOrFail();
            $sku = $this->skus->find($actor->company, $item->sku_id)
                ?? throw InvalidCartItem::because('Este item não está mais disponível para a sua empresa. Remova-o do carrinho.');

            $item = $this->writer->replace($cart, $actor->company, $item, $sku, $this->writer->parseQuantity($sku, $quantity));
            $this->writer->quote($cart, $actor->company);

            return $item;
        });
    }
}

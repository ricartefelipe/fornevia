<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Portal;

use App\Actions\Ordering\Portal\Support\CartWriter;
use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Enums\Identity\Permission;
use App\Models\Ordering\CartItem;
use App\Support\Http\UntrustedId;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Remove uma linha do carrinho aberto do comprador.
 */
final readonly class RemoveCartItem
{
    public function __construct(
        private PortalBuyer $buyer,
        private CartWriter $writer,
    ) {}

    /**
     * @throws ModelNotFoundException linha inexistente ou de outro carrinho
     */
    public function handle(string $cartItemId): void
    {
        $actor = $this->buyer->require(Permission::PortalCart);
        $id = UntrustedId::uuid($cartItemId, CartItem::class);

        DB::transaction(function () use ($actor, $id): void {
            $cart = $this->writer->lockOpenCart($actor, create: false) ?? throw (new ModelNotFoundException)->setModel(CartItem::class, [$id]);

            CartItem::query()->where('cart_id', $cart->id)->whereKey($id)->firstOrFail()->delete();
            $cart->touch();
        });
    }
}

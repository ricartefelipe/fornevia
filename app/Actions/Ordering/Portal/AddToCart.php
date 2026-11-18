<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Portal;

use App\Actions\Ordering\Portal\Exceptions\InvalidCartItem;
use App\Actions\Ordering\Portal\Support\CartWriter;
use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Actions\Ordering\Portal\Support\PurchasableSkus;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\Permission;
use App\Models\Catalog\Sku;
use App\Models\Ordering\CartItem;
use App\Support\Http\UntrustedId;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Adiciona um SKU ao carrinho aberto do comprador (criando o carrinho se
 * necessário), somando à quantidade já existente. Identificador de SKU
 * malformado, de outro tenant ou não visível à empresa equivale a SKU
 * inexistente.
 */
final readonly class AddToCart
{
    public function __construct(
        private PortalBuyer $buyer,
        private PurchasableSkus $skus,
        private CartWriter $writer,
    ) {}

    /**
     * @throws InvalidCartItem
     * @throws ModelNotFoundException
     */
    public function handle(string $skuId, Quantity $quantity): CartItem
    {
        $actor = $this->buyer->require(Permission::PortalCart);
        $id = UntrustedId::uuid($skuId, Sku::class);

        return DB::transaction(function () use ($actor, $id, $quantity): CartItem {
            $cart = $this->writer->lockOpenCart($actor) ?? throw new ModelNotFoundException;
            $sku = $this->skus->find($actor->company, $id) ?? throw (new ModelNotFoundException)->setModel(Sku::class, [$id]);

            $item = $this->writer->add($cart, $actor->company, $sku, $quantity);
            $this->writer->quote($cart, $actor->company);

            return $item;
        });
    }
}

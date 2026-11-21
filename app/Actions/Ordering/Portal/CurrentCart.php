<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Portal;

use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Data\Ordering\CartLineQuote;
use App\Data\Ordering\Portal\CartView;
use App\Domain\Shared\AmountOutOfRange;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\CartStatus;
use App\Models\Catalog\Sku;
use App\Models\Ordering\Cart;
use App\Models\Ordering\CartItem;
use App\Services\Ordering\CartPricing;

/**
 * Carrinho aberto do comprador, precificado no servidor a cada exibição.
 * Não cria carrinho: sem carrinho aberto, a visão é vazia.
 *
 * Quando o total excede o limite de cálculo (preços ou quantidades
 * extremos), as linhas são exibidas sem preço, com o pedido de redução da
 * quantidade, em vez de uma falha da tela.
 */
final readonly class CurrentCart
{
    public function __construct(
        private PortalBuyer $buyer,
        private CartPricing $pricing,
    ) {}

    public function handle(): CartView
    {
        $actor = $this->buyer->require(Permission::PortalCart);
        $company = $actor->company;

        $cart = Cart::query()
            ->where('membership_id', $actor->membership->id)
            ->where('buyer_company_id', $company->id)
            ->where('status', CartStatus::Open->value)
            ->first();

        if ($cart === null) {
            return new CartView(null, [], null, false, $company->status->canPurchase(), $company->status->label());
        }

        try {
            $quote = $this->pricing->quote($cart, $company);

            return new CartView($cart->id, $quote->lines, $quote->totals, false, $company->status->canPurchase(), $company->status->label());
        } catch (AmountOutOfRange) {
            return new CartView($cart->id, $this->unpriced($cart), null, true, $company->status->canPurchase(), $company->status->label());
        }
    }

    /**
     * @return list<CartLineQuote>
     */
    private function unpriced(Cart $cart): array
    {
        /** @var list<CartItem> $items */
        $items = $cart->items()->with(['sku.product', 'sku.salesUnit'])->orderBy('created_at')->get()->all();

        return array_map(static function (CartItem $item): CartLineQuote {
            // SKU oculto pela RLS (deixou de ser visível à empresa): relação nula.
            /** @var Sku|null $sku */
            $sku = $item->sku;

            return new CartLineQuote(
                $item->id, $item->sku_id, $sku->code ?? '—', $sku?->product->name ?? 'Item indisponível', $sku?->variant_label,
                $sku?->salesUnit->code ?? '', $sku?->package_label, $sku?->conversion_factor, $item->quantity, null, [], null, null,
                'unavailable', false, ['O total excede o limite de cálculo. Reduza a quantidade.'],
            );
        }, $items);
    }
}

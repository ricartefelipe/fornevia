<?php

declare(strict_types=1);

namespace Tests\Integration\Ordering\Portal;

use App\Actions\Ordering\PlaceOrder;
use App\Data\Ordering\PlaceOrderData;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\SystemRole;
use App\Enums\Pricing\PriceListScope;
use App\Models\Buyers\BuyerAddress;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Product;
use App\Models\Catalog\Sku;
use App\Models\Identity\Membership;
use App\Models\Ordering\Cart;
use App\Models\Ordering\CartItem;
use App\Models\Ordering\Order;
use App\Models\Pricing\BuyerPaymentTerm;
use App\Services\Inventory\StockLedger;
use App\Services\Pricing\PriceVersion;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\Catalog\Portal\PortalCatalogFixtures as Catalog;
use Tests\Support\Builders\OrderingScenario;

/**
 * Compradores, SKUs, carrinhos e pedidos para os testes do carrinho e dos
 * pedidos do portal, montados no contexto de sistema (como fariam a equipe
 * do distribuidor e as Actions de confirmação).
 */
final class PortalOrderingFixtures
{
    /**
     * Empresa (nova ou existente) com endereço de entrega e a condição de
     * pagamento do cenário permitida, e um usuário com o papel informado.
     * Sem carrinho.
     *
     * @return array{membership: Membership, company: BuyerCompany, address: BuyerAddress}
     */
    public static function buyer(OrderingScenario $scenario, ?BuyerCompany $company = null, SystemRole $role = SystemRole::Purchaser): array
    {
        $company ??= $scenario->tenant->addBuyerCompany();
        $membership = $scenario->tenant->addBuyerUser($company, $role);

        $address = $scenario->system(static function () use ($scenario, $company): BuyerAddress {
            $existing = BuyerAddress::query()->where('buyer_company_id', $company->id)->where('kind', 'shipping')->first();

            if ($existing !== null) {
                return $existing;
            }

            $address = new BuyerAddress;
            $address->forceFill([
                'buyer_company_id' => $company->id, 'kind' => 'shipping', 'label' => 'Depósito central', 'street' => 'Rua das Indústrias',
                'number' => '200', 'district' => 'Centro', 'city' => 'Sorocaba', 'state' => 'SP', 'postal_code' => '18010000',
                'is_default' => true, 'is_active' => true,
            ])->save();

            $allowed = new BuyerPaymentTerm;
            $allowed->forceFill(['buyer_company_id' => $company->id, 'payment_term_id' => $scenario->paymentTerm->id, 'is_default' => true])->save();

            return $address;
        });

        return ['membership' => $membership, 'company' => $company, 'address' => $address];
    }

    /**
     * Produto público com um SKU, preço na tabela padrão e estoque.
     *
     * @param  array{unit?: string, minimum?: string, multiple?: string, visibility?: \App\Enums\Catalog\ProductVisibility}  $options
     */
    public static function sku(OrderingScenario $scenario, string $code, ?int $priceCents = 1_000, string $stock = '100', array $options = [], string $name = 'Cabo flexível 2,5 mm'): Sku
    {
        $product = Catalog::product($scenario->tenant, $name.' '.$code, array_filter(['visibility' => $options['visibility'] ?? null]));
        $sku = Catalog::sku($scenario->tenant, $product, $code, array_filter([
            'unit' => $options['unit'] ?? null,
            'minimum' => $options['minimum'] ?? null,
            'multiple' => $options['multiple'] ?? null,
        ]));

        if ($priceCents !== null) {
            Catalog::priceList($scenario->tenant, PriceListScope::Default, [$sku->id => $priceCents]);
        }

        if ($stock !== '0') {
            $scenario->system(static fn () => app(StockLedger::class)->receive($scenario->warehouse->id, $sku->id, Quantity::of($stock), 'Saldo inicial'));
        }

        return $scenario->system(static fn (): Sku => Sku::query()->with(['product', 'salesUnit'])->findOrFail($sku->id));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function as(OrderingScenario $scenario, Membership $membership, Closure $callback): mixed
    {
        return $scenario->tenant->actingAs($membership, $callback);
    }

    /**
     * Itens do carrinho aberto da associação: código do SKU => quantidade.
     *
     * @return array<string, string>
     */
    public static function cartLines(OrderingScenario $scenario, Membership $membership): array
    {
        return $scenario->system(static function () use ($membership): array {
            $cart = Cart::query()->where('membership_id', $membership->id)->where('status', 'open')->first();

            if ($cart === null) {
                return [];
            }

            return CartItem::query()
                ->join('skus', 'skus.id', '=', 'cart_items.sku_id')
                ->where('cart_items.cart_id', $cart->id)
                ->orderBy('skus.code')
                ->pluck('cart_items.quantity', 'skus.code')
                ->map(static fn (Quantity|string $quantity): string => ($quantity instanceof Quantity ? $quantity : Quantity::of($quantity))->format())
                ->all();
        });
    }

    /**
     * Grava itens no carrinho aberto da associação (criando-o) diretamente,
     * sem as conferências das Actions.
     *
     * @param  array<string, string>  $items  sku_id => quantidade
     */
    public static function fillCart(OrderingScenario $scenario, Membership $membership, array $items): Cart
    {
        return $scenario->system(static function () use ($membership, $items): Cart {
            $cart = Cart::query()->where('membership_id', $membership->id)->where('status', 'open')->first();

            if ($cart === null) {
                $cart = new Cart;
                $cart->forceFill(['buyer_company_id' => $membership->buyer_company_id, 'membership_id' => $membership->id, 'status' => 'open'])->save();
            }

            foreach ($items as $skuId => $quantity) {
                (new CartItem)->forceFill(['cart_id' => $cart->id, 'buyer_company_id' => $cart->buyer_company_id, 'sku_id' => $skuId, 'quantity' => $quantity])->save();
            }

            return $cart;
        });
    }

    /**
     * Confirma um pedido com os itens informados pelo caminho real (PlaceOrder).
     *
     * @param  array{membership: Membership, company: BuyerCompany, address: BuyerAddress}  $buyer
     * @param  array<string, string>  $items  sku_id => quantidade
     */
    public static function order(OrderingScenario $scenario, array $buyer, array $items, ?string $purchaseOrder = null): Order
    {
        $cart = self::fillCart($scenario, $buyer['membership'], $items);

        return self::as($scenario, $buyer['membership'], static fn (): Order => app(PlaceOrder::class)->handle(new PlaceOrderData(
            cartId: $cart->id,
            idempotencyKey: 'teste-'.Str::lower(Str::random(16)),
            shippingAddressId: $buyer['address']->id,
            paymentTermId: $scenario->paymentTerm->id,
            purchaseOrderReference: $purchaseOrder,
        )));
    }

    public static function setPrice(OrderingScenario $scenario, Sku $sku, int $cents): void
    {
        $scenario->system(static function () use ($sku, $cents): void {
            DB::table('price_list_items')->where('sku_id', $sku->id)->update(['unit_price_cents' => $cents]);
            app(PriceVersion::class)->bump();
        });
    }

    public static function product(OrderingScenario $scenario, Sku $sku): Product
    {
        return $scenario->system(static fn (): Product => Product::query()->findOrFail($sku->product_id));
    }
}

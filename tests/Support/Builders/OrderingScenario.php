<?php

declare(strict_types=1);

namespace Tests\Support\Builders;

use App\Domain\Shared\Quantity;
use App\Enums\Identity\SystemRole;
use App\Models\Buyers\BuyerAddress;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Product;
use App\Models\Catalog\Sku;
use App\Models\Catalog\UnitOfMeasure;
use App\Models\Identity\Branch;
use App\Models\Identity\Membership;
use App\Models\Inventory\Warehouse;
use App\Models\Ordering\Cart;
use App\Models\Ordering\CartItem;
use App\Models\Pricing\BuyerPaymentTerm;
use App\Models\Pricing\PaymentTerm;
use App\Models\Pricing\PriceList;
use App\Models\Pricing\PriceListItem;
use App\Services\Inventory\StockLedger;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cenário de pedidos: distribuidor com depósito, um produto ativo com SKU
 * vendido por unidade, tabela padrão ativa, estoque inicial e, opcionalmente,
 * crédito comercial e condição a prazo. Compradores recebem endereço de
 * entrega, condição de pagamento permitida e carrinho com o SKU.
 */
final class OrderingScenario
{
    public ?BuyerCompany $sharedCompany = null;

    private function __construct(
        public readonly TenantScenario $tenant,
        public readonly Warehouse $warehouse,
        public readonly Sku $sku,
        public readonly PaymentTerm $paymentTerm,
        private readonly int $creditLimitCents,
    ) {}

    public static function create(string $stock = '10', int $unitPriceCents = 1_000, int $creditLimitCents = 0, bool $onCredit = false, bool $allowBackorder = false): self
    {
        $tenant = TenantScenario::create();

        [$warehouse, $sku, $term] = $tenant->system(function () use ($stock, $unitPriceCents, $onCredit, $allowBackorder): array {
            $warehouse = new Warehouse;
            $warehouse->forceFill([
                'branch_id' => Branch::query()->where('is_headquarters', true)->value('id'),
                'code' => 'CD1', 'name' => 'Centro de distribuição', 'priority' => 10, 'allows_sales' => true, 'is_active' => true,
            ])->save();

            $unit = UnitOfMeasure::query()->where('code', 'UN')->firstOrFail();
            $product = new Product;
            $product->forceFill([
                'name' => 'Disjuntor bipolar 32A', 'slug' => 'disjuntor-bipolar-32a-'.Str::lower(Str::random(5)), 'base_unit_id' => $unit->id,
                'status' => 'active', 'visibility' => 'public', 'allow_backorder' => $allowBackorder,
            ])->save();

            $sku = new Sku;
            $sku->forceFill([
                'product_id' => $product->id, 'sales_unit_id' => $unit->id, 'code' => 'DJ-32A-'.Str::upper(Str::random(4)),
                'conversion_factor' => '1', 'min_order_quantity' => '1', 'order_multiple' => '1', 'status' => 'active',
            ])->save();

            $list = new PriceList;
            $list->forceFill(['code' => 'PADRAO', 'name' => 'Tabela padrão', 'scope' => 'default', 'valid_from' => '2026-01-01', 'status' => 'active'])->save();
            $item = new PriceListItem;
            $item->forceFill(['price_list_id' => $list->id, 'sku_id' => $sku->id, 'unit_price_cents' => $unitPriceCents])->save();

            app(StockLedger::class)->receive($warehouse->id, $sku->id, Quantity::of($stock), 'Saldo inicial do cenário');

            $term = PaymentTerm::query()->where('code', $onCredit ? '28D' : 'AVISTA')->firstOrFail();

            return [$warehouse, $sku->fresh(['product', 'salesUnit']), $term];
        });

        return new self($tenant, $warehouse, $sku, $term, $creditLimitCents);
    }

    /**
     * @return array{membership: Membership, company: BuyerCompany, address: BuyerAddress, cart: Cart}
     */
    public function buyerWithCart(string $quantity, bool $sameCompany = false): array
    {
        $company = $sameCompany && $this->sharedCompany !== null ? $this->sharedCompany : $this->tenant->addBuyerCompany();

        if ($sameCompany) {
            $this->sharedCompany = $company;
        }

        $membership = $this->tenant->addBuyerUser($company, SystemRole::Purchaser);

        [$address, $cart] = $this->tenant->system(function () use ($company, $membership, $quantity): array {
            $address = BuyerAddress::query()->where('buyer_company_id', $company->id)->where('kind', 'shipping')->first();

            if ($address === null) {
                $address = new BuyerAddress;
                $address->forceFill([
                    'buyer_company_id' => $company->id, 'kind' => 'shipping', 'label' => 'Obra central', 'street' => 'Avenida das Indústrias',
                    'number' => '1500', 'district' => 'Distrito Industrial', 'city' => 'Campinas', 'state' => 'SP', 'postal_code' => '13050000',
                    'is_default' => true, 'is_active' => true,
                ])->save();

                $allowed = new BuyerPaymentTerm;
                $allowed->forceFill(['buyer_company_id' => $company->id, 'payment_term_id' => $this->paymentTerm->id, 'is_default' => true])->save();

                if ($this->creditLimitCents > 0) {
                    DB::table('credit_accounts')->insert([
                        'id' => (string) Str::uuid7(), 'tenant_id' => $company->tenant_id, 'buyer_company_id' => $company->id,
                        'limit_cents' => $this->creditLimitCents, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            $cart = new Cart;
            $cart->forceFill(['buyer_company_id' => $company->id, 'membership_id' => $membership->id, 'status' => 'open'])->save();
            $item = new CartItem;
            $item->forceFill(['cart_id' => $cart->id, 'buyer_company_id' => $company->id, 'sku_id' => $this->sku->id, 'quantity' => $quantity])->save();

            return [$address, $cart];
        });

        return ['membership' => $membership, 'company' => $company, 'address' => $address, 'cart' => $cart];
    }

    /**
     * @param  array{membership: Membership, company: BuyerCompany, address: BuyerAddress, cart: Cart}  $buyer
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function workerArgs(array $buyer, array $overrides = []): array
    {
        return [
            'tenant_id' => $this->tenant->organization->id,
            'user_id' => $buyer['membership']->user_id,
            'membership_id' => $buyer['membership']->id,
            'buyer_company_id' => $buyer['company']->id,
            'cart_id' => $buyer['cart']->id,
            'shipping_address_id' => $buyer['address']->id,
            'payment_term_id' => $this->paymentTerm->id,
            'idempotency_key' => 'checkout-'.Str::lower(Str::random(16)),
            ...$overrides,
        ];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function system(Closure $callback): mixed
    {
        return $this->tenant->system($callback);
    }
}

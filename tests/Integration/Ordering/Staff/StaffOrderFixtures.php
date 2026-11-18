<?php

declare(strict_types=1);

namespace Tests\Integration\Ordering\Staff;

use App\Actions\Ordering\PlaceOrder;
use App\Data\Ordering\PlaceOrderData;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Models\Buyers\BuyerAddress;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\BuyerUnit;
use App\Models\Buyers\CostCenter;
use App\Models\Catalog\Product;
use App\Models\Catalog\Sku;
use App\Models\Catalog\SkuCost;
use App\Models\Catalog\UnitOfMeasure;
use App\Models\Identity\Branch;
use App\Models\Identity\Membership;
use App\Models\Identity\Role;
use App\Models\Identity\User;
use App\Models\Inventory\Warehouse;
use App\Models\Ordering\Cart;
use App\Models\Ordering\CartItem;
use App\Models\Ordering\Order;
use App\Models\Pricing\PriceList;
use App\Models\Pricing\PriceListItem;
use App\Services\Inventory\StockLedger;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/**
 * Apoio aos testes de pedidos da equipe: pedidos confirmados pelo caminho
 * real (PlaceOrder no contexto do comprador), carteira de representantes,
 * custos, depósitos e SKUs adicionais.
 */
final class StaffOrderFixtures
{
    /**
     * Confirma um pedido de um novo comprador (ou da empresa compartilhada do cenário).
     *
     * O callback $prepare recebe o comprador antes da confirmação e pode
     * devolver argumentos adicionais de PlaceOrderData.
     *
     * @param  array<string, mixed>  $overrides  argumentos de PlaceOrderData
     * @param  Closure(array{membership: Membership, company: BuyerCompany, address: BuyerAddress, cart: Cart}): (array<string, mixed>|null)|null  $prepare
     * @return array{order: Order, company: BuyerCompany, membership: Membership, cart: Cart}
     */
    public static function place(OrderingScenario $scenario, string $quantity = '1', array $overrides = [], bool $sameCompany = false, ?Closure $prepare = null): array
    {
        $buyer = $scenario->buyerWithCart(quantity: $quantity, sameCompany: $sameCompany);

        if ($prepare !== null) {
            $overrides = [...$overrides, ...($prepare($buyer) ?? [])];
        }

        $args = $scenario->workerArgs($buyer);
        $data = new PlaceOrderData(...[
            'cartId' => $args['cart_id'],
            'idempotencyKey' => $args['idempotency_key'],
            'shippingAddressId' => $args['shipping_address_id'],
            'paymentTermId' => $args['payment_term_id'],
            ...$overrides,
        ]);

        $order = $scenario->tenant->actingAs($buyer['membership'], static fn (): Order => app(PlaceOrder::class)->handle($data));

        return ['order' => $order, 'company' => $buyer['company'], 'membership' => $buyer['membership'], 'cart' => $buyer['cart']];
    }

    public static function rename(TenantScenario $tenant, BuyerCompany $company, string $tradeName): BuyerCompany
    {
        return $tenant->system(static function () use ($company, $tradeName): BuyerCompany {
            $stored = BuyerCompany::query()->findOrFail($company->id);
            $stored->forceFill(['trade_name' => $tradeName])->save();

            return $stored;
        });
    }

    public static function assignRep(TenantScenario $tenant, BuyerCompany $company, ?Membership $rep): void
    {
        $tenant->system(static fn () => BuyerCompany::query()->findOrFail($company->id)->forceFill(['sales_rep_membership_id' => $rep?->id])->save());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function update(TenantScenario $tenant, Order $order, array $attributes): Order
    {
        return $tenant->system(static function () use ($order, $attributes): Order {
            $stored = Order::query()->findOrFail($order->id);
            $stored->forceFill($attributes)->save();

            return $stored;
        });
    }

    public static function cost(OrderingScenario $scenario, int $cents, ?Sku $sku = null): void
    {
        $scenario->system(static function () use ($scenario, $cents, $sku): void {
            $cost = new SkuCost;
            $cost->timestamps = false;
            $cost->forceFill([
                'sku_id' => ($sku ?? $scenario->sku)->id,
                'cost_cents' => Money::ofCents($cents),
                'effective_from' => now()->subMinute(),
            ])->save();
        });
    }

    public static function warehouse(OrderingScenario $scenario, string $code, string $name, int $priority, string $stock, ?Sku $sku = null): Warehouse
    {
        return $scenario->system(static function () use ($scenario, $code, $name, $priority, $stock, $sku): Warehouse {
            $warehouse = new Warehouse;
            $warehouse->forceFill([
                'branch_id' => Branch::query()->where('is_headquarters', true)->value('id'),
                'code' => $code, 'name' => $name, 'priority' => $priority, 'allows_sales' => true, 'is_active' => true,
            ])->save();

            app(StockLedger::class)->receive($warehouse->id, ($sku ?? $scenario->sku)->id, Quantity::of($stock), 'Saldo inicial do depósito');

            return $warehouse;
        });
    }

    /**
     * SKU adicional na tabela padrão do cenário, com estoque no depósito principal.
     */
    public static function sku(OrderingScenario $scenario, string $code, string $name, int $unitPriceCents, string $stock): Sku
    {
        return $scenario->system(static function () use ($scenario, $code, $name, $unitPriceCents, $stock): Sku {
            $unit = UnitOfMeasure::query()->where('code', 'UN')->firstOrFail();
            $product = new Product;
            $product->forceFill([
                'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)), 'base_unit_id' => $unit->id,
                'status' => 'active', 'visibility' => 'public', 'allow_backorder' => false,
            ])->save();

            $sku = new Sku;
            $sku->forceFill([
                'product_id' => $product->id, 'sales_unit_id' => $unit->id, 'code' => $code,
                'conversion_factor' => '1', 'min_order_quantity' => '1', 'order_multiple' => '1', 'status' => 'active',
            ])->save();

            $item = new PriceListItem;
            $item->forceFill([
                'price_list_id' => PriceList::query()->where('code', 'PADRAO')->value('id'),
                'sku_id' => $sku->id,
                'unit_price_cents' => $unitPriceCents,
            ])->save();

            app(StockLedger::class)->receive($scenario->warehouse->id, $sku->id, Quantity::of($stock), 'Saldo inicial do cenário');

            return $sku;
        });
    }

    public static function addToCart(OrderingScenario $scenario, Cart $cart, Sku $sku, string $quantity): void
    {
        $scenario->system(static function () use ($cart, $sku, $quantity): void {
            $item = new CartItem;
            $item->forceFill(['cart_id' => $cart->id, 'buyer_company_id' => $cart->buyer_company_id, 'sku_id' => $sku->id, 'quantity' => $quantity])->save();
        });
    }

    /**
     * @return array{0: BuyerUnit, 1: CostCenter}
     */
    public static function unitAndCostCenter(TenantScenario $tenant, BuyerCompany $company): array
    {
        return $tenant->system(static function () use ($company): array {
            $unit = new BuyerUnit;
            $unit->forceFill(['buyer_company_id' => $company->id, 'code' => 'OBRA-07', 'name' => 'Obra Jardim Paulista', 'is_active' => true])->save();

            $center = new CostCenter;
            $center->forceFill([
                'buyer_company_id' => $company->id, 'buyer_unit_id' => $unit->id, 'code' => 'CC-ELETRICA', 'name' => 'Instalações elétricas', 'is_active' => true,
            ])->save();

            return [$unit, $center];
        });
    }

    /**
     * Ajusta as permissões de um papel padrão do tenant.
     *
     * @param  list<Permission>  $grant
     * @param  list<Permission>  $revoke
     */
    public static function adjustRole(TenantScenario $tenant, SystemRole $role, array $grant = [], array $revoke = []): void
    {
        $tenant->system(static function () use ($role, $grant, $revoke): void {
            $stored = Role::query()->where('key', $role->value)->firstOrFail();
            $permissions = array_diff($stored->permissions, array_map(static fn (Permission $p): string => $p->value, $revoke));
            $permissions = array_values(array_unique([...$permissions, ...array_map(static fn (Permission $p): string => $p->value, $grant)]));
            $stored->forceFill(['permissions' => $permissions])->save();
        });
    }

    /**
     * Usuário da equipe com segundo fator confirmado, pronto para requisições HTTP.
     *
     * @return array{0: User, 1: Membership}
     */
    public static function staffUser(TenantScenario $tenant, SystemRole $role): array
    {
        $user = User::factory()->withTwoFactor()->create()->fresh();

        return [$user, $tenant->addStaff($role, $user)];
    }

    /**
     * Executa o callback como a associação, com o usuário autenticado no Livewire.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function asMember(TenantScenario $tenant, Membership $membership, Closure $callback): mixed
    {
        return $tenant->actingAs($membership, static function () use ($membership, $callback): mixed {
            Livewire::actingAs(User::query()->findOrFail($membership->user_id));

            return $callback();
        });
    }

    /**
     * Estado persistido do pedido, de seus itens e dos ledgers.
     *
     * @return array{status: string, credit: int, items: array<string, array{cancelled: string, reserved: string, backordered: string}>, stock_reserved: string, account_committed: int, events: list<string>}
     */
    public static function state(TenantScenario $tenant, Order $order): array
    {
        return $tenant->system(static function () use ($order): array {
            $stored = Order::query()->with('items')->findOrFail($order->id);
            $items = [];

            foreach ($stored->items->sortBy('line_number') as $item) {
                $items[$item->sku_code] = [
                    'cancelled' => $item->quantity_cancelled->toDatabase(),
                    'reserved' => $item->quantity_reserved->toDatabase(),
                    'backordered' => $item->quantity_backordered->toDatabase(),
                ];
            }

            return [
                'status' => $stored->commercial_status->value,
                'credit' => $stored->credit_committed_cents->cents,
                'items' => $items,
                'stock_reserved' => (string) DB::table('stock_levels')->sum('reserved'),
                'account_committed' => (int) DB::table('credit_accounts')->sum('committed_cents'),
                'events' => DB::table('order_events')->where('order_id', $order->id)->orderBy('created_at')->orderBy('id')->pluck('type')->all(),
            ];
        });
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory;

use App\Actions\Ordering\PlaceOrder;
use App\Data\Ordering\PlaceOrderData;
use App\Domain\Shared\Quantity;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\SkuStatus;
use App\Models\Catalog\Sku;
use App\Models\Identity\Branch;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Models\Inventory\Warehouse;
use App\Models\Ordering\Order;
use App\Services\Inventory\StockLedger;
use Closure;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Integration\Catalog\Staff\CatalogFixtures;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/**
 * Apoio aos testes de estoque: depósitos e SKUs gravados no contexto de
 * sistema do tenant (sem passar pelas Actions em teste), saldos iniciais
 * pelo ledger, reservas por pedidos confirmados, leituras de saldos e
 * conciliação com os movimentos.
 */
final class InventoryFixtures
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function warehouse(TenantScenario $tenant, string $code = 'CD1', array $attributes = []): Warehouse
    {
        return $tenant->system(static function () use ($code, $attributes): Warehouse {
            $warehouse = new Warehouse;
            $warehouse->forceFill([
                'branch_id' => Branch::query()->where('is_headquarters', true)->value('id'),
                'code' => $code,
                'name' => "Depósito {$code}",
                'priority' => 10,
                'allows_sales' => true,
                'is_active' => true,
                ...$attributes,
            ])->save();

            return $warehouse;
        });
    }

    /**
     * SKU ativo de um produto ativo cuja unidade base é a informada
     * (UN sem casas decimais; KG com 3).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function sku(TenantScenario $tenant, string $code = 'PAR-M8', string $unitCode = 'UN', string $productName = 'Parafuso sextavado M8', array $attributes = []): Sku
    {
        $unit = CatalogFixtures::unit($tenant, $unitCode);
        $product = CatalogFixtures::product($tenant, $unit, ['name' => $productName, 'status' => ProductStatus::Active]);

        return CatalogFixtures::sku($tenant, $product, $unit, $code, ['status' => SkuStatus::Active, ...$attributes]);
    }

    public static function receive(TenantScenario $tenant, Warehouse $warehouse, Sku $sku, string $quantity): void
    {
        $tenant->system(static fn () => app(StockLedger::class)->receive($warehouse->id, $sku->id, Quantity::of($quantity), 'Saldo inicial'));
    }

    /**
     * Confirma um pedido do SKU do cenário pelo checkout real (PlaceOrder),
     * que reserva o estoque como em produção (depósitos ativos que atendem
     * vendas, por prioridade).
     *
     * @return array{order_id: string, number: string, item_id: string}
     */
    public static function confirmOrder(OrderingScenario $scenario, string $quantity): array
    {
        $buyer = $scenario->buyerWithCart($quantity);
        $args = $scenario->workerArgs($buyer);

        $order = $scenario->tenant->actingAs($buyer['membership'], static fn (): Order => app(PlaceOrder::class)->handle(new PlaceOrderData(
            cartId: (string) $args['cart_id'],
            idempotencyKey: (string) $args['idempotency_key'],
            shippingAddressId: (string) $args['shipping_address_id'],
            paymentTermId: (string) $args['payment_term_id'],
        )));

        $itemId = $scenario->system(static fn (): string => (string) DB::table('order_items')->where('order_id', $order->id)->value('id'));

        return ['order_id' => $order->id, 'number' => $order->number, 'item_id' => $itemId];
    }

    /**
     * @return array{on_hand: string, reserved: string}|null
     */
    public static function level(TenantScenario $tenant, Warehouse $warehouse, Sku $sku): ?array
    {
        return $tenant->system(static function () use ($warehouse, $sku): ?array {
            $row = DB::table('stock_levels')->where('warehouse_id', $warehouse->id)->where('sku_id', $sku->id)->first(['on_hand', 'reserved']);

            return $row === null ? null : ['on_hand' => (string) $row->on_hand, 'reserved' => (string) $row->reserved];
        });
    }

    public static function movementCount(TenantScenario $tenant, ?string $type = null): int
    {
        return $tenant->system(static fn (): int => DB::table('stock_movements')->when($type !== null, static fn ($q) => $q->where('type', $type))->count());
    }

    /**
     * Saldos da projeção que divergem da soma dos movimentos (RN-070):
     * deve ser sempre vazio.
     *
     * @return list<array<string, mixed>>
     */
    public static function divergences(TenantScenario $tenant): array
    {
        return $tenant->system(static fn (): array => array_values(array_map(static fn (object $row): array => (array) $row, DB::select(<<<'SQL'
            select coalesce(l.warehouse_id, m.warehouse_id) as warehouse_id,
                   coalesce(l.sku_id, m.sku_id) as sku_id,
                   l.on_hand, m.on_hand_sum, l.reserved, m.reserved_sum
              from stock_levels l
              full join (
                    select tenant_id, warehouse_id, sku_id, sum(on_hand_delta) as on_hand_sum, sum(reserved_delta) as reserved_sum
                      from stock_movements
                     group by tenant_id, warehouse_id, sku_id
                   ) m on m.tenant_id = l.tenant_id and m.warehouse_id = l.warehouse_id and m.sku_id = l.sku_id
             where l.on_hand is distinct from m.on_hand_sum or l.reserved is distinct from m.reserved_sum
            SQL))));
    }

    /**
     * Executa como a associação informada, com o usuário autenticado no
     * Livewire (telas testadas com Livewire::test).
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
}

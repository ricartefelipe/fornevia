<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Staff;

use App\Domain\Pricing\PriceSource;
use App\Domain\Shared\Money;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerUnit;
use App\Models\Buyers\CostCenter;
use App\Models\Catalog\Sku;
use App\Models\Catalog\UnitOfMeasure;
use App\Models\Credit\CreditMovement;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Models\Inventory\StockReservation;
use App\Models\Inventory\Warehouse;
use App\Models\Ordering\OrderEvent;
use App\Models\Ordering\OrderItem;
use App\Models\Pricing\PriceList;
use App\Support\Auth\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Monta o detalhe de um pedido para a equipe (RF-062, RN-062), sempre a
 * partir de StaffOrderQuery (tenant, orders.view e carteira).
 *
 * order_item_costs só é consultada com catalog.view_costs: sem a permissão,
 * nenhum custo chega à tela nem à memória do componente.
 */
final readonly class LoadStaffOrderDetail
{
    public function __construct(
        private StaffOrderQuery $orders,
        private Access $access,
    ) {}

    public function handle(mixed $orderId): StaffOrderDetail
    {
        $order = $this->orders->find($orderId);
        $order->load('buyerCompany');

        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('line_number')->get();
        $order->setRelation('items', $items);

        $events = OrderEvent::query()->where('order_id', $order->id)->orderBy('created_at')->orderBy('id')->get();
        $credit = CreditMovement::query()
            ->where('reference_type', 'order')
            ->where('reference_id', $order->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $salesRep = $order->sales_rep_membership_id !== null
            ? Membership::query()->with('user')->find($order->sales_rep_membership_id)
            : null;

        $userIds = array_values(array_unique(array_filter([
            $order->placed_by_user_id,
            ...$events->pluck('actor_user_id')->all(),
            ...$credit->pluck('actor_user_id')->all(),
        ], 'is_string')));

        return new StaffOrderDetail(
            order: $order,
            items: $items,
            unit: $order->buyer_unit_id !== null ? BuyerUnit::query()->find($order->buyer_unit_id) : null,
            costCenter: $order->cost_center_id !== null ? CostCenter::query()->find($order->cost_center_id) : null,
            salesRepName: $salesRep?->user->name,
            events: $events,
            reservationsByWarehouse: $this->reservations($items->modelKeys()),
            creditMovements: $credit,
            userNames: User::query()->whereIn('id', $userIds)->pluck('name', 'id')->all(),
            baseUnits: self::baseUnits($items),
            unitDecimals: self::unitDecimals($items),
            priceLists: $this->priceLists($items),
            costs: $this->access->can(Permission::CatalogViewCosts) ? $this->costs($items) : null,
        );
    }

    /**
     * Código da unidade base por SKU dos itens (fixa depois que o SKU existe).
     *
     * @param  iterable<OrderItem>  $items
     * @return array<string, string>
     */
    public static function baseUnits(iterable $items): array
    {
        $skuIds = [];

        foreach ($items as $item) {
            $skuIds[$item->sku_id] = $item->sku_id;
        }

        $units = [];

        foreach (Sku::query()->with('product.baseUnit')->whereIn('id', array_values($skuIds))->get() as $sku) {
            $units[$sku->id] = $sku->product->baseUnit->code;
        }

        return $units;
    }

    /**
     * Casas decimais aceitas por unidade de venda dos itens (código => casas).
     *
     * @param  iterable<OrderItem>  $items
     * @return array<string, int>
     */
    public static function unitDecimals(iterable $items): array
    {
        $codes = [];

        foreach ($items as $item) {
            $codes[$item->unit_code] = $item->unit_code;
        }

        $decimals = [];

        foreach (UnitOfMeasure::query()->whereIn('code', array_values($codes))->get(['code', 'decimals']) as $unit) {
            $decimals[$unit->code] = $unit->decimals;
        }

        return $decimals;
    }

    /**
     * Reservas dos itens agrupadas por depósito (maior prioridade primeiro).
     *
     * @param  array<int, int|string>  $itemIds
     * @return list<array{warehouse: Warehouse, reservations: list<StockReservation>}>
     */
    private function reservations(array $itemIds): array
    {
        $reservations = StockReservation::query()
            ->with('warehouse')
            ->whereIn('order_item_id', $itemIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $groups = [];

        foreach ($reservations as $reservation) {
            $warehouse = $reservation->warehouse;

            if ($warehouse === null) {
                continue;
            }

            $groups[$warehouse->id] ??= ['warehouse' => $warehouse, 'reservations' => []];
            $groups[$warehouse->id]['reservations'][] = $reservation;
        }

        usort($groups, static fn (array $a, array $b): int => [-$a['warehouse']->priority, $a['warehouse']->code] <=> [-$b['warehouse']->priority, $b['warehouse']->code]);

        return $groups;
    }

    /**
     * Códigos das tabelas de origem dos preços, para quem consulta tabelas.
     *
     * @param  iterable<OrderItem>  $items
     * @return array<string, string>
     */
    private function priceLists(iterable $items): array
    {
        if (! $this->access->can(Permission::PricingView)) {
            return [];
        }

        $ids = [];

        foreach ($items as $item) {
            $source = $item->price_source['source'] ?? null;
            $reference = $item->price_source['reference_id'] ?? null;

            if (is_string($source) && $source !== PriceSource::Negotiated->value && is_string($reference) && Str::isUuid($reference)) {
                $ids[] = $reference;
            }
        }

        return $ids === [] ? [] : PriceList::query()->whereIn('id', array_values(array_unique($ids)))->pluck('code', 'id')->all();
    }

    /**
     * @param  iterable<OrderItem>  $items
     * @return array<string, StaffItemCost>
     */
    private function costs(iterable $items): array
    {
        $byItem = [];

        foreach ($items as $item) {
            $byItem[$item->id] = $item;
        }

        $stored = DB::table('order_item_costs')
            ->whereIn('order_item_id', array_keys($byItem))
            ->pluck('unit_cost_cents', 'order_item_id')
            ->all();

        $costs = [];

        foreach ($byItem as $id => $item) {
            $cents = $stored[$id] ?? null;
            $costs[$id] = StaffItemCost::for($item, is_numeric($cents) ? Money::ofCents((int) $cents) : null);
        }

        return $costs;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Domain\Shared\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Disponível agora (físico − reservado) por SKU, somado nos depósitos ativos
 * que atendem vendas. É consulta, não reserva (RN-043): pode mudar até a
 * confirmação, quando a reserva acontece sob lock.
 */
final class StockAvailability
{
    /**
     * @param  list<string>  $skuIds
     * @return array<string, Quantity> SKUs sem saldo ficam de fora
     */
    public function bySku(array $skuIds): array
    {
        if ($skuIds === []) {
            return [];
        }

        $rows = DB::table('stock_levels as sl')
            ->join('warehouses as w', 'w.id', '=', 'sl.warehouse_id')
            ->where('w.is_active', true)
            ->where('w.allows_sales', true)
            ->whereIn('sl.sku_id', $skuIds)
            ->groupBy('sl.sku_id')
            ->selectRaw('sl.sku_id, sum(sl.on_hand - sl.reserved) as available')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row->sku_id] = Quantity::of((string) $row->available);
        }

        return $result;
    }
}

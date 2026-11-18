<?php

declare(strict_types=1);

namespace App\Actions\Catalog\Support;

use App\Models\Catalog\SkuCost;
use Carbon\CarbonImmutable;

/**
 * Custo vigente de SKUs: a linha mais recente de sku_costs com início até o
 * instante informado. Consulta explícita (distinct on) porque o PostgreSQL
 * não tem max() para uuid, usado como desempate pela relação do modelo.
 *
 * Legível apenas em contextos de equipe, integração ou sistema (RLS); quem
 * exibe o valor precisa verificar catalog.view_costs.
 */
final class CurrentSkuCosts
{
    /**
     * @param  list<string>  $skuIds
     * @return array<string, SkuCost> sku_id => custo vigente
     */
    public static function forSkus(array $skuIds, CarbonImmutable $at): array
    {
        if ($skuIds === []) {
            return [];
        }

        return SkuCost::query()
            ->distinct(['sku_id'])
            ->whereIn('sku_id', $skuIds)
            ->where('effective_from', '<=', $at)
            ->orderBy('sku_id')
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->keyBy('sku_id')
            ->all();
    }

    public static function forSku(string $skuId, CarbonImmutable $at): ?SkuCost
    {
        return self::forSkus([$skuId], $at)[$skuId] ?? null;
    }
}

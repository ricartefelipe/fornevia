<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Support\Database\Casts\AsMoney;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Política comercial interna do SKU: preço e margem mínimos (visível apenas à equipe).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sku_id
 * @property \App\Domain\Shared\Money|null $min_unit_price_cents
 * @property int|null $min_margin_bps
 */
class SkuCommercialPolicy extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['*'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'min_unit_price_cents' => AsMoney::class,
            'min_margin_bps' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}

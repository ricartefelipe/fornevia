<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Support\Database\Casts\AsMoney;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Custo histórico de um SKU (append-only; visível apenas à equipe).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sku_id
 * @property \App\Domain\Shared\Money $cost_cents
 * @property \Carbon\CarbonImmutable $effective_from
 * @property string|null $recorded_by_user_id
 */
class SkuCost extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** Histórico imutável: apenas created_at (o custo vigente fica em CurrentSkuCosts). */
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'cost_cents' => AsMoney::class,
            'effective_from' => 'immutable_datetime',
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

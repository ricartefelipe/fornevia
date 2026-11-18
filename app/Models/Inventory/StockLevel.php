<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Catalog\Sku;
use App\Support\Database\Casts\AsQuantity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo por depósito e SKU. Projeção mantida pelo banco a partir dos movimentos: somente leitura na aplicação.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $warehouse_id
 * @property string $sku_id
 * @property \App\Domain\Shared\Quantity $on_hand
 * @property \App\Domain\Shared\Quantity $reserved
 */
class StockLevel extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const CREATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'on_hand' => AsQuantity::class,
            'reserved' => AsQuantity::class,
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}

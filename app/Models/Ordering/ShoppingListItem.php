<?php

declare(strict_types=1);

namespace App\Models\Ordering;

use App\Models\Catalog\Sku;
use App\Support\Database\Casts\AsQuantity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de lista de compra.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $shopping_list_id
 * @property string $buyer_company_id
 * @property string $sku_id
 * @property \App\Domain\Shared\Quantity $quantity
 */
class ShoppingListItem extends Model
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
            'quantity' => AsQuantity::class,
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

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
 * Item do carrinho (SKU e quantidade na unidade de venda).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $cart_id
 * @property string $buyer_company_id
 * @property string $sku_id
 * @property \App\Domain\Shared\Quantity $quantity
 */
class CartItem extends Model
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
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}

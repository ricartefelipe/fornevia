<?php

declare(strict_types=1);

namespace App\Models\Ordering;

use App\Models\Inventory\StockReservation;
use App\Support\Database\Casts\AsDecimal;
use App\Support\Database\Casts\AsMoney;
use App\Support\Database\Casts\AsQuantity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Item de pedido com snapshot comercial e quantidades por estágio (reservada, encomendada, cancelada, expedida, entregue, devolvida).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_id
 * @property string $buyer_company_id
 * @property int $line_number
 * @property string $sku_id
 * @property string $sku_code
 * @property string $product_name
 * @property string|null $variant_label
 * @property string $unit_code
 * @property string|null $package_label
 * @property \Brick\Math\BigDecimal $conversion_factor
 * @property \App\Domain\Shared\Quantity $quantity
 * @property \App\Domain\Shared\Quantity $base_quantity
 * @property \App\Domain\Shared\Money $unit_price_cents
 * @property int $discount_bps
 * @property \App\Domain\Shared\Money $unit_net_price_cents
 * @property \App\Domain\Shared\Money $line_gross_cents
 * @property \App\Domain\Shared\Money $line_total_cents
 * @property array<string, mixed> $price_source
 * @property \App\Domain\Shared\Quantity $quantity_reserved
 * @property \App\Domain\Shared\Quantity $quantity_backordered
 * @property \App\Domain\Shared\Quantity $quantity_cancelled
 * @property \App\Domain\Shared\Quantity $quantity_shipped
 * @property \App\Domain\Shared\Quantity $quantity_delivered
 * @property \App\Domain\Shared\Quantity $quantity_returned
 */
class OrderItem extends Model
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
            'conversion_factor' => AsDecimal::class.':6',
            'quantity' => AsQuantity::class,
            'base_quantity' => AsQuantity::class,
            'unit_price_cents' => AsMoney::class,
            'discount_bps' => 'integer',
            'unit_net_price_cents' => AsMoney::class,
            'line_gross_cents' => AsMoney::class,
            'line_total_cents' => AsMoney::class,
            'price_source' => 'array',
            'quantity_reserved' => AsQuantity::class,
            'quantity_backordered' => AsQuantity::class,
            'quantity_cancelled' => AsQuantity::class,
            'quantity_shipped' => AsQuantity::class,
            'quantity_delivered' => AsQuantity::class,
            'quantity_returned' => AsQuantity::class,
            'line_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<StockReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }
}

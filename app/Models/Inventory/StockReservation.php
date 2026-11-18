<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Enums\Inventory\ReservationStatus;
use App\Support\Database\Casts\AsQuantity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reserva de estoque de um item de pedido em um depósito.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $warehouse_id
 * @property string $sku_id
 * @property string|null $order_item_id
 * @property \App\Domain\Shared\Quantity $quantity
 * @property \App\Domain\Shared\Quantity $quantity_consumed
 * @property \App\Domain\Shared\Quantity $quantity_released
 * @property ReservationStatus $status
 */
class StockReservation extends Model
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
            'quantity_consumed' => AsQuantity::class,
            'quantity_released' => AsQuantity::class,
            'status' => ReservationStatus::class,
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}

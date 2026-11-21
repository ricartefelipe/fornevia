<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Enums\Inventory\StockMovementType;
use App\Models\Catalog\Sku;
use App\Support\Database\Casts\AsQuantity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimento de estoque (append-only), com deltas explícitos de físico e reservado.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $warehouse_id
 * @property string $sku_id
 * @property string|null $reservation_id
 * @property StockMovementType $type
 * @property \App\Domain\Shared\Quantity $quantity
 * @property \App\Domain\Shared\Quantity $on_hand_delta
 * @property \App\Domain\Shared\Quantity $reserved_delta
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string|null $transfer_id
 * @property string|null $reason
 * @property string|null $actor_user_id
 * @property string|null $correlation_id
 */
class StockMovement extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => AsQuantity::class,
            'on_hand_delta' => AsQuantity::class,
            'reserved_delta' => AsQuantity::class,
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

<?php

declare(strict_types=1);

namespace App\Models\Fulfillment;

use App\Enums\Fulfillment\ShipmentStatus;
use App\Models\Inventory\Warehouse;
use App\Models\Ordering\Order;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Remessa de um pedido a partir de um depósito (RF-090, ADR-027).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $order_id
 * @property string $warehouse_id
 * @property string $number
 * @property ShipmentStatus $status
 * @property string|null $carrier
 * @property string|null $tracking_code
 * @property string|null $created_by_user_id
 * @property \Carbon\CarbonImmutable|null $checked_at
 * @property string|null $checked_by_user_id
 * @property \Carbon\CarbonImmutable|null $shipped_at
 * @property string|null $shipped_by_user_id
 * @property \Carbon\CarbonImmutable|null $delivered_at
 * @property \Carbon\CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property \Carbon\CarbonImmutable|null $last_event_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ShipmentItem> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ShipmentEvent> $events
 * @property-read Order $order
 * @property-read Warehouse $warehouse
 */
class Shipment extends Model
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
            'status' => ShipmentStatus::class,
            'checked_at' => 'immutable_datetime',
            'shipped_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'last_event_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<ShipmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    /**
     * @return HasMany<ShipmentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('occurred_at')->orderBy('created_at');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}

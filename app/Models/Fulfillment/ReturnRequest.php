<?php

declare(strict_types=1);

namespace App\Models\Fulfillment;

use App\Enums\Fulfillment\ReturnReason;
use App\Enums\Fulfillment\ReturnStatus;
use App\Models\Inventory\Warehouse;
use App\Models\Ordering\Order;
use App\Support\Database\Casts\AsMoney;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Devolução pedida pelo comprador sobre itens entregues (RF-091, ADR-027).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $order_id
 * @property string $number
 * @property ReturnStatus $status
 * @property ReturnReason $reason_code
 * @property string $reason
 * @property string $requested_by_user_id
 * @property string $requested_by_membership_id
 * @property string|null $decided_by_user_id
 * @property \Carbon\CarbonImmutable|null $decided_at
 * @property string|null $decision_comment
 * @property string|null $warehouse_id
 * @property bool $inspection_required
 * @property \Carbon\CarbonImmutable|null $received_at
 * @property string|null $received_by_user_id
 * @property \Carbon\CarbonImmutable|null $closed_at
 * @property \App\Domain\Shared\Money $refund_cents
 * @property \Carbon\CarbonImmutable $created_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ReturnItem> $items
 * @property-read Order $order
 * @property-read Warehouse|null $warehouse
 */
class ReturnRequest extends Model
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
            'status' => ReturnStatus::class,
            'reason_code' => ReturnReason::class,
            'decided_at' => 'immutable_datetime',
            'inspection_required' => 'boolean',
            'received_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'refund_cents' => AsMoney::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<ReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class);
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

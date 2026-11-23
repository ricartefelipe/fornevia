<?php

declare(strict_types=1);

namespace App\Models\Fulfillment;

use App\Domain\Shared\Quantity;
use App\Models\Ordering\OrderItem;
use App\Support\Database\Casts\AsQuantity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de remessa, na unidade de venda do item do pedido.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $shipment_id
 * @property string $order_item_id
 * @property Quantity $quantity
 * @property Quantity|null $quantity_checked
 * @property-read OrderItem $orderItem
 */
class ShipmentItem extends Model
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
            'quantity_checked' => AsQuantity::class,
        ];
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}

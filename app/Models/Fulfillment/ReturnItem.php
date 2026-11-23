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
 * Item de devolução, na unidade de venda: pedido, recebido e o resultado
 * da inspeção.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $return_request_id
 * @property string $order_item_id
 * @property Quantity $quantity
 * @property Quantity $quantity_received
 * @property Quantity $quantity_approved
 * @property Quantity $quantity_rejected
 * @property string|null $inspection_note
 * @property-read OrderItem $orderItem
 */
class ReturnItem extends Model
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
            'quantity_received' => AsQuantity::class,
            'quantity_approved' => AsQuantity::class,
            'quantity_rejected' => AsQuantity::class,
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

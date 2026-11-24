<?php

declare(strict_types=1);

namespace App\Models\Finance;

use App\Domain\Shared\Money;
use App\Support\Database\Casts\AsMoney;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item faturado: quantidade expedida do item do pedido e valor líquido.
 *
 * @property string $id
 * @property string $invoice_id
 * @property string $order_item_id
 * @property \App\Domain\Shared\Quantity $quantity
 * @property Money $amount_cents
 * @property-read \App\Models\Ordering\OrderItem $orderItem
 */
class InvoiceItem extends Model
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
            'quantity' => \App\Support\Database\Casts\AsQuantity::class,
            'amount_cents' => AsMoney::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<\App\Models\Ordering\OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Ordering\OrderItem::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Ordering;

use App\Enums\Ordering\CartStatus;
use App\Models\Buyers\BuyerCompany;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Carrinho aberto de uma associação de comprador. Não guarda preços: são calculados no servidor a cada exibição e na confirmação.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $membership_id
 * @property string|null $buyer_unit_id
 * @property string|null $cost_center_id
 * @property string|null $shipping_address_id
 * @property string|null $billing_address_id
 * @property string|null $payment_term_id
 * @property CartStatus $status
 * @property string|null $purchase_order_reference
 * @property string|null $notes
 * @property string|null $converted_order_id
 */
class Cart extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['purchase_order_reference', 'notes'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => CartStatus::class,
        ];
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * @return BelongsTo<BuyerCompany, $this>
     */
    public function buyerCompany(): BelongsTo
    {
        return $this->belongsTo(BuyerCompany::class);
    }
}

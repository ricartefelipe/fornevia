<?php

declare(strict_types=1);

namespace App\Models\Quotes;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Item pedido pelo comprador, com retrato do SKU e a quantidade.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $quote_request_id
 * @property int $line_number
 * @property string $sku_id
 * @property string $sku_code
 * @property string $product_name
 * @property string|null $variant_label
 * @property string $unit_code
 * @property \App\Domain\Shared\Quantity $quantity
 * @property \Carbon\CarbonImmutable $created_at
 */
class QuoteRequestItem extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** Registro append-only: apenas created_at. */
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => \App\Support\Database\Casts\AsQuantity::class,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Quotes;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha da versão com retrato do item e valores calculados no servidor (RN-034, RN-044). Append-only.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $quote_version_id
 * @property int $line_number
 * @property string $sku_id
 * @property string $sku_code
 * @property string $product_name
 * @property string|null $variant_label
 * @property string $unit_code
 * @property string|null $package_label
 * @property \Brick\Math\BigDecimal $conversion_factor
 * @property string $packaging_fingerprint
 * @property \App\Domain\Shared\Quantity $quantity
 * @property \App\Domain\Shared\Quantity $base_quantity
 * @property \App\Domain\Shared\Money $unit_price_cents
 * @property int $discount_bps
 * @property \App\Domain\Shared\Money $unit_net_price_cents
 * @property \App\Domain\Shared\Money $line_gross_cents
 * @property \App\Domain\Shared\Money $line_total_cents
 * @property array<string, mixed> $price_source
 * @property \Carbon\CarbonImmutable $created_at
 */
class QuoteItem extends Model
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
            'conversion_factor' => \App\Support\Database\Casts\AsDecimal::class.':6',
            'quantity' => \App\Support\Database\Casts\AsQuantity::class,
            'base_quantity' => \App\Support\Database\Casts\AsQuantity::class,
            'unit_price_cents' => \App\Support\Database\Casts\AsMoney::class,
            'discount_bps' => 'integer',
            'unit_net_price_cents' => \App\Support\Database\Casts\AsMoney::class,
            'line_gross_cents' => \App\Support\Database\Casts\AsMoney::class,
            'line_total_cents' => \App\Support\Database\Casts\AsMoney::class,
            'price_source' => 'array',
        ];
    }

    /**
     * @return BelongsTo<QuoteVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(QuoteVersion::class, 'quote_version_id');
    }
}

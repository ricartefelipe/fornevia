<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use App\Domain\Shared\Money;
use App\Enums\Pricing\NegotiatedPriceStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Sku;
use App\Support\Database\Casts\AsMoney;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Condição negociada (contratada) para uma empresa e um SKU. Vigências de
 * condições ativas não se sobrepõem (exclusion constraint).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $sku_id
 * @property Money $unit_price_cents
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_until
 * @property string $source
 * @property string|null $source_reference_id
 * @property NegotiatedPriceStatus $status
 * @property string|null $approved_by_user_id
 * @property string|null $notes
 * @property-read BuyerCompany $buyerCompany
 * @property-read Sku $sku
 */
class NegotiatedPrice extends Model
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
            'unit_price_cents' => AsMoney::class,
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'status' => NegotiatedPriceStatus::class,
        ];
    }

    /**
     * @return BelongsTo<BuyerCompany, $this>
     */
    public function buyerCompany(): BelongsTo
    {
        return $this->belongsTo(BuyerCompany::class);
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}

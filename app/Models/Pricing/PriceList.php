<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use App\Enums\Pricing\PriceListScope;
use App\Enums\Pricing\PriceListStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\CustomerGroup;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela de preço padrão, de grupo ou de empresa, com vigência e prioridade.
 * Escopo, alvo e situação são definidos por Actions (versão de preços).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property PriceListScope $scope
 * @property string|null $customer_group_id
 * @property string|null $buyer_company_id
 * @property int $priority
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_until
 * @property PriceListStatus $status
 * @property-read CustomerGroup|null $customerGroup
 * @property-read BuyerCompany|null $buyerCompany
 * @property-read Collection<int, PriceListItem> $items
 */
class PriceList extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['code', 'name'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft', 'priority' => 0];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'scope' => PriceListScope::class,
            'status' => PriceListStatus::class,
            'priority' => 'integer',
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<CustomerGroup, $this>
     */
    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    /**
     * @return BelongsTo<BuyerCompany, $this>
     */
    public function buyerCompany(): BelongsTo
    {
        return $this->belongsTo(BuyerCompany::class);
    }

    /**
     * @return HasMany<PriceListItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }
}

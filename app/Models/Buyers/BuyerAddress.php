<?php

declare(strict_types=1);

namespace App\Models\Buyers;

use App\Enums\Buyers\AddressKind;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Endereço de cobrança ou entrega da empresa compradora.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string|null $buyer_unit_id
 * @property AddressKind $kind
 * @property string $label
 * @property string|null $recipient
 * @property string $street
 * @property string $number
 * @property string|null $complement
 * @property string $district
 * @property string $city
 * @property string $state
 * @property string $postal_code
 * @property bool $is_default
 * @property bool $is_active
 */
class BuyerAddress extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['kind', 'label', 'recipient', 'street', 'number', 'complement', 'district', 'city', 'state', 'postal_code', 'is_default', 'is_active'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kind' => AddressKind::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
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
     * @return BelongsTo<BuyerUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(BuyerUnit::class, 'buyer_unit_id');
    }
}

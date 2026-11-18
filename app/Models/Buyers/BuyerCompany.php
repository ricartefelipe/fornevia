<?php

declare(strict_types=1);

namespace App\Models\Buyers;

use App\Enums\Buyers\BuyerCompanyStatus;
use App\Models\Identity\Membership;
use App\Models\Pricing\BuyerPaymentTerm;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Empresa compradora (cliente B2B) dentro de um tenant.
 *
 * O mesmo CNPJ pode existir em tenants diferentes: cada distribuidor mantém
 * seu próprio cadastro e suas condições, sem compartilhamento.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $legal_name
 * @property string $trade_name
 * @property string $document
 * @property string|null $state_registration
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $customer_group_id
 * @property string|null $sales_rep_membership_id
 * @property BuyerCompanyStatus $status
 * @property string|null $notes
 * @property-read CustomerGroup|null $customerGroup
 * @property-read Membership|null $salesRep
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BuyerAddress> $addresses
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BuyerUnit> $units
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CostCenter> $costCenters
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BuyerContact> $contacts
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BuyerPaymentTerm> $paymentTerms
 */
class BuyerCompany extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['legal_name', 'trade_name', 'document', 'state_registration', 'email', 'phone', 'notes'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => BuyerCompanyStatus::class];
    }

    /**
     * @return BelongsTo<CustomerGroup, $this>
     */
    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'sales_rep_membership_id');
    }

    /**
     * @return HasMany<BuyerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(BuyerAddress::class);
    }

    /**
     * @return HasMany<BuyerUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(BuyerUnit::class);
    }

    /**
     * @return HasMany<CostCenter, $this>
     */
    public function costCenters(): HasMany
    {
        return $this->hasMany(CostCenter::class);
    }

    /**
     * @return HasMany<BuyerContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(BuyerContact::class);
    }

    /**
     * @return HasMany<BuyerPaymentTerm, $this>
     */
    public function paymentTerms(): HasMany
    {
        return $this->hasMany(BuyerPaymentTerm::class);
    }
}

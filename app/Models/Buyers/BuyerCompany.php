<?php

declare(strict_types=1);

namespace App\Models\Buyers;

use App\Enums\Buyers\BuyerCompanyStatus;
use App\Models\Identity\Membership;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}

<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\CustomerGroup;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Atribuição de catálogo privado a uma empresa compradora ou a um grupo.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $private_catalog_id
 * @property string|null $buyer_company_id
 * @property string|null $customer_group_id
 * @property-read BuyerCompany|null $buyerCompany
 * @property-read CustomerGroup|null $customerGroup
 */
class PrivateCatalogAssignment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return BelongsTo<BuyerCompany, $this>
     */
    public function buyerCompany(): BelongsTo
    {
        return $this->belongsTo(BuyerCompany::class);
    }

    /**
     * @return BelongsTo<CustomerGroup, $this>
     */
    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }
}

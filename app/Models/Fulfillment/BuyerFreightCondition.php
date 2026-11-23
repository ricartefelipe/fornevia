<?php

declare(strict_types=1);

namespace App\Models\Fulfillment;

use App\Enums\Fulfillment\FreightConditionKind;
use App\Support\Database\Casts\AsMoney;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Condição de frete negociada com a empresa compradora (RF-092).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property FreightConditionKind $kind
 * @property \App\Domain\Shared\Money $amount_cents
 */
class BuyerFreightCondition extends Model
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
            'kind' => FreightConditionKind::class,
            'amount_cents' => AsMoney::class,
        ];
    }
}

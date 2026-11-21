<?php

declare(strict_types=1);

namespace App\Models\Buyers;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Centro de custo da empresa compradora (base de orçamento e aprovação).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string|null $buyer_unit_id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 */
class CostCenter extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'is_active'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
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

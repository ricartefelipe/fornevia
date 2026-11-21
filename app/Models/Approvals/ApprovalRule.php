<?php

declare(strict_types=1);

namespace App\Models\Approvals;

use App\Support\Database\Casts\AsMoney;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Regra de aprovação de compras da empresa compradora (RF-050).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $name
 * @property int $priority
 * @property bool $is_active
 * @property \App\Domain\Shared\Money|null $min_total_cents
 * @property string|null $cost_center_id
 * @property string|null $buyer_unit_id
 * @property string|null $category_id
 * @property string|null $requester_role_key
 * @property \Carbon\CarbonImmutable $created_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ApprovalRuleLevel> $levels
 */
class ApprovalRule extends Model
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
            'priority' => 'integer',
            'is_active' => 'boolean',
            'min_total_cents' => AsMoney::class,
        ];
    }

    /**
     * @return HasMany<ApprovalRuleLevel, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(ApprovalRuleLevel::class)->orderBy('level_number');
    }
}

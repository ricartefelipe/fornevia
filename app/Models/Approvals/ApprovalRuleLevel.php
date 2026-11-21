<?php

declare(strict_types=1);

namespace App\Models\Approvals;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nível de uma regra: quórum e aprovadores.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $approval_rule_id
 * @property int $level_number
 * @property int $required_approvals
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ApprovalRuleLevelApprover> $approvers
 */
class ApprovalRuleLevel extends Model
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
            'level_number' => 'integer',
            'required_approvals' => 'integer',
        ];
    }

    /**
     * @return HasMany<ApprovalRuleLevelApprover, $this>
     */
    public function approvers(): HasMany
    {
        return $this->hasMany(ApprovalRuleLevelApprover::class);
    }
}

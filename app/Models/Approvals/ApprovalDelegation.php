<?php

declare(strict_types=1);

namespace App\Models\Approvals;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Delegação de aprovação com prazo (RN-053).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $delegator_membership_id
 * @property string $delegate_membership_id
 * @property \Carbon\CarbonImmutable $valid_from
 * @property \Carbon\CarbonImmutable $valid_until
 * @property string $reason
 * @property \Carbon\CarbonImmutable|null $revoked_at
 * @property string $created_by_user_id
 */
class ApprovalDelegation extends Model
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
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}

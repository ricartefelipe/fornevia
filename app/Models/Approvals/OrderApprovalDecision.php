<?php

declare(strict_types=1);

namespace App\Models\Approvals;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Decisão em um nível (append-only; invalidação apenas marcada).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $order_id
 * @property string $order_approval_step_id
 * @property string $actor_membership_id
 * @property string $principal_membership_id
 * @property \App\Enums\Approvals\ApprovalDecision $decision
 * @property string|null $comment
 * @property string $order_fingerprint
 * @property string|null $delegation_id
 * @property \Carbon\CarbonImmutable|null $invalidated_at
 * @property \Carbon\CarbonImmutable $created_at
 */
class OrderApprovalDecision extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'decision' => \App\Enums\Approvals\ApprovalDecision::class,
            'invalidated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}

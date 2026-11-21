<?php

declare(strict_types=1);

namespace App\Models\Approvals;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Nível de aprovação de um pedido, retratado na submissão.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $order_id
 * @property int $level_number
 * @property int $required_approvals
 * @property list<string> $approver_membership_ids
 * @property \App\Enums\Approvals\ApprovalStepStatus $status
 * @property \Carbon\CarbonImmutable|null $decided_at
 */
class OrderApprovalStep extends Model
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
            'approver_membership_ids' => 'array',
            'status' => \App\Enums\Approvals\ApprovalStepStatus::class,
            'decided_at' => 'immutable_datetime',
        ];
    }
}

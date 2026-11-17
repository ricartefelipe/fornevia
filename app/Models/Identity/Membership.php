<?php

declare(strict_types=1);

namespace App\Models\Identity;

use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Models\Buyers\BuyerCompany;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\ExecutionContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Associação de um usuário a um contexto: equipe do distribuidor ou
 * usuário de uma empresa compradora. As permissões vêm do papel.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property MembershipKind $kind
 * @property string|null $buyer_company_id
 * @property string $role_id
 * @property string|null $branch_id
 * @property MembershipStatus $status
 * @property string|null $invited_by_user_id
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_by_user_id
 * @property string|null $revoke_reason
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable $created_at
 * @property-read User $user
 * @property-read Role $role
 * @property-read BuyerCompany|null $buyerCompany
 * @property-read Branch|null $branch
 * @property-read Organization $tenant
 */
class Membership extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** Nenhum atributo atribuível em massa: criação apenas por Actions. */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MembershipKind::class,
            'status' => MembershipStatus::class,
            'revoked_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<BuyerCompany, $this>
     */
    public function buyerCompany(): BelongsTo
    {
        return $this->belongsTo(BuyerCompany::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    public function executionContext(): ExecutionContext
    {
        return match ($this->kind) {
            MembershipKind::Staff => ExecutionContext::staff($this->user_id, $this->tenant_id, $this->id),
            MembershipKind::Buyer => ExecutionContext::buyer(
                $this->user_id,
                $this->tenant_id,
                (string) $this->buyer_company_id,
                $this->id,
            ),
        };
    }
}

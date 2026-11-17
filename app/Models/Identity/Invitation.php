<?php

declare(strict_types=1);

namespace App\Models\Identity;

use App\Enums\Identity\MembershipKind;
use App\Models\Buyers\BuyerCompany;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Convite de uso único. Apenas o hash SHA-256 do token é persistido.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property MembershipKind $kind
 * @property string|null $buyer_company_id
 * @property string $role_id
 * @property string|null $branch_id
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property string|null $accepted_by_user_id
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $invited_by_user_id
 * @property-read Role $role
 * @property-read BuyerCompany|null $buyerCompany
 * @property-read Organization $tenant
 */
class Invitation extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MembershipKind::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
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

    public function isPending(CarbonImmutable $now): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->greaterThan($now);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Data\Identity\ContextOption;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Membership;
use App\Models\Identity\Role;
use App\Models\Identity\User;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use App\Support\Tenancy\TenantScope;

/**
 * Lista os contextos de um usuário. Rótulos de cada tenant (papel e
 * empresa compradora) são lidos no contexto da própria associação, com o
 * menor privilégio possível.
 */
final readonly class ContextDirectory
{
    public function __construct(private TenantDatabase $db) {}

    /**
     * @return list<ContextOption>
     */
    public function forUser(User $user): array
    {
        $memberships = $this->db->run(ExecutionContext::user($user->id), static fn () => Membership::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with('tenant')
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active->value)
            ->orderBy('created_at')
            ->get());

        $options = [];

        foreach ($memberships as $membership) {
            [$roleName, $buyerName] = $this->db->run($membership->executionContext(), static fn (): array => [
                (string) Role::query()->whereKey($membership->role_id)->value('name'),
                $membership->kind === MembershipKind::Buyer
                    ? BuyerCompany::query()->whereKey($membership->buyer_company_id)->value('trade_name')
                    : null,
            ]);

            $options[] = new ContextOption(
                membershipId: $membership->id,
                organizationName: $membership->tenant->trade_name,
                kind: $membership->kind,
                roleName: $roleName,
                buyerCompanyName: is_string($buyerName) ? $buyerName : null,
                available: $membership->tenant->allowsAccess(),
            );
        }

        return $options;
    }
}

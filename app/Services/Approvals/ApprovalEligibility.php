<?php

declare(strict_types=1);

namespace App\Services\Approvals;

use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\Permission;
use App\Models\Approvals\ApprovalDelegation;
use App\Models\Identity\Membership;
use App\Support\Clock\Clock;

/**
 * Quem pode decidir hoje (RN-052, RN-053): associação ativa da empresa
 * compradora com permissão de aprovar; delegações vigentes e não revogadas.
 */
final readonly class ApprovalEligibility
{
    public function __construct(private Clock $clock) {}

    /**
     * @param  list<string>  $membershipIds
     * @return list<string>
     */
    public function eligible(string $buyerCompanyId, array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        return array_values(Membership::query()
            ->with('role')
            ->whereKey($membershipIds)
            ->where('buyer_company_id', $buyerCompanyId)
            ->where('status', MembershipStatus::Active->value)
            ->get()
            ->filter(static fn (Membership $m): bool => in_array(Permission::PortalApprovals->value, $m->role->permissions, true))
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all());
    }

    /**
     * Titulares que delegaram ao ator, com delegação vigente agora.
     *
     * Com $lock (decisão), as delegações ficam bloqueadas em modo
     * compartilhado até o fim da transação: uma revogação concorrente
     * termina antes (e a delegação revogada não é usada) ou espera a decisão.
     *
     * @return array<string, string> delegator_membership_id => delegation_id
     */
    public function delegatorsOf(string $buyerCompanyId, string $actorMembershipId, bool $lock = false): array
    {
        $now = $this->clock->now();

        return ApprovalDelegation::query()
            ->where('buyer_company_id', $buyerCompanyId)
            ->where('delegate_membership_id', $actorMembershipId)
            ->whereNull('revoked_at')
            ->where('valid_from', '<=', $now)
            ->where('valid_until', '>', $now)
            ->when($lock, static fn ($query) => $query->sharedLock())
            ->orderBy('created_at')
            ->pluck('id', 'delegator_membership_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }
}

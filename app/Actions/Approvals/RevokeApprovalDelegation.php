<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Data\Approvals\DelegationPeriod;
use App\Domain\Approvals\Exceptions\ApprovalNotAllowed;
use App\Enums\Identity\Permission;
use App\Models\Approvals\ApprovalDelegation;
use App\Services\Audit\AuditRecorder;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Revoga uma delegação vigente ou futura (RN-053). O titular revoga as
 * próprias; o administrador (portal.approval_rules), qualquer delegação da
 * empresa. A partir da revogação o delegado deixa de decidir em nome do
 * titular; decisões já registradas permanecem no histórico.
 */
final readonly class RevokeApprovalDelegation
{
    public const int MAX_REASON_LENGTH = 500;

    public function __construct(
        private PortalBuyer $buyer,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    /**
     * @throws AuthorizationException sem permissão, ou aprovador revogando delegação de outro titular
     * @throws ModelNotFoundException delegação inexistente, malformada ou de outra empresa
     * @throws ApprovalNotAllowed delegação já revogada ou encerrada
     */
    public function handle(string $delegationId, ?string $reason = null): ApprovalDelegation
    {
        $actor = $this->buyer->require();
        $manages = $this->buyer->can(Permission::PortalApprovalRules);

        if (! $manages && ! $this->buyer->can(Permission::PortalApprovals)) {
            throw new AuthorizationException('Permissão necessária: '.Permission::PortalApprovals->label().'.');
        }

        $delegationId = UntrustedId::uuid($delegationId, ApprovalDelegation::class);
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        if ($reason !== null && mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new ApprovalNotAllowed('O motivo deve ter até '.self::MAX_REASON_LENGTH.' caracteres.', ['field' => 'reason']);
        }

        return DB::transaction(function () use ($actor, $manages, $delegationId, $reason): ApprovalDelegation {
            $delegation = ApprovalDelegation::query()
                ->where('buyer_company_id', $actor->company->id)
                ->whereKey($delegationId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $manages && $delegation->delegator_membership_id !== $actor->membership->id) {
                throw new AuthorizationException('Somente o titular ou o administrador da empresa revoga esta delegação.');
            }

            $now = $this->clock->now();

            if ($delegation->revoked_at !== null) {
                throw new ApprovalNotAllowed('Esta delegação já foi revogada.');
            }

            if ($delegation->valid_until->lessThanOrEqualTo($now)) {
                throw new ApprovalNotAllowed('Esta delegação já terminou; não há o que revogar.');
            }

            $delegation->forceFill(['revoked_at' => $now])->save();
            $period = DelegationPeriod::fromInstants($delegation->valid_from, $delegation->valid_until);

            $this->audit->record('approval_delegation.revoked', $delegation, [
                'buyer_company_id' => $actor->company->id,
                'delegator_membership_id' => $delegation->delegator_membership_id,
                'delegate_membership_id' => $delegation->delegate_membership_id,
                'first_day' => $period->firstDay,
                'last_day' => $period->lastDay,
                'started' => $delegation->valid_from->lessThanOrEqualTo($now),
            ], $reason);

            return $delegation;
        });
    }
}

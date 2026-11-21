<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Data\Approvals\ApprovalDelegationData;
use App\Data\Approvals\DelegationPeriod;
use App\Data\Ordering\Portal\BuyerActor;
use App\Domain\Approvals\Exceptions\ApprovalNotAllowed;
use App\Enums\Identity\Permission;
use App\Models\Approvals\ApprovalDelegation;
use App\Models\Identity\Membership;
use App\Services\Approvals\ApprovalEligibility;
use App\Services\Audit\AuditRecorder;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Tenancy\CurrentContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Delega as aprovações de um titular a outro usuário da empresa por um
 * prazo (RN-053), com motivo e auditoria.
 *
 * - o aprovador (portal.approvals) delega as próprias aprovações; o
 *   administrador (portal.approval_rules) delega por qualquer aprovador;
 * - titular e delegado são associações ativas da empresa com permissão de
 *   aprovar (ApprovalEligibility), diferentes entre si;
 * - a vigência vai do primeiro ao último dia (fuso do negócio), começa hoje
 *   ou depois e dura no máximo MAX_DAYS dias; não se sobrepõe a outra
 *   delegação ativa do mesmo titular ao mesmo delegado;
 * - o delegado nunca decide pedido que ele mesmo solicitou: a regra é
 *   aplicada na decisão (ApprovalFlow).
 */
final readonly class SaveApprovalDelegation
{
    public const int MAX_DAYS = 90;

    public const int MAX_DAYS_AHEAD = 365;

    public const int MIN_REASON_LENGTH = 5;

    public const int MAX_REASON_LENGTH = 500;

    public function __construct(
        private PortalBuyer $buyer,
        private ApprovalEligibility $eligibility,
        private AuditRecorder $audit,
        private CurrentContext $context,
        private TenantDatabase $db,
        private Clock $clock,
    ) {}

    /**
     * @throws AuthorizationException sem permissão, ou aprovador delegando por outra pessoa
     * @throws ModelNotFoundException identificador malformado
     * @throws ApprovalNotAllowed dados inválidos (detalhe "field" indica o campo)
     */
    public function handle(ApprovalDelegationData $data): ApprovalDelegation
    {
        $actor = $this->buyer->require();
        $manages = $this->buyer->can(Permission::PortalApprovalRules);

        if (! $manages && ! $this->buyer->can(Permission::PortalApprovals)) {
            throw new AuthorizationException('Permissão necessária: '.Permission::PortalApprovals->label().'.');
        }

        $delegatorId = UntrustedId::uuid($data->delegatorMembershipId, Membership::class);
        $delegateId = UntrustedId::uuid($data->delegateMembershipId, Membership::class);

        if (! $manages && $delegatorId !== $actor->membership->id) {
            throw new AuthorizationException('Você pode delegar apenas as suas próprias aprovações.');
        }

        if ($delegatorId === $delegateId) {
            throw new ApprovalNotAllowed('Escolha como delegado outra pessoa, diferente do titular.', ['field' => 'delegate']);
        }

        $period = $this->period($data->firstDay, $data->lastDay);
        $reason = self::reason($data->reason);

        return DB::transaction(function () use ($actor, $delegatorId, $delegateId, $period, $reason, $manages): ApprovalDelegation {
            // Serializa delegações do mesmo titular (duplo clique, abas): a
            // segunda vê a primeira na verificação de sobreposição.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [
                implode('|', ['approval_delegations', $this->context->tenantId(), $actor->company->id, $delegatorId]),
            ]);

            $eligible = $this->eligibility->eligible($actor->company->id, [$delegatorId, $delegateId]);

            if (! in_array($delegatorId, $eligible, true)) {
                throw new ApprovalNotAllowed('O titular precisa ser um usuário ativo da empresa com permissão de aprovar compras.', ['field' => 'delegator']);
            }

            if (! in_array($delegateId, $eligible, true)) {
                throw new ApprovalNotAllowed('O delegado precisa ser um usuário ativo da empresa com permissão de aprovar compras.', ['field' => 'delegate']);
            }

            $this->assertNoOverlap($actor, $delegatorId, $delegateId, $period);

            // Delegações são somente leitura no contexto de comprador
            // (ADR-024): grava no contexto de sistema do mesmo usuário.
            $delegation = $this->db->asSystem(static function () use ($actor, $delegatorId, $delegateId, $period, $reason): ApprovalDelegation {
                $delegation = new ApprovalDelegation;
                $delegation->forceFill([
                    'buyer_company_id' => $actor->company->id,
                    'delegator_membership_id' => $delegatorId,
                    'delegate_membership_id' => $delegateId,
                    'valid_from' => $period->validFrom(),
                    'valid_until' => $period->validUntil(),
                    'reason' => $reason,
                    'created_by_user_id' => $actor->membership->user_id,
                ])->save();

                return $delegation;
            });

            $this->audit->record('approval_delegation.created', $delegation, [
                'buyer_company_id' => $actor->company->id,
                'delegator_membership_id' => $delegatorId,
                'delegate_membership_id' => $delegateId,
                'first_day' => $period->firstDay,
                'last_day' => $period->lastDay,
                'by_administrator' => $manages && $delegatorId !== $actor->membership->id,
            ], $reason);

            return $delegation;
        });
    }

    private function period(string $firstDay, string $lastDay): DelegationPeriod
    {
        try {
            DelegationPeriod::fromDays($firstDay, $firstDay);
        } catch (InvalidArgumentException) {
            throw new ApprovalNotAllowed('Informe o primeiro dia da delegação.', ['field' => 'first_day']);
        }

        try {
            $period = DelegationPeriod::fromDays($firstDay, $lastDay);
        } catch (InvalidArgumentException) {
            throw new ApprovalNotAllowed('Informe o último dia da delegação.', ['field' => 'last_day']);
        }

        $today = $this->clock->today();

        if ($period->firstDay < $today->toDateString()) {
            throw new ApprovalNotAllowed('A delegação não pode começar antes de hoje.', ['field' => 'first_day']);
        }

        if ($period->firstDay > $today->addDays(self::MAX_DAYS_AHEAD)->toDateString()) {
            throw new ApprovalNotAllowed('A delegação deve começar em até '.self::MAX_DAYS_AHEAD.' dias.', ['field' => 'first_day']);
        }

        if ($period->lastDay < $period->firstDay) {
            throw new ApprovalNotAllowed('O último dia não pode ser anterior ao primeiro.', ['field' => 'last_day']);
        }

        if ($period->days() > self::MAX_DAYS) {
            throw new ApprovalNotAllowed('A delegação vale por no máximo '.self::MAX_DAYS.' dias.', ['field' => 'last_day']);
        }

        return $period;
    }

    private static function reason(string $value): string
    {
        $reason = trim($value);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw new ApprovalNotAllowed('Informe o motivo da delegação (ao menos '.self::MIN_REASON_LENGTH.' caracteres).', ['field' => 'reason']);
        }

        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new ApprovalNotAllowed('O motivo deve ter até '.self::MAX_REASON_LENGTH.' caracteres.', ['field' => 'reason']);
        }

        return $reason;
    }

    private function assertNoOverlap(BuyerActor $actor, string $delegatorId, string $delegateId, DelegationPeriod $period): void
    {
        $overlaps = ApprovalDelegation::query()
            ->where('buyer_company_id', $actor->company->id)
            ->where('delegator_membership_id', $delegatorId)
            ->where('delegate_membership_id', $delegateId)
            ->whereNull('revoked_at')
            ->where('valid_until', '>', $this->clock->now())
            ->where('valid_from', '<', $period->validUntil())
            ->where('valid_until', '>', $period->validFrom())
            ->exists();

        if ($overlaps) {
            throw new ApprovalNotAllowed(
                'Já existe delegação deste titular para o mesmo delegado nesse período. Revogue-a ou escolha outras datas.',
                ['field' => 'first_day'],
            );
        }
    }
}

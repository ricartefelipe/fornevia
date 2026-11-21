<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Approvals\Admin;

use App\Actions\Approvals\RevokeApprovalDelegation;
use App\Actions\Approvals\SaveApprovalDelegation;
use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Data\Approvals\ApprovalDelegationData;
use App\Data\Approvals\ApprovalDelegationEntry;
use App\Data\Approvals\ApproverOption;
use App\Data\Approvals\DelegationPeriod;
use App\Data\Ordering\Portal\BuyerActor;
use App\Domain\Approvals\Exceptions\ApprovalNotAllowed;
use App\Enums\Identity\Permission;
use App\Livewire\Portal\Approvals\Admin\Concerns\ReadsCompanyApprovers;
use App\Models\Approvals\ApprovalDelegation;
use App\Models\Identity\User;
use App\Support\Clock\Clock;
use App\Support\Validation\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Delegações de aprovação (RN-053): vigentes, futuras e encerradas, nova
 * delegação e revogação.
 *
 * O administrador (portal.approval_rules) vê e administra as delegações de
 * toda a empresa e delega por qualquer aprovador; o aprovador
 * (portal.approvals) vê as que deu e recebeu e delega as próprias
 * aprovações. A rota não fixa uma única permissão: o acesso é conferido aqui
 * em cada requisição e de novo pelas Actions.
 */
#[Layout('layouts.app')]
#[Title('Delegações de aprovação')]
final class ApprovalDelegationsIndex extends Component
{
    use ReadsCompanyApprovers;

    public const int MAX_ENDED = 50;

    public string $delegatorId = '';

    public string $delegateId = '';

    public string $firstDay = '';

    public string $lastDay = '';

    public string $reason = '';

    #[Locked]
    public ?string $message = null;

    #[Locked]
    public ?string $failure = null;

    public function mount(PortalBuyer $buyer, Clock $clock): void
    {
        [$actor, $manages] = self::viewer($buyer);

        $this->firstDay = $clock->today()->toDateString();
        $this->delegatorId = $manages ? '' : $actor->membership->id;
    }

    public function save(SaveApprovalDelegation $save, PortalBuyer $buyer, Clock $clock): void
    {
        $this->clearFeedback();
        [$actor, $manages] = self::viewer($buyer);

        if (! $manages) {
            $this->delegatorId = $actor->membership->id;
        }

        $this->validate([
            'delegatorId' => ['required', 'string', 'max:64'],
            'delegateId' => ['required', 'string', 'max:64'],
            'firstDay' => ['required', ...BusinessDate::rules()],
            'lastDay' => ['required', ...BusinessDate::rules()],
            'reason' => ['required', 'string', 'max:'.SaveApprovalDelegation::MAX_REASON_LENGTH],
        ], [], [
            'delegatorId' => 'titular',
            'delegateId' => 'delegado',
            'firstDay' => 'primeiro dia',
            'lastDay' => 'último dia',
            'reason' => 'motivo',
        ]);

        try {
            $delegation = $save->handle(new ApprovalDelegationData(
                delegatorMembershipId: $this->delegatorId,
                delegateMembershipId: $this->delegateId,
                firstDay: $this->firstDay,
                lastDay: $this->lastDay,
                reason: $this->reason,
            ));
        } catch (ApprovalNotAllowed $e) {
            $this->addViolation($e, [
                'delegator' => 'delegatorId',
                'delegate' => 'delegateId',
                'first_day' => 'firstDay',
                'last_day' => 'lastDay',
                'reason' => 'reason',
            ], 'delegateId');

            return;
        }

        $members = $this->companyMembers($actor->company->id);
        $period = DelegationPeriod::fromInstants($delegation->valid_from, $delegation->valid_until);

        $this->reset('delegateId', 'lastDay', 'reason');
        $this->firstDay = $clock->today()->toDateString();
        $this->delegatorId = $manages ? '' : $actor->membership->id;
        $this->message = 'Delegação de '.self::nameOf($members, $delegation->delegator_membership_id)
            .' para '.self::nameOf($members, $delegation->delegate_membership_id).' registrada: '.$period->label().'.';
    }

    public function revoke(string $delegationId, RevokeApprovalDelegation $revoke, PortalBuyer $buyer): void
    {
        $this->clearFeedback();
        [$actor] = self::viewer($buyer);

        try {
            $delegation = $revoke->handle($delegationId);
        } catch (ApprovalNotAllowed $e) {
            $this->failure = $e->getMessage();

            return;
        }

        $members = $this->companyMembers($actor->company->id);
        $this->message = 'Delegação de '.self::nameOf($members, $delegation->delegator_membership_id)
            .' para '.self::nameOf($members, $delegation->delegate_membership_id).' revogada.';
    }

    public function render(PortalBuyer $buyer, Clock $clock): View
    {
        [$actor, $manages] = self::viewer($buyer);
        $members = $this->companyMembers($actor->company->id);
        $eligible = self::eligibleOnly($members);
        $now = $clock->now();

        $open = $this->visible($actor, $manages)
            ->whereNull('revoked_at')
            ->where('valid_until', '>', $now)
            ->orderBy('valid_from')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $closed = $this->visible($actor, $manages)
            ->where(static fn (Builder $query): Builder => $query->whereNotNull('revoked_at')->orWhere('valid_until', '<=', $now))
            ->orderByDesc('valid_until')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ENDED)
            ->get();

        $entries = $this->entries($open->concat($closed), $members, $actor, $manages, $now);
        $own = $members[$actor->membership->id] ?? null;

        return view('livewire.portal.approvals.admin.approval-delegations-index', [
            'manages' => $manages,
            'active' => array_values(array_filter($entries, static fn (ApprovalDelegationEntry $e): bool => $e->state === ApprovalDelegationEntry::ACTIVE)),
            'scheduled' => array_values(array_filter($entries, static fn (ApprovalDelegationEntry $e): bool => $e->state === ApprovalDelegationEntry::SCHEDULED)),
            'ended' => array_values(array_filter($entries, static fn (ApprovalDelegationEntry $e): bool => ! $e->isOpen())),
            'delegatorOptions' => $manages ? self::labels($eligible) : [],
            'delegateOptions' => self::labels(array_filter($eligible, fn (ApproverOption $option): bool => $option->membershipId !== $this->delegatorId)),
            'ownName' => $own?->name,
            'canDelegate' => $manages || ($own !== null && $own->eligible),
            'maxDays' => SaveApprovalDelegation::MAX_DAYS,
            'maxEnded' => self::MAX_ENDED,
        ]);
    }

    /**
     * Comprador do contexto e se administra as regras e delegações da
     * empresa. Sem portal.approval_rules nem portal.approvals, recusa.
     *
     * @return array{0: BuyerActor, 1: bool}
     *
     * @throws AuthorizationException
     */
    private static function viewer(PortalBuyer $buyer): array
    {
        $actor = $buyer->require();
        $manages = $buyer->can(Permission::PortalApprovalRules);

        if (! $manages && ! $buyer->can(Permission::PortalApprovals)) {
            throw new AuthorizationException('Permissão necessária: '.Permission::PortalApprovals->label().'.');
        }

        return [$actor, $manages];
    }

    /**
     * Delegações da empresa visíveis: todas para o administrador; para o
     * aprovador, as que ele deu ou recebeu.
     *
     * @return Builder<ApprovalDelegation>
     */
    private function visible(BuyerActor $actor, bool $manages): Builder
    {
        $query = ApprovalDelegation::query()->where('buyer_company_id', $actor->company->id);

        if (! $manages) {
            $own = $actor->membership->id;
            $query->where(static fn (Builder $q): Builder => $q->where('delegator_membership_id', $own)->orWhere('delegate_membership_id', $own));
        }

        return $query;
    }

    /**
     * @param  Collection<int, ApprovalDelegation>  $delegations
     * @param  array<string, ApproverOption>  $members
     * @return list<ApprovalDelegationEntry>
     */
    private function entries(Collection $delegations, array $members, BuyerActor $actor, bool $manages, CarbonImmutable $now): array
    {
        $creators = User::query()
            ->whereIn('id', $delegations->pluck('created_by_user_id')->unique()->values()->all())
            ->pluck('name', 'id')
            ->all();

        $entries = [];

        foreach ($delegations as $delegation) {
            $state = ApprovalDelegationEntry::stateAt($now, $delegation->valid_from, $delegation->valid_until, $delegation->revoked_at);
            $open = $state === ApprovalDelegationEntry::ACTIVE || $state === ApprovalDelegationEntry::SCHEDULED;

            $entries[] = new ApprovalDelegationEntry(
                id: $delegation->id,
                delegatorName: self::nameOf($members, $delegation->delegator_membership_id),
                delegateName: self::nameOf($members, $delegation->delegate_membership_id),
                period: DelegationPeriod::fromInstants($delegation->valid_from, $delegation->valid_until),
                reason: $delegation->reason,
                createdByName: (string) ($creators[$delegation->created_by_user_id] ?? 'Usuário indisponível'),
                createdAt: $delegation->created_at ?? $now,
                revokedAt: $delegation->revoked_at,
                state: $state,
                canRevoke: $open && ($manages || $delegation->delegator_membership_id === $actor->membership->id),
            );
        }

        return $entries;
    }

    /**
     * @param  array<string, ApproverOption>  $options
     * @return array<string, string>
     */
    private static function labels(array $options): array
    {
        return array_map(static fn (ApproverOption $option): string => $option->label(), $options);
    }

    /**
     * @param  array<string, ApproverOption>  $members
     */
    private static function nameOf(array $members, string $membershipId): string
    {
        return isset($members[$membershipId]) ? $members[$membershipId]->name : 'Usuário indisponível';
    }

    private function clearFeedback(): void
    {
        $this->resetErrorBag();
        $this->message = null;
        $this->failure = null;
    }
}

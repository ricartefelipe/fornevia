<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\Approvals\ApprovalStepStatus;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\Permission;
use App\Models\Approvals\ApprovalDelegation;
use App\Models\Approvals\OrderApprovalDecision;
use App\Models\Approvals\OrderApprovalStep;
use App\Models\Identity\Membership;
use App\Models\Ordering\Order;
use App\Models\Quotes\Quote;
use App\Services\Approvals\ApprovalEligibility;
use App\Support\Clock\Clock;
use Illuminate\Database\Eloquent\Collection;

/**
 * Destinatários calculados no consumo, pelo estado atual (ADR-026): quem
 * perdeu a permissão ou a associação não é notificado. Leituras sem
 * bloqueio, no contexto de sistema do tenant.
 */
final readonly class NotificationRecipients
{
    public function __construct(
        private ApprovalEligibility $eligibility,
        private Clock $clock,
    ) {}

    /**
     * Associações ativas que ainda têm a permissão da tela do aviso.
     *
     * @param  list<string|null>  $ids
     * @return Collection<int, Membership>
     */
    public function memberships(array $ids, ?Permission $permission = null): Collection
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (?string $id): bool => $id !== null && $id !== '')));

        if ($ids === []) {
            return new Collection;
        }

        return $this->active()->whereKey($ids)->get()
            ->filter(static fn (Membership $m): bool => $permission === null || $m->role->grants($permission))
            ->values();
    }

    /**
     * Equipe com a permissão.
     *
     * @return Collection<int, Membership>
     */
    public function staffWith(Permission $permission): Collection
    {
        return $this->active()
            ->where('kind', MembershipKind::Staff->value)
            ->get()
            ->filter(static fn (Membership $m): bool => $m->role->grants($permission))
            ->values();
    }

    /**
     * Quem atende a cotação: o representante da carteira, se ainda pode
     * elaborar propostas; sem ele, toda a equipe que elabora propostas.
     *
     * @return Collection<int, Membership>
     */
    public function quoteTeam(Quote $quote): Collection
    {
        $answers = static fn (Membership $m): bool => $m->role->grants(Permission::QuotesManage) && $m->role->grants(Permission::QuotesView);
        $rep = $this->memberships([$quote->sales_rep_membership_id])->filter($answers);

        return ($rep->isNotEmpty() ? $rep : $this->staffWith(Permission::QuotesManage)->filter($answers))->values();
    }

    /**
     * Quem pode decidir a aprovação comercial: permissão de aprovar preços e
     * limite de desconto que cobre o maior desconto, exceto o autor.
     *
     * @return Collection<int, Membership>
     */
    public function commercialApprovers(int $maxDiscountBps, string $authorMembershipId): Collection
    {
        return $this->staffWith(Permission::PricingApprove)
            ->filter(static fn (Membership $m): bool => $m->id !== $authorMembershipId
                && $m->role->grants(Permission::QuotesView)
                && $m->role->max_discount_bps >= $maxDiscountBps)
            ->values();
    }

    /**
     * Quem pode decidir agora o nível pendente do pedido: aprovadores
     * listados ainda elegíveis e seus delegados vigentes, sem quem já
     * decidiu o nível nem quem fez o pedido.
     *
     * @return Collection<int, Membership>
     */
    public function pendingApprovers(Order $order): Collection
    {
        $step = OrderApprovalStep::query()
            ->where('order_id', $order->id)
            ->where('status', ApprovalStepStatus::Pending->value)
            ->orderBy('level_number')
            ->first();

        if ($step === null) {
            return new Collection;
        }

        /** @var list<string> $listed */
        $listed = array_values($step->approver_membership_ids);
        $eligible = $this->eligibility->eligible($order->buyer_company_id, $listed);
        $now = $this->clock->now();
        $decided = OrderApprovalDecision::query()
            ->where('order_approval_step_id', $step->id)
            ->whereNull('invalidated_at')
            ->get(['actor_membership_id', 'principal_membership_id'])
            ->flatMap(static fn (OrderApprovalDecision $d): array => [(string) $d->actor_membership_id, (string) $d->principal_membership_id])
            ->all();
        // Só quem ainda não decidiu pode delegar a decisão deste nível.
        $undecided = array_values(array_diff($eligible, $decided));
        $delegates = ApprovalDelegation::query()
            ->where('buyer_company_id', $order->buyer_company_id)
            ->whereIn('delegator_membership_id', $undecided)
            ->whereNull('revoked_at')
            ->where('valid_from', '<=', $now)
            ->where('valid_until', '>', $now)
            ->pluck('delegate_membership_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
        $candidates = array_diff(
            array_unique([...$undecided, ...$this->eligibility->eligible($order->buyer_company_id, array_values($delegates))]),
            $decided,
            [(string) $order->placed_by_membership_id],
        );

        return $this->memberships(array_values($candidates));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Membership>
     */
    private function active(): \Illuminate\Database\Eloquent\Builder
    {
        return Membership::query()->with(['role', 'user'])->where('status', MembershipStatus::Active->value);
    }
}

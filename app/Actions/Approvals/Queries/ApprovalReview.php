<?php

declare(strict_types=1);

namespace App\Actions\Approvals\Queries;

use App\Actions\Approvals\Support\OrderApprovalSummary;
use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Data\Ordering\Portal\BuyerActor;
use App\Domain\Shared\Money;
use App\Enums\Approvals\ApprovalDecision;
use App\Enums\Approvals\ApprovalStepStatus;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\ApprovalStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Approvals\OrderApprovalDecision;
use App\Models\Approvals\OrderApprovalStep;
use App\Models\Buyers\BuyerUnit;
use App\Models\Buyers\CostCenter;
use App\Models\Identity\Membership;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderEvent;
use App\Services\Approvals\ApprovalEligibility;
use App\Support\Http\UntrustedId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Detalhe de um pedido para quem aprova (RF-050, RN-050 a RN-056).
 *
 * Visível, com portal.approvals, a quem participa da aprovação do pedido:
 * aprovador retratado em algum nível, delegado vigente de um deles ou quem
 * já decidiu (inclusive em nome de outro). Com portal.approval_rules, todos
 * os pedidos da empresa que passaram por aprovação. Fora desse alcance, o
 * pedido equivale a inexistente.
 */
final readonly class ApprovalReview
{
    /** @var array<string, string> */
    private const array EVENT_LABELS = [
        'order.submitted_for_approval' => 'Enviado para aprovação interna',
        'order.approval_recorded' => 'Decisão registrada',
        'order.approval_level_approved' => 'Nível aprovado',
        'order.approval_rejected' => 'Rejeitado na aprovação interna',
        'order.approval_step_reassigned' => 'Aprovadores reatribuídos',
        'order.approvals_invalidated' => 'Aprovações invalidadas na reavaliação do pedido',
        'order.approved' => 'Aprovação interna concluída',
        'order.confirmation_pending' => 'Aprovado, ainda sem confirmação do distribuidor',
        'order.confirmed' => 'Pedido confirmado com o distribuidor',
        'order.items_cancelled' => 'Itens cancelados',
        'order.cancelled' => 'Pedido cancelado',
    ];

    public function __construct(
        private PortalBuyer $buyer,
        private PendingApprovals $pending,
        private ApprovalEligibility $eligibility,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function actor(): BuyerActor
    {
        return $this->buyer->require(Permission::PortalApprovals);
    }

    public function managesRules(): bool
    {
        return $this->buyer->can(Permission::PortalApprovalRules);
    }

    /**
     * @throws AuthorizationException
     */
    public function ruleManager(): BuyerActor
    {
        return $this->buyer->require(Permission::PortalApprovalRules);
    }

    /**
     * Pedido com itens (por linha) e eventos (em ordem cronológica).
     *
     * @throws ModelNotFoundException
     */
    public function find(BuyerActor $actor, string $orderId): Order
    {
        $query = Order::query()
            ->where('buyer_company_id', $actor->company->id)
            ->where('approval_status', '<>', ApprovalStatus::NotRequired->value)
            ->whereKey(UntrustedId::uuid($orderId, Order::class));

        if (! $this->managesRules()) {
            $actorId = $actor->membership->id;
            $listed = [$actorId, ...$this->pending->delegators($actor)];

            $query->where(static function (Builder $involved) use ($actorId, $listed): void {
                $involved
                    ->whereExists(static function (QueryBuilder $steps) use ($listed): void {
                        $steps->selectRaw('1')
                            ->from('order_approval_steps')
                            ->whereColumn('order_approval_steps.order_id', 'orders.id')
                            ->where(static function (QueryBuilder $any) use ($listed): void {
                                foreach ($listed as $membershipId) {
                                    $any->orWhereJsonContains('order_approval_steps.approver_membership_ids', $membershipId);
                                }
                            });
                    })
                    ->orWhereExists(static function (QueryBuilder $decisions) use ($actorId): void {
                        $decisions->selectRaw('1')
                            ->from('order_approval_decisions')
                            ->whereColumn('order_approval_decisions.order_id', 'orders.id')
                            ->where(static fn (QueryBuilder $mine): QueryBuilder => $mine
                                ->where('order_approval_decisions.actor_membership_id', $actorId)
                                ->orWhere('order_approval_decisions.principal_membership_id', $actorId));
                    });
            });
        }

        return $query
            ->with([
                'items' => static fn (HasMany $items): HasMany => $items->orderBy('line_number'),
                'events' => static fn (HasMany $events): HasMany => $events->orderBy('created_at')->orderBy('id'),
            ])
            ->firstOrFail();
    }

    public function review(BuyerActor $actor, Order $order): ApprovalReviewData
    {
        $requesterId = (string) $order->placed_by_membership_id;
        $steps = OrderApprovalSummary::orderedSteps($order);
        $decisions = OrderApprovalDecision::query()->where('order_id', $order->id)->orderBy('created_at')->orderBy('id')->get();

        $current = self::awaitingApproval($order) ? ($this->pending->evaluate($actor, [$order])[$order->id] ?? null) : null;
        $canReassign = $current !== null && $current->blocked && $this->managesRules();
        $candidates = $canReassign ? $this->candidates($actor, $current) : [];

        $listed = array_values(array_unique(array_merge(...array_map(
            static fn (OrderApprovalStep $step): array => array_values($step->approver_membership_ids),
            $steps->all(),
        ))));
        $eligible = $this->eligibility->eligible($order->buyer_company_id, $listed);
        $names = MemberNames::ofMemberships([
            ...$listed,
            ...$decisions->map(static fn (OrderApprovalDecision $d): string => $d->actor_membership_id)->all(),
            ...$decisions->map(static fn (OrderApprovalDecision $d): string => $d->principal_membership_id)->all(),
        ]);
        $users = MemberNames::ofUsers([
            $order->placed_by_user_id,
            ...$order->events->map(static fn (OrderEvent $event): ?string => $event->actor_user_id)->all(),
        ]);

        /** @var array<string, list<OrderApprovalDecision>> $byStep */
        $byStep = [];

        foreach ($decisions as $decision) {
            $byStep[$decision->order_approval_step_id][] = $decision;
        }

        $reviews = [];

        foreach ($steps as $step) {
            $all = $byStep[$step->id] ?? [];
            $valid = array_values(array_filter($all, static fn (OrderApprovalDecision $d): bool => $d->invalidated_at === null));
            $isCurrent = $current !== null && $current->step->id === $step->id;

            $reviews[] = new StepReview(
                $step,
                count(PendingApprovals::view($step, $valid)->approvedPrincipals($requesterId)),
                $isCurrent,
                $isCurrent && $current->blocked,
                array_map(static function (string $id) use ($step, $valid, $requesterId, $eligible, $names): array {
                    [$situation, $color] = self::situation($id, $step, $valid, $requesterId, in_array($id, $eligible, true), $names);

                    return ['id' => $id, 'name' => $names[$id] ?? 'Usuário sem nome', 'situation' => $situation, 'color' => $color];
                }, array_values($step->approver_membership_ids)),
                array_map(static fn (OrderApprovalDecision $d): array => [
                    'id' => $d->id,
                    'actor' => $names[$d->actor_membership_id] ?? 'Usuário sem nome',
                    'principal' => $d->principal_membership_id !== $d->actor_membership_id ? ($names[$d->principal_membership_id] ?? 'Usuário sem nome') : null,
                    'decision' => $d->decision,
                    'comment' => $d->comment,
                    'at' => $d->created_at,
                    'invalidatedAt' => $d->invalidated_at,
                ], $all),
            );
        }

        $delegatedFrom = $current?->delegatedFrom($actor->membership->id);

        return new ApprovalReviewData(
            order: $order,
            requesterName: $users[$order->placed_by_user_id] ?? null,
            costCenterName: $order->cost_center_id !== null ? CostCenter::query()->whereKey($order->cost_center_id)->value('name') : null,
            unitName: $order->buyer_unit_id !== null ? BuyerUnit::query()->whereKey($order->buyer_unit_id)->value('name') : null,
            steps: $reviews,
            current: $current,
            principalName: $delegatedFrom !== null ? (MemberNames::ofMemberships([$delegatedFrom])[$delegatedFrom] ?? null) : null,
            canReassign: $canReassign,
            candidates: $candidates,
            suggestedApprovers: $current !== null && $canReassign ? array_values(array_intersect($current->remainingApprovers(), array_keys($candidates))) : [],
            history: array_values($order->events->map(static fn (OrderEvent $event): array => [
                'id' => $event->id,
                'label' => self::EVENT_LABELS[$event->type] ?? 'Atualização do pedido',
                'at' => $event->created_at,
                'actor' => $event->actor_user_id !== null ? ($users[$event->actor_user_id] ?? null) : null,
                'details' => self::eventDetails($event),
            ])->all()),
        );
    }

    public static function awaitingApproval(Order $order): bool
    {
        return $order->commercial_status === CommercialStatus::PendingApproval && $order->approval_status === ApprovalStatus::Pending;
    }

    /**
     * Associações ativas da empresa com permissão de aprovar, exceto o
     * solicitante e quem já decidiu no nível, em ordem de nome.
     *
     * @return array<string, string> associação => nome
     */
    private function candidates(BuyerActor $actor, PendingStepState $state): array
    {
        $active = array_values(Membership::query()
            ->where('buyer_company_id', $actor->company->id)
            ->where('status', MembershipStatus::Active->value)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all());
        $ids = array_values(array_filter(
            $this->eligibility->eligible($actor->company->id, $active),
            static fn (string $id): bool => $id !== $state->requesterId && ! $state->view->hasDecided($id),
        ));
        $names = MemberNames::ofMemberships($ids);
        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * Situação de um aprovador retratado no nível.
     *
     * @param  list<OrderApprovalDecision>  $valid  decisões válidas do nível
     * @param  array<string, string>  $names
     * @return array{0: string, 1: string} rótulo e cor
     */
    private static function situation(string $id, OrderApprovalStep $step, array $valid, string $requesterId, bool $eligible, array $names): array
    {
        foreach ($valid as $decision) {
            if ($decision->principal_membership_id === $id) {
                $approved = $decision->decision === ApprovalDecision::Approved;
                $label = $approved ? 'Aprovou' : 'Rejeitou';

                if ($decision->actor_membership_id !== $id) {
                    $label .= ' (por '.($names[$decision->actor_membership_id] ?? 'delegado').', por delegação)';
                }

                return [$label, $approved ? 'green' : 'red'];
            }

            if ($decision->actor_membership_id === $id) {
                return ['Decidiu em nome de '.($names[$decision->principal_membership_id] ?? 'outro aprovador'), 'slate'];
            }
        }

        return match (true) {
            $id === $requesterId => ['Solicitante: não conta para o quórum', 'slate'],
            $step->status === ApprovalStepStatus::Superseded => ['Nível substituído na reavaliação', 'slate'],
            $step->status === ApprovalStepStatus::Cancelled => ['Nível encerrado sem decisão', 'slate'],
            ! $eligible => ['Sem acesso para aprovar', 'red'],
            $step->status === ApprovalStepStatus::Pending => ['Aguardando decisão', 'amber'],
            default => ['Não precisou decidir', 'slate'],
        };
    }

    /**
     * @return list<string>
     */
    private static function eventDetails(OrderEvent $event): array
    {
        $data = $event->data;
        $details = [];

        if (isset($data['level']) && is_int($data['level'])) {
            $details[] = 'Nível '.$data['level'];
        }

        if (isset($data['rule']) && is_string($data['rule']) && $data['rule'] !== '') {
            $details[] = 'Regra: '.$data['rule'];
        }

        foreach (['previous_total_cents' => 'Total anterior', 'total_cents' => 'Total', 'value_cents' => 'Valor cancelado'] as $key => $label) {
            if (isset($data[$key]) && is_int($data[$key])) {
                $details[] = $label.': '.Money::ofCents($data[$key])->format();
            }
        }

        if (isset($data['reason']) && is_string($data['reason']) && $data['reason'] !== '') {
            $details[] = 'Motivo: '.$data['reason'];
        }

        $reasons = OrderApprovalSummary::invalidationReasons($data);

        if ($reasons !== null) {
            $details[] = $reasons;
        }

        return $details;
    }
}

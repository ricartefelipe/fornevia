<?php

declare(strict_types=1);

namespace App\Actions\Approvals\Queries;

use App\Data\Ordering\Portal\BuyerActor;
use App\Domain\Approvals\ApprovalFlow;
use App\Domain\Approvals\ApprovalStepView;
use App\Domain\Approvals\Exceptions\ApprovalNotAllowed;
use App\Enums\Approvals\ApprovalDecision;
use App\Enums\Approvals\ApprovalStepStatus;
use App\Enums\Ordering\ApprovalStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Approvals\OrderApprovalDecision;
use App\Models\Approvals\OrderApprovalStep;
use App\Models\Ordering\Order;
use App\Services\Approvals\ApprovalEligibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Leitura, sem bloqueio, do nível pendente dos pedidos aguardando
 * aprovação, avaliado com as mesmas regras da decisão (ApprovalFlow e
 * ApprovalEligibility): em nome de quem o ator decidiria agora e se o
 * quórum ainda pode ser atingido. Níveis, decisões válidas e elegibilidade
 * são lidos em lote (a leitura de ApprovalSteps::view, sem uma consulta
 * por pedido). A decisão em si confere tudo de novo sob lock.
 */
final readonly class PendingApprovals
{
    public function __construct(private ApprovalEligibility $eligibility) {}

    /**
     * Pedidos da empresa do ator aguardando aprovação interna.
     *
     * @return Builder<Order>
     */
    public function orders(BuyerActor $actor): Builder
    {
        return Order::query()
            ->where('buyer_company_id', $actor->company->id)
            ->where('commercial_status', CommercialStatus::PendingApproval->value)
            ->where('approval_status', ApprovalStatus::Pending->value);
    }

    /**
     * Candidatos à decisão do ator: pedidos que não são dele com algum
     * nível pendente que lista o ator ou um titular com delegação vigente
     * para ele. A avaliação confirma quem pode decidir o nível atual.
     *
     * @return Builder<Order>
     */
    public function candidates(BuyerActor $actor): Builder
    {
        $actorId = $actor->membership->id;
        $listed = [$actorId, ...$this->delegators($actor)];

        return $this->orders($actor)
            ->where(static fn (Builder $query): Builder => $query->whereNull('placed_by_membership_id')->orWhere('placed_by_membership_id', '<>', $actorId))
            ->whereExists(static function (QueryBuilder $steps) use ($listed): void {
                $steps->selectRaw('1')
                    ->from('order_approval_steps')
                    ->whereColumn('order_approval_steps.order_id', 'orders.id')
                    ->where('order_approval_steps.status', ApprovalStepStatus::Pending->value)
                    ->where(static function (QueryBuilder $any) use ($listed): void {
                        foreach ($listed as $membershipId) {
                            $any->orWhereJsonContains('order_approval_steps.approver_membership_ids', $membershipId);
                        }
                    });
            });
    }

    /**
     * Titulares com delegação vigente para o ator (ordem de criação).
     *
     * @return list<string>
     */
    public function delegators(BuyerActor $actor): array
    {
        return array_keys($this->eligibility->delegatorsOf($actor->company->id, $actor->membership->id));
    }

    /**
     * Situação do nível pendente (o de menor número) de cada pedido.
     * Pedidos sem nível pendente ficam de fora.
     *
     * @param  iterable<Order>  $orders
     * @return array<string, PendingStepState> pedido => situação
     */
    public function evaluate(BuyerActor $actor, iterable $orders): array
    {
        $requesters = [];

        foreach ($orders as $order) {
            $requesters[$order->id] = (string) $order->placed_by_membership_id;
        }

        if ($requesters === []) {
            return [];
        }

        /** @var array<string, OrderApprovalStep> $current */
        $current = [];

        foreach (OrderApprovalStep::query()
            ->whereIn('order_id', array_keys($requesters))
            ->where('status', ApprovalStepStatus::Pending->value)
            ->orderBy('level_number')
            ->get() as $step) {
            $current[$step->order_id] ??= $step;
        }

        if ($current === []) {
            return [];
        }

        $decisions = $this->validDecisions(array_map(static fn (OrderApprovalStep $step): string => $step->id, array_values($current)));
        $listed = array_values(array_unique(array_merge(...array_map(
            static fn (OrderApprovalStep $step): array => array_values($step->approver_membership_ids),
            array_values($current),
        ))));
        $eligible = $this->eligibility->eligible($actor->company->id, $listed);
        $delegators = $this->delegators($actor);
        $actorId = $actor->membership->id;

        $states = [];

        foreach ($requesters as $orderId => $requesterId) {
            $step = $current[$orderId] ?? null;

            if ($step === null) {
                continue;
            }

            $view = self::view($step, $decisions[$step->id] ?? []);
            $stepEligible = array_values(array_intersect(array_values($step->approver_membership_ids), $eligible));
            $principal = null;
            $reason = null;

            try {
                $principal = ApprovalFlow::principalFor($view, $actorId, $requesterId, $stepEligible, $delegators);
            } catch (ApprovalNotAllowed $e) {
                $reason = $e->getMessage();
            }

            $states[$orderId] = new PendingStepState(
                $step,
                $view,
                $requesterId,
                $stepEligible,
                $principal,
                $reason,
                ApprovalFlow::isBlocked($view, $requesterId, $stepEligible),
            );
        }

        return $states;
    }

    /**
     * Nível com as decisões válidas informadas, para as regras do domínio.
     *
     * @param  list<OrderApprovalDecision>  $decisions  decisões válidas do nível, em ordem cronológica
     */
    public static function view(OrderApprovalStep $step, array $decisions): ApprovalStepView
    {
        return new ApprovalStepView(
            $step->id,
            $step->level_number,
            $step->required_approvals,
            array_values($step->approver_membership_ids),
            array_map(static fn (OrderApprovalDecision $decision): array => [
                'actor' => $decision->actor_membership_id,
                'principal' => $decision->principal_membership_id,
                'approved' => $decision->decision === ApprovalDecision::Approved,
            ], $decisions),
        );
    }

    /**
     * @param  list<string>  $stepIds
     * @return array<string, list<OrderApprovalDecision>> nível => decisões válidas
     */
    private function validDecisions(array $stepIds): array
    {
        $byStep = [];

        foreach (OrderApprovalDecision::query()
            ->whereIn('order_approval_step_id', $stepIds)
            ->whereNull('invalidated_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get() as $decision) {
            $byStep[$decision->order_approval_step_id][] = $decision;
        }

        return $byStep;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Actions\Approvals\Support\ApprovalSteps;
use App\Domain\Approvals\ApprovalFlow;
use App\Domain\Approvals\Exceptions\ApprovalNotAllowed;
use App\Domain\Approvals\Exceptions\OrderNotAwaitingApproval;
use App\Enums\Approvals\ApprovalDecision;
use App\Enums\Approvals\ApprovalOutcome;
use App\Enums\Approvals\ApprovalStepStatus;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\ApprovalStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Approvals\OrderApprovalDecision;
use App\Models\Ordering\Order;
use App\Services\Approvals\ApprovalEligibility;
use App\Services\Approvals\ApprovedOrderFinalizer;
use App\Services\Approvals\BudgetLedger;
use App\Services\Audit\AuditRecorder;
use App\Services\Ordering\OrderConfirmation;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Aprovação ou rejeição do nível pendente de um pedido (RF-050, RN-050 a
 * RN-053).
 *
 * - só no contexto de comprador da própria empresa, com portal.approvals;
 * - o pedido é bloqueado antes de decidir: decisões simultâneas no mesmo
 *   pedido são serializadas e uma única confirmação acontece;
 * - rejeição exige motivo, encerra o pedido e libera o orçamento;
 * - quórum atingido no último nível conclui a aprovação e tenta confirmar
 *   o pedido com o distribuidor (ApprovedOrderFinalizer).
 */
final readonly class DecideOrderApproval
{
    public function __construct(
        private Access $access,
        private ApprovalEligibility $eligibility,
        private ApprovedOrderFinalizer $finalizer,
        private BudgetLedger $budgets,
        private OrderConfirmation $confirmation,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(Order $order, ApprovalDecision $decision, ?string $comment = null): ApprovalOutcome
    {
        $this->access->authorize(Permission::PortalApprovals);

        if (! $this->access->isBuyer() || $order->buyer_company_id !== $this->access->buyerCompanyId()) {
            throw new AuthorizationException('Sem permissão para decidir este pedido.');
        }

        $comment = $comment !== null && trim($comment) !== '' ? trim($comment) : null;

        if ($decision === ApprovalDecision::Rejected && mb_strlen((string) $comment) < 5) {
            throw new ApprovalNotAllowed('Informe o motivo da rejeição (ao menos 5 caracteres).', ['field' => 'comment']);
        }

        if ($comment !== null && mb_strlen($comment) > 1000) {
            throw new ApprovalNotAllowed('O comentário deve ter até 1.000 caracteres.', ['field' => 'comment']);
        }

        return DB::transaction(function () use ($order, $decision, $comment): ApprovalOutcome {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->commercial_status !== CommercialStatus::PendingApproval || $locked->approval_status !== ApprovalStatus::Pending) {
                throw new OrderNotAwaitingApproval('Este pedido não está aguardando aprovação.');
            }

            $step = ApprovalSteps::current($locked) ?? throw new OrderNotAwaitingApproval('Este pedido não tem nível de aprovação pendente.');
            $actorId = $this->access->membership()->id;
            $requesterId = (string) $locked->placed_by_membership_id;
            $delegators = $this->eligibility->delegatorsOf($locked->buyer_company_id, $actorId);

            $principalId = ApprovalFlow::principalFor(
                ApprovalSteps::view($step),
                $actorId,
                $requesterId,
                $this->eligibility->eligible($locked->buyer_company_id, $step->approver_membership_ids),
                array_keys($delegators),
            );

            (new OrderApprovalDecision)->forceFill([
                'buyer_company_id' => $locked->buyer_company_id,
                'order_id' => $locked->id,
                'order_approval_step_id' => $step->id,
                'actor_membership_id' => $actorId,
                'principal_membership_id' => $principalId,
                'decision' => $decision,
                'comment' => $comment,
                'delegation_id' => $principalId !== $actorId ? $delegators[$principalId] : null,
            ])->save();

            $this->audit->record('order.approval_decided', $locked, [
                'level' => $step->level_number,
                'decision' => $decision->value,
                'principal_membership_id' => $principalId,
                'delegated' => $principalId !== $actorId,
            ], $comment);

            $view = ApprovalSteps::view($step);
            $now = $this->clock->now();

            if ($view->isRejected()) {
                $step->forceFill(['status' => ApprovalStepStatus::Rejected, 'decided_at' => $now])->save();
                $locked->forceFill([
                    'commercial_status' => CommercialStatus::Rejected,
                    'approval_status' => ApprovalStatus::Rejected,
                    'cancel_reason' => $comment,
                ])->save();
                $this->budgets->release($locked);
                $this->confirmation->event($locked, 'order.approval_rejected', ['level' => $step->level_number, 'reason' => $comment]);

                return ApprovalOutcome::Rejected;
            }

            if (! $view->isApproved($requesterId)) {
                $this->confirmation->event($locked, 'order.approval_recorded', ['level' => $step->level_number]);

                return ApprovalOutcome::Pending;
            }

            $step->forceFill(['status' => ApprovalStepStatus::Approved, 'decided_at' => $now])->save();

            if (ApprovalSteps::current($locked) !== null) {
                $this->confirmation->event($locked, 'order.approval_level_approved', ['level' => $step->level_number]);

                return ApprovalOutcome::Pending;
            }

            $locked->forceFill([
                'approval_status' => ApprovalStatus::Approved,
                'commercial_status' => CommercialStatus::Approved,
                'approved_at' => $now,
            ])->save();
            $this->confirmation->event($locked, 'order.approved', ['levels' => $step->level_number]);

            return $this->finalizer->finalize($locked);
        });
    }
}

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
use App\Models\Approvals\OrderApprovalStep;
use App\Models\Ordering\Order;
use App\Services\Approvals\ApprovalEligibility;
use App\Services\Approvals\ApprovedOrderFinalizer;
use App\Services\Approvals\BudgetLedger;
use App\Services\Audit\AuditRecorder;
use App\Services\Ordering\OrderConfirmation;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Aprovação ou rejeição do nível pendente de um pedido (RF-050, RN-050 a
 * RN-053).
 *
 * - só no contexto de comprador da própria empresa, com portal.approvals;
 *   a gravação roda no contexto de sistema do mesmo usuário (ADR-014);
 * - o pedido é bloqueado antes de decidir: decisões simultâneas no mesmo
 *   pedido, cancelamento, nova tentativa e reatribuição são serializados e
 *   uma única confirmação acontece;
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
        private TenantDatabase $db,
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

        $actorId = $this->access->membership()->id;

        return DB::transaction(function () use ($order, $decision, $comment, $actorId): ApprovalOutcome {
            // Pedido, níveis e decisões são somente leitura no contexto de
            // comprador: a decisão é gravada no contexto de sistema do mesmo
            // usuário, depois da autorização acima (ADR-014).
            [$outcome, $recorded] = $this->db->asSystem(fn (): array => $this->decide($order, $decision, $comment, $actorId));

            $this->audit->record('order.approval_decided', $order, $recorded, $comment);

            return $outcome;
        });
    }

    /**
     * @return array{0: ApprovalOutcome, 1: array{level: int, decision: string, principal_membership_id: string, delegated: bool}}
     */
    private function decide(Order $order, ApprovalDecision $decision, ?string $comment, string $actorId): array
    {
        $locked = Order::query()
            ->where('buyer_company_id', $order->buyer_company_id)
            ->lockForUpdate()
            ->findOrFail($order->id);

        if ($locked->commercial_status !== CommercialStatus::PendingApproval || $locked->approval_status !== ApprovalStatus::Pending) {
            throw new OrderNotAwaitingApproval('Este pedido não está aguardando aprovação.');
        }

        $step = ApprovalSteps::current($locked) ?? throw new OrderNotAwaitingApproval('Este pedido não tem nível de aprovação pendente.');
        $requesterId = (string) $locked->placed_by_membership_id;
        $delegators = $this->eligibility->delegatorsOf($locked->buyer_company_id, $actorId, lock: true);

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

        $recorded = [
            'level' => $step->level_number,
            'decision' => $decision->value,
            'principal_membership_id' => $principalId,
            'delegated' => $principalId !== $actorId,
        ];

        return [$this->advance($locked, $step, $requesterId, $comment), $recorded];
    }

    private function advance(Order $locked, OrderApprovalStep $step, string $requesterId, ?string $comment): ApprovalOutcome
    {
        $view = ApprovalSteps::view($step);
        $now = $this->clock->now();

        if ($view->isRejected()) {
            $step->forceFill(['status' => ApprovalStepStatus::Rejected, 'decided_at' => $now])->save();
            // Os níveis seguintes deixam de aguardar decisão.
            OrderApprovalStep::query()
                ->where('order_id', $locked->id)
                ->where('status', ApprovalStepStatus::Pending->value)
                ->update(['status' => ApprovalStepStatus::Cancelled->value, 'decided_at' => $now, 'updated_at' => $now]);
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
    }
}

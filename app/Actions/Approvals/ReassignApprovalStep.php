<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Actions\Approvals\Support\ApprovalSteps;
use App\Domain\Approvals\ApprovalFlow;
use App\Domain\Approvals\Exceptions\ApprovalNotAllowed;
use App\Domain\Approvals\Exceptions\ApprovalOutdated;
use App\Domain\Approvals\Exceptions\OrderNotAwaitingApproval;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\ApprovalStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Identity\Membership;
use App\Models\Ordering\Order;
use App\Services\Approvals\ApprovalEligibility;
use App\Services\Audit\AuditRecorder;
use App\Services\Ordering\OrderConfirmation;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Reatribui os aprovadores do nível pendente bloqueado de um pedido
 * (RN-052): aprovadores perderam o acesso e o quórum ficou inalcançável.
 * Aprovações já registradas permanecem; os novos aprovadores precisam ser
 * elegíveis e diferentes do solicitante. Exige portal.approval_rules.
 */
final readonly class ReassignApprovalStep
{
    public function __construct(
        private Access $access,
        private ApprovalEligibility $eligibility,
        private OrderConfirmation $confirmation,
        private AuditRecorder $audit,
        private TenantDatabase $db,
    ) {}

    /**
     * @param  list<string>  $approverIds
     * @param  string  $reviewedStepId  nível bloqueado exibido ao administrador
     * @return int número do nível reatribuído
     */
    public function handle(Order $order, array $approverIds, string $reason, string $reviewedStepId): int
    {
        $this->access->authorize(Permission::PortalApprovalRules);

        if (! $this->access->isBuyer() || $order->buyer_company_id !== $this->access->buyerCompanyId()) {
            throw new AuthorizationException('Sem permissão para alterar os aprovadores deste pedido.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new ApprovalNotAllowed('Informe o motivo da reatribuição (de 5 a 500 caracteres).', ['field' => 'reason']);
        }

        $ids = array_values(array_unique(array_map(static fn (mixed $id): string => UntrustedId::uuid($id, Membership::class), $approverIds)));

        return DB::transaction(function () use ($order, $ids, $reason, $reviewedStepId): int {
            // Níveis e eventos são somente leitura no contexto de comprador
            // (ADR-014); a auditoria fica no contexto de quem reatribuiu.
            $audit = $this->db->asSystem(fn (): array => $this->reassign($order, $ids, $reviewedStepId));

            $this->audit->record('order.approval_step_reassigned', $order, $audit, $reason);

            return $audit['level'];
        });
    }

    /**
     * @param  list<string>  $ids
     * @return array{level: int, before: list<string>, after: list<string>}
     */
    private function reassign(Order $order, array $ids, string $reviewedStepId): array
    {
        $locked = Order::query()
            ->where('buyer_company_id', $order->buyer_company_id)
            ->lockForUpdate()
            ->findOrFail($order->id);

        if ($locked->commercial_status !== CommercialStatus::PendingApproval || $locked->approval_status !== ApprovalStatus::Pending) {
            throw new OrderNotAwaitingApproval('Este pedido não está aguardando aprovação.');
        }

        $step = ApprovalSteps::current($locked) ?? throw new OrderNotAwaitingApproval('Este pedido não tem nível de aprovação pendente.');

        if ($step->id !== $reviewedStepId) {
            throw new ApprovalOutdated('Outro nível entrou em análise desde que você abriu esta página. Revise o pedido antes de reatribuir.');
        }

        $requesterId = (string) $locked->placed_by_membership_id;

        // Só níveis bloqueados (RN-052): com quórum alcançável, os
        // aprovadores retratados na submissão continuam valendo.
        if (! ApprovalFlow::isBlocked(ApprovalSteps::view($step), $requesterId, $this->eligibility->eligible($locked->buyer_company_id, $step->approver_membership_ids))) {
            throw new ApprovalNotAllowed('O nível pendente ainda tem aprovadores suficientes; a reatribuição é apenas para níveis bloqueados.', ['field' => 'approvers']);
        }

        $eligible = $this->eligibility->eligible($locked->buyer_company_id, $ids);

        if (count($eligible) !== count($ids) || in_array($requesterId, $ids, true)) {
            throw new ApprovalNotAllowed('Escolha aprovadores ativos da empresa, com permissão de aprovar, diferentes do solicitante.', ['field' => 'approvers']);
        }

        $view = ApprovalSteps::view($step);
        $approved = $view->approvedPrincipals($requesterId);
        $newApprovers = array_values(array_unique([...$approved, ...$ids]));
        $remaining = count(array_filter($ids, static fn (string $id): bool => ! $view->hasDecided($id)));

        if (count($approved) + $remaining < $step->required_approvals) {
            throw new ApprovalNotAllowed("O nível exige {$step->required_approvals} aprovações: escolha aprovadores suficientes.", ['field' => 'approvers']);
        }

        $previous = array_values($step->approver_membership_ids);
        $step->forceFill(['approver_membership_ids' => $newApprovers])->save();
        $this->confirmation->event($locked, 'order.approval_step_reassigned', ['level' => $step->level_number]);

        return ['level' => $step->level_number, 'before' => $previous, 'after' => $newApprovers];
    }
}

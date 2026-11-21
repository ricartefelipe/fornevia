<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Actions\Approvals\Support\ApprovalSteps;
use App\Domain\Approvals\Exceptions\ApprovalNotAllowed;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Reatribui os aprovadores do nível pendente de um pedido (RN-052): usado
 * quando aprovadores perderam o acesso e o quórum ficou inalcançável.
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
    ) {}

    /**
     * @param  list<string>  $approverIds
     */
    public function handle(Order $order, array $approverIds, string $reason): void
    {
        $this->access->authorize(Permission::PortalApprovalRules);

        if (! $this->access->isBuyer() || $order->buyer_company_id !== $this->access->buyerCompanyId()) {
            throw new AuthorizationException('Sem permissão para alterar os aprovadores deste pedido.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw new ApprovalNotAllowed('Informe o motivo da reatribuição (ao menos 5 caracteres).', ['field' => 'reason']);
        }

        $ids = array_values(array_unique(array_map(static fn (mixed $id): string => UntrustedId::uuid($id, Membership::class), $approverIds)));

        DB::transaction(function () use ($order, $ids, $reason): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->commercial_status !== CommercialStatus::PendingApproval || $locked->approval_status !== ApprovalStatus::Pending) {
                throw new OrderNotAwaitingApproval('Este pedido não está aguardando aprovação.');
            }

            $step = ApprovalSteps::current($locked) ?? throw new OrderNotAwaitingApproval('Este pedido não tem nível de aprovação pendente.');
            $requesterId = (string) $locked->placed_by_membership_id;
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

            $previous = $step->approver_membership_ids;
            $step->forceFill(['approver_membership_ids' => $newApprovers])->save();

            $this->audit->record('order.approval_step_reassigned', $locked, [
                'level' => $step->level_number,
                'before' => $previous,
                'after' => $newApprovers,
            ], $reason);
            $this->confirmation->event($locked, 'order.approval_step_reassigned', ['level' => $step->level_number]);
        });
    }
}

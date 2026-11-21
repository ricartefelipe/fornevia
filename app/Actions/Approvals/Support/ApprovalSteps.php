<?php

declare(strict_types=1);

namespace App\Actions\Approvals\Support;

use App\Domain\Approvals\ApprovalStepView;
use App\Enums\Approvals\ApprovalDecision;
use App\Enums\Approvals\ApprovalStepStatus;
use App\Models\Approvals\OrderApprovalDecision;
use App\Models\Approvals\OrderApprovalStep;
use App\Models\Ordering\Order;

/**
 * Leitura dos níveis de aprovação de um pedido para o domínio.
 */
final class ApprovalSteps
{
    /**
     * Nível pendente de menor número (bloqueado para atualização). Consulta
     * o banco a cada chamada: o resultado muda depois de aprovar um nível.
     *
     * @phpstan-impure
     */
    public static function current(Order $order): ?OrderApprovalStep
    {
        return OrderApprovalStep::query()
            ->where('order_id', $order->id)
            ->where('status', ApprovalStepStatus::Pending->value)
            ->orderBy('level_number')
            ->lockForUpdate()
            ->first();
    }

    public static function view(OrderApprovalStep $step): ApprovalStepView
    {
        $decisions = OrderApprovalDecision::query()
            ->where('order_approval_step_id', $step->id)
            ->whereNull('invalidated_at')
            ->orderBy('created_at')
            ->get()
            ->map(static fn (OrderApprovalDecision $d): array => [
                'actor' => $d->actor_membership_id,
                'principal' => $d->principal_membership_id,
                'approved' => $d->decision === ApprovalDecision::Approved,
            ])
            ->all();

        return new ApprovalStepView(
            $step->id,
            $step->level_number,
            $step->required_approvals,
            array_values($step->approver_membership_ids),
            array_values($decisions),
        );
    }
}

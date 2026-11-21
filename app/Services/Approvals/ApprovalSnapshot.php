<?php

declare(strict_types=1);

namespace App\Services\Approvals;

use App\Enums\Approvals\ApprovalStepStatus;
use App\Enums\Ordering\ApprovalStatus;
use App\Models\Approvals\ApprovalRule;
use App\Models\Approvals\OrderApprovalStep;
use App\Models\Ordering\Order;

/**
 * Retrato da regra de aprovação no pedido (RF-050): nome da regra,
 * impressão do que é aprovado e níveis com quórum e aprovadores. Editar a
 * regra depois não muda pedidos já submetidos. Na submissão e na
 * reavaliação que troca de regra (RN-054); roda no contexto de sistema.
 */
final class ApprovalSnapshot
{
    public static function take(Order $order, ApprovalRule $rule): void
    {
        $order->forceFill([
            'approval_status' => ApprovalStatus::Pending,
            'approval_rule_id' => $rule->id,
            'approval_rule_name' => $rule->name,
            'approval_fingerprint' => ApprovalFingerprint::of($order),
        ])->save();

        foreach ($rule->levels as $level) {
            (new OrderApprovalStep)->forceFill([
                'buyer_company_id' => $order->buyer_company_id,
                'order_id' => $order->id,
                'level_number' => $level->level_number,
                'required_approvals' => $level->required_approvals,
                // Ordem determinística: o retrato não depende da ordem de leitura.
                'approver_membership_ids' => $level->approvers->pluck('membership_id')->map(static fn (mixed $id): string => (string) $id)->sort()->values()->all(),
                'status' => ApprovalStepStatus::Pending,
            ])->save();
        }
    }
}

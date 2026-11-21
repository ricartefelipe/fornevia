<?php

declare(strict_types=1);

namespace App\Services\Approvals;

use App\Domain\Approvals\ApprovalContext;
use App\Domain\Approvals\ApprovalCriteria;
use App\Models\Approvals\ApprovalRule;

/**
 * Regra de aprovação aplicável a um pedido: a regra ativa de maior
 * prioridade (empate: a criada primeiro) cujos critérios são atendidos.
 */
final class ApprovalRuleResolver
{
    public function resolve(string $buyerCompanyId, ApprovalContext $context): ?ApprovalRule
    {
        $rules = ApprovalRule::query()
            ->with('levels.approvers')
            ->where('buyer_company_id', $buyerCompanyId)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            $criteria = new ApprovalCriteria(
                minTotal: $rule->min_total_cents,
                costCenterId: $rule->cost_center_id,
                buyerUnitId: $rule->buyer_unit_id,
                categoryId: $rule->category_id,
                requesterRoleKey: $rule->requester_role_key,
            );

            if ($rule->levels->isNotEmpty() && $criteria->matches($context)) {
                return $rule;
            }
        }

        return null;
    }
}

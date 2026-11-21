<?php

declare(strict_types=1);

namespace App\Services\Approvals;

use App\Domain\Approvals\ApprovalContext;
use App\Domain\Approvals\ApprovalCriteria;
use App\Models\Approvals\ApprovalRule;
use App\Models\Identity\Membership;
use App\Models\Ordering\Order;
use Illuminate\Support\Facades\DB;

/**
 * Regra de aprovação aplicável a um pedido: a regra ativa de maior
 * prioridade (empate: a criada primeiro) cujos critérios são atendidos.
 * As consultas filtram a empresa explicitamente: também rodam no contexto
 * de sistema.
 */
final class ApprovalRuleResolver
{
    /**
     * Regra para o pedido gravado: total, centro de custo, unidade,
     * categorias dos itens e perfil atual do solicitante. Usada na
     * submissão e na reavaliação (RN-054), quando o total muda.
     */
    public function forOrder(Order $order): ?ApprovalRule
    {
        // Pedidos sem solicitante do portal (equipe, integração) não passam
        // pela aprovação interna da empresa.
        if ($order->placed_by_membership_id === null) {
            return null;
        }

        $requester = Membership::query()
            ->with('role')
            ->where('buyer_company_id', $order->buyer_company_id)
            ->findOrFail($order->placed_by_membership_id);

        return $this->resolve($order->buyer_company_id, new ApprovalContext(
            total: $order->total_cents,
            costCenterId: $order->cost_center_id,
            buyerUnitId: $order->buyer_unit_id,
            categoryIds: self::categoryIds($order),
            requesterRoleKey: $requester->role->key,
        ));
    }

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

    /**
     * @return list<string>
     */
    private static function categoryIds(Order $order): array
    {
        return array_values(array_unique(DB::table('order_items')
            ->join('skus', 'skus.id', '=', 'order_items.sku_id')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->where('order_items.order_id', $order->id)
            ->whereNotNull('products.category_id')
            ->pluck('products.category_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all()));
    }
}

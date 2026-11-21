<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Enums\Identity\Permission;
use App\Models\Approvals\ApprovalRule;
use App\Services\Audit\AuditRecorder;
use App\Support\Http\UntrustedId;
use App\Support\Tenancy\CurrentContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Exclui uma regra de aprovação da empresa (com níveis e aprovadores, em
 * cascata). Pedidos já submetidos não são afetados: guardam o nome da
 * regra e o retrato dos níveis. O estado excluído fica na auditoria.
 * Exige portal.approval_rules no contexto de comprador da própria empresa.
 */
final readonly class DeleteApprovalRule
{
    public function __construct(
        private PortalBuyer $buyer,
        private AuditRecorder $audit,
        private CurrentContext $context,
    ) {}

    /**
     * @return string nome da regra excluída
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException regra inexistente, malformada ou de outra empresa
     */
    public function handle(string $ruleId): string
    {
        $actor = $this->buyer->require(Permission::PortalApprovalRules);
        $ruleId = UntrustedId::uuid($ruleId, ApprovalRule::class);

        return DB::transaction(function () use ($actor, $ruleId): string {
            // Mesma ordem de SaveApprovalRule: empresa (advisory) e depois a regra.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [
                implode('|', ['approval_rules', $this->context->tenantId(), $actor->company->id]),
            ]);

            $rule = ApprovalRule::query()
                ->where('buyer_company_id', $actor->company->id)
                ->whereKey($ruleId)
                ->lockForUpdate()
                ->firstOrFail();

            $snapshot = SaveApprovalRule::snapshot($rule->load('levels.approvers'));
            $rule->delete();

            $this->audit->record('approval_rule.deleted', $rule, [
                'buyer_company_id' => $actor->company->id,
                'before' => $snapshot,
            ]);

            return $rule->name;
        });
    }
}

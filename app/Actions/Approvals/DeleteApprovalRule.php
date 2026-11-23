<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Enums\Identity\Permission;
use App\Models\Approvals\ApprovalRule;
use App\Services\Approvals\ApprovalRuleLock;
use App\Services\Audit\AuditRecorder;
use App\Support\Http\UntrustedId;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Exclui uma regra de aprovação da empresa (com níveis e aprovadores, em
 * cascata). Pedidos já submetidos não são afetados: guardam o nome da
 * regra e o retrato dos níveis. O estado excluído fica na auditoria.
 * Exige portal.approval_rules no contexto de comprador da própria empresa;
 * a exclusão roda no contexto de sistema do mesmo usuário (ADR-024).
 */
final readonly class DeleteApprovalRule
{
    public function __construct(
        private PortalBuyer $buyer,
        private AuditRecorder $audit,
        private ApprovalRuleLock $lock,
        private TenantDatabase $db,
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
            // Regras são somente leitura no contexto de comprador (ADR-024).
            [$rule, $snapshot] = $this->db->asSystem(function () use ($actor, $ruleId): array {
                // Mesma ordem de SaveApprovalRule: regras da empresa e depois a regra.
                $this->lock->exclusive($actor->company->id);

                $rule = ApprovalRule::query()
                    ->where('buyer_company_id', $actor->company->id)
                    ->whereKey($ruleId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $snapshot = SaveApprovalRule::snapshot($rule->load('levels.approvers'));
                $rule->delete();

                return [$rule, $snapshot];
            });

            $this->audit->record('approval_rule.deleted', $rule, [
                'buyer_company_id' => $actor->company->id,
                'before' => $snapshot,
            ]);

            return $rule->name;
        });
    }
}

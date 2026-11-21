<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Approvals\Admin;

use App\Actions\Approvals\DeleteApprovalRule;
use App\Actions\Approvals\SaveApprovalRule;
use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Data\Approvals\ApprovalLevelOverview;
use App\Data\Approvals\ApprovalRuleOverview;
use App\Data\Approvals\ApproverOption;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\Permission;
use App\Livewire\Portal\Approvals\Admin\Concerns\ReadsCompanyApprovers;
use App\Models\Approvals\ApprovalRule;
use App\Models\Approvals\ApprovalRuleLevel;
use App\Models\Buyers\BuyerUnit;
use App\Models\Buyers\CostCenter;
use App\Models\Catalog\Category;
use App\Models\Identity\Role;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Regras de aprovação da empresa (RF-050) na ordem em que são avaliadas
 * nos pedidos: critérios, níveis com quórum e aprovadores, situação, e
 * ações de ativar, desativar e excluir. Exige portal.approval_rules (rota
 * e Actions).
 */
#[Layout('layouts.app')]
#[Title('Regras de aprovação')]
final class ApprovalRulesIndex extends Component
{
    use ReadsCompanyApprovers;

    #[Locked]
    public ?string $message = null;

    public function activate(string $ruleId, SaveApprovalRule $save): void
    {
        $this->clearFeedback();
        $rule = $save->setActive($ruleId, true);

        $this->message = "Regra {$rule->name} ativada: ela vale para os próximos pedidos.";
    }

    public function deactivate(string $ruleId, SaveApprovalRule $save): void
    {
        $this->clearFeedback();
        $rule = $save->setActive($ruleId, false);

        $this->message = "Regra {$rule->name} desativada. Pedidos já enviados para aprovação seguem os níveis definidos no envio.";
    }

    public function delete(string $ruleId, DeleteApprovalRule $delete): void
    {
        $this->clearFeedback();
        $name = $delete->handle($ruleId);

        $this->message = "Regra {$name} excluída. Pedidos já enviados para aprovação não são afetados.";
    }

    public function render(PortalBuyer $buyer): View
    {
        $actor = $buyer->require(Permission::PortalApprovalRules);

        $rules = ApprovalRule::query()
            ->with('levels.approvers')
            ->where('buyer_company_id', $actor->company->id)
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return view('livewire.portal.approvals.admin.approval-rules-index', [
            'rules' => $this->overviews($actor->company->id, $rules),
            'maxRules' => SaveApprovalRule::MAX_RULES_PER_COMPANY,
        ]);
    }

    /**
     * @param  Collection<int, ApprovalRule>  $rules
     * @return list<ApprovalRuleOverview>
     */
    private function overviews(string $companyId, Collection $rules): array
    {
        if ($rules->isEmpty()) {
            return [];
        }

        $members = $this->companyMembers($companyId);
        $centers = CostCenter::query()
            ->where('buyer_company_id', $companyId)
            ->whereIn('id', $rules->pluck('cost_center_id')->filter()->unique()->values()->all())
            ->get()
            ->mapWithKeys(static fn (CostCenter $center): array => [$center->id => "{$center->code} – {$center->name}"])
            ->all();
        $units = BuyerUnit::query()
            ->where('buyer_company_id', $companyId)
            ->whereIn('id', $rules->pluck('buyer_unit_id')->filter()->unique()->values()->all())
            ->get()
            ->mapWithKeys(static fn (BuyerUnit $unit): array => [$unit->id => "{$unit->code} – {$unit->name}"])
            ->all();
        $categories = Category::query()
            ->whereIn('id', $rules->pluck('category_id')->filter()->unique()->values()->all())
            ->pluck('name', 'id')
            ->all();
        $roles = Role::query()->where('kind', MembershipKind::Buyer->value)->pluck('name', 'key')->all();

        $overviews = [];

        foreach ($rules as $rule) {
            $criteria = array_values(array_filter([
                $rule->min_total_cents !== null ? 'Valor a partir de '.$rule->min_total_cents->format() : null,
                $rule->cost_center_id !== null ? 'Centro de custo '.($centers[$rule->cost_center_id] ?? 'indisponível') : null,
                $rule->buyer_unit_id !== null ? 'Unidade '.($units[$rule->buyer_unit_id] ?? 'indisponível') : null,
                $rule->category_id !== null ? 'Categoria '.(string) ($categories[$rule->category_id] ?? 'indisponível') : null,
                $rule->requester_role_key !== null ? 'Solicitante com perfil '.(string) ($roles[$rule->requester_role_key] ?? $rule->requester_role_key) : null,
            ]));

            $levels = array_values($rule->levels->map(static function (ApprovalRuleLevel $level) use ($members): ApprovalLevelOverview {
                $ids = $level->approvers->pluck('membership_id')->map(static fn (mixed $id): string => (string) $id)->all();
                $known = array_values(array_filter(array_map(static fn (string $id): ?ApproverOption => $members[$id] ?? null, $ids)));

                return new ApprovalLevelOverview(
                    $level->level_number,
                    $level->required_approvals,
                    array_map(static fn (ApproverOption $option): string => $option->eligible ? $option->name : $option->name.' (sem acesso)', $known),
                    count(array_filter($known, static fn (ApproverOption $option): bool => $option->eligible)),
                );
            })->all());

            $overviews[] = new ApprovalRuleOverview($rule->id, $rule->name, $rule->priority, $rule->is_active, $criteria, $levels);
        }

        return $overviews;
    }

    private function clearFeedback(): void
    {
        $this->message = null;
    }
}

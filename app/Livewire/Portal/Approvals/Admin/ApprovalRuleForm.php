<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Approvals\Admin;

use App\Actions\Approvals\SaveApprovalRule;
use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Data\Approvals\ApprovalRuleData;
use App\Data\Approvals\ApprovalRuleLevelData;
use App\Data\Approvals\ApproverOption;
use App\Domain\Approvals\Exceptions\InvalidApprovalRule;
use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\Money;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\Permission;
use App\Livewire\Portal\Approvals\Admin\Concerns\ReadsCompanyApprovers;
use App\Models\Approvals\ApprovalRule;
use App\Models\Approvals\ApprovalRuleLevel;
use App\Models\Buyers\BuyerUnit;
use App\Models\Buyers\CostCenter;
use App\Models\Catalog\Category;
use App\Models\Identity\Role;
use App\Support\Http\UntrustedId;
use Brick\Math\Exception\MathException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Criação e edição de uma regra de aprovação (RF-050): nome, prioridade,
 * situação, critérios opcionais e níveis com quórum e aprovadores.
 *
 * Todos os campos são entrada não confiável: o componente apenas converte
 * texto em números e valores (Money::parse); empresa, elegibilidade dos
 * aprovadores e demais regras são conferidas por SaveApprovalRule, e as
 * violações aparecem no campo correspondente.
 */
#[Layout('layouts.app')]
final class ApprovalRuleForm extends Component
{
    use ReadsCompanyApprovers;

    #[Locked]
    public ?string $ruleId = null;

    public string $name = '';

    public string $priority = '0';

    public bool $isActive = true;

    public string $minTotal = '';

    public string $costCenterId = '';

    public string $buyerUnitId = '';

    public string $categoryId = '';

    public string $requesterRoleKey = '';

    /**
     * Níveis digitados: quórum e aprovadores marcados. Entrada não confiável,
     * normalizada a cada atualização (updatedLevels).
     *
     * @var list<array{required: string, approvers: list<string>}>
     */
    public array $levels = [];

    public function mount(PortalBuyer $buyer, ?string $rule = null): void
    {
        $actor = $buyer->require(Permission::PortalApprovalRules);

        if ($rule === null) {
            $this->levels = [self::emptyLevel()];

            return;
        }

        $found = ApprovalRule::query()
            ->with('levels.approvers')
            ->where('buyer_company_id', $actor->company->id)
            ->whereKey(UntrustedId::uuid($rule, ApprovalRule::class))
            ->firstOrFail();

        $this->ruleId = $found->id;
        $this->name = $found->name;
        $this->priority = (string) $found->priority;
        $this->isActive = $found->is_active;
        $this->minTotal = $found->min_total_cents !== null ? str_replace('.', ',', $found->min_total_cents->toDecimalString()) : '';
        $this->costCenterId = (string) $found->cost_center_id;
        $this->buyerUnitId = (string) $found->buyer_unit_id;
        $this->categoryId = (string) $found->category_id;
        $this->requesterRoleKey = (string) $found->requester_role_key;
        $this->levels = array_values($found->levels->map(static fn (ApprovalRuleLevel $level): array => [
            'required' => (string) $level->required_approvals,
            'approvers' => array_values($level->approvers->pluck('membership_id')->map(static fn (mixed $id): string => (string) $id)->sort()->values()->all()),
        ])->all());

        if ($this->levels === []) {
            $this->levels = [self::emptyLevel()];
        }
    }

    /**
     * Mantém o formato esperado dos níveis (texto no quórum, lista de textos
     * nos aprovadores), descartando o que o cliente enviar fora dele.
     */
    public function updatedLevels(): void
    {
        $this->levels = self::sanitizeLevels($this->levels);
    }

    public function addLevel(): void
    {
        if (count($this->levels) < SaveApprovalRule::MAX_LEVELS) {
            $this->levels[] = self::emptyLevel();
        }
    }

    public function removeLevel(int $index): void
    {
        if (count($this->levels) <= 1) {
            return;
        }

        $levels = $this->levels;
        unset($levels[$index]);
        $this->levels = array_values($levels);
        $this->resetErrorBag();
    }

    public function save(SaveApprovalRule $save): void
    {
        $this->resetErrorBag();

        $priority = $this->integerOrError('priority', $this->priority, 'Informe a prioridade como número inteiro entre '.SaveApprovalRule::MIN_PRIORITY.' e '.SaveApprovalRule::MAX_PRIORITY.'.');
        $minTotal = trim($this->minTotal) === '' ? null : $this->moneyOrError('minTotal', $this->minTotal);
        $levels = $this->levelData();

        if ($priority === null || ($minTotal === null && trim($this->minTotal) !== '') || $levels === null) {
            return;
        }

        try {
            $rule = $save->handle($this->ruleId, new ApprovalRuleData(
                name: $this->name,
                priority: $priority,
                isActive: $this->isActive,
                levels: $levels,
                minTotal: $minTotal,
                costCenterId: $this->costCenterId,
                buyerUnitId: $this->buyerUnitId,
                categoryId: $this->categoryId,
                requesterRoleKey: $this->requesterRoleKey,
            ));
        } catch (InvalidApprovalRule $e) {
            $this->addRuleViolation($e);

            return;
        }

        session()->flash('success', $this->ruleId === null
            ? "Regra {$rule->name} criada. Ela vale para os pedidos enviados a partir de agora."
            : "Regra {$rule->name} atualizada. Pedidos já enviados para aprovação seguem os níveis definidos no envio.");

        $this->redirectRoute('portal.approval-rules');
    }

    public function render(PortalBuyer $buyer): View
    {
        $actor = $buyer->require(Permission::PortalApprovalRules);
        $members = $this->companyMembers($actor->company->id);

        return view('livewire.portal.approvals.admin.approval-rule-form', [
            'eligible' => self::eligibleOnly($members),
            'withoutAccess' => $this->selectedWithoutAccess($members),
            'costCenterOptions' => $this->costCenterOptions($actor->company->id),
            'unitOptions' => $this->unitOptions($actor->company->id),
            'categoryOptions' => self::categoryOptions(),
            'roleOptions' => Role::query()->where('kind', MembershipKind::Buyer->value)->orderBy('name')->pluck('name', 'key')->all(),
            'maxLevels' => SaveApprovalRule::MAX_LEVELS,
            'maxRequired' => SaveApprovalRule::MAX_REQUIRED_APPROVALS,
        ])->title($this->ruleId === null ? 'Nova regra de aprovação' : 'Editar regra de aprovação');
    }

    /**
     * @return array{required: string, approvers: list<string>}
     */
    private static function emptyLevel(): array
    {
        return ['required' => '1', 'approvers' => []];
    }

    /**
     * @param  array<mixed>  $levels
     * @return list<array{required: string, approvers: list<string>}>
     */
    private static function sanitizeLevels(array $levels): array
    {
        $clean = [];

        // Um nível além do máximo é mantido para que a Action recuse o excesso com mensagem.
        foreach (array_slice(array_values($levels), 0, SaveApprovalRule::MAX_LEVELS + 1) as $raw) {
            $level = is_array($raw) ? $raw : [];
            $required = $level['required'] ?? '';
            $approvers = is_array($level['approvers'] ?? null) ? $level['approvers'] : [];

            $clean[] = [
                'required' => is_scalar($required) ? mb_substr((string) $required, 0, 6) : '',
                'approvers' => array_values(array_map(
                    static fn (mixed $id): string => is_string($id) ? mb_substr($id, 0, 64) : '',
                    array_slice(array_values($approvers), 0, SaveApprovalRule::MAX_APPROVERS_PER_LEVEL + 1),
                )),
            ];
        }

        return $clean;
    }

    /**
     * Níveis digitados convertidos para a Action; null quando algum quórum
     * não é um número inteiro (erro já associado ao campo).
     *
     * @return list<ApprovalRuleLevelData>|null
     */
    private function levelData(): ?array
    {
        $this->levels = self::sanitizeLevels($this->levels);

        if (count($this->levels) > SaveApprovalRule::MAX_LEVELS) {
            $this->addError('levels', 'A regra pode ter até '.SaveApprovalRule::MAX_LEVELS.' níveis de aprovação.');

            return null;
        }

        $levels = [];
        $valid = true;

        foreach ($this->levels as $index => $level) {
            $number = $index + 1;
            $quorum = $this->integerOrError(
                "levels.{$index}.required",
                $level['required'],
                "Informe o quórum do nível {$number} (de 1 a ".SaveApprovalRule::MAX_REQUIRED_APPROVALS.').',
            );

            if ($quorum === null) {
                $valid = false;

                continue;
            }

            $levels[] = new ApprovalRuleLevelData($quorum, $level['approvers']);
        }

        return $valid ? $levels : null;
    }

    private function integerOrError(string $field, string $value, string $message): ?int
    {
        $text = trim($value);

        if (preg_match('/^[+-]?\d{1,6}$/D', $text) !== 1) {
            $this->addError($field, $message);

            return null;
        }

        return (int) $text;
    }

    /**
     * Valor em reais digitado em pt-BR ("1.234,56", "1234,56" ou "1234.56").
     */
    private function moneyOrError(string $field, string $value): ?Money
    {
        try {
            return Money::parse($value);
        } catch (AmountOutOfRange) {
            $this->addError($field, 'Valor acima do limite suportado.');
        } catch (InvalidArgumentException|MathException) {
            $this->addError($field, 'Informe um valor em reais, por exemplo 1.234,56.');
        }

        return null;
    }

    private function addRuleViolation(InvalidApprovalRule $violation): void
    {
        $details = $violation->details();

        if (($details['field'] ?? null) === 'levels' && isset($details['level'], $details['attribute']) && is_int($details['level'])) {
            $attribute = $details['attribute'] === 'required_approvals' ? 'required' : 'approvers';
            $this->addError('levels.'.($details['level'] - 1).'.'.$attribute, $violation->getMessage());

            return;
        }

        $this->addViolation($violation, [
            'name' => 'name',
            'priority' => 'priority',
            'min_total' => 'minTotal',
            'cost_center' => 'costCenterId',
            'buyer_unit' => 'buyerUnitId',
            'category' => 'categoryId',
            'requester_role' => 'requesterRoleKey',
            'levels' => 'levels',
        ], 'name');
    }

    /**
     * Aprovadores marcados que perderam o acesso (revogados ou sem permissão
     * de aprovar): aparecem para serem desmarcados antes de salvar.
     *
     * @param  array<string, ApproverOption>  $members
     * @return array<string, ApproverOption>
     */
    private function selectedWithoutAccess(array $members): array
    {
        $selected = [];

        foreach ($this->levels as $level) {
            foreach ($level['approvers'] as $id) {
                if (isset($members[$id]) && ! $members[$id]->eligible) {
                    $selected[$id] = $members[$id];
                }
            }
        }

        return $selected;
    }

    /**
     * Centros de custo ativos da empresa e o já escolhido (mesmo inativo).
     *
     * @return array<string, string>
     */
    private function costCenterOptions(string $companyId): array
    {
        $current = Str::isUuid($this->costCenterId) ? strtolower($this->costCenterId) : null;

        return CostCenter::query()
            ->where('buyer_company_id', $companyId)
            ->where(static function (Builder $query) use ($current): void {
                $query->where('is_active', true);

                if ($current !== null) {
                    $query->orWhere('id', $current);
                }
            })
            ->orderBy('code')
            ->get()
            ->mapWithKeys(static fn (CostCenter $center): array => [
                $center->id => "{$center->code} – {$center->name}".($center->is_active ? '' : ' (inativo)'),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function unitOptions(string $companyId): array
    {
        $current = Str::isUuid($this->buyerUnitId) ? strtolower($this->buyerUnitId) : null;

        return BuyerUnit::query()
            ->where('buyer_company_id', $companyId)
            ->where(static function (Builder $query) use ($current): void {
                $query->where('is_active', true);

                if ($current !== null) {
                    $query->orWhere('id', $current);
                }
            })
            ->orderBy('code')
            ->get()
            ->mapWithKeys(static fn (BuyerUnit $unit): array => [
                $unit->id => "{$unit->code} – {$unit->name}".($unit->is_active ? '' : ' (inativa)'),
            ])
            ->all();
    }

    /**
     * Categorias do catálogo em ordem hierárquica, com recuo pela profundidade.
     *
     * @return array<string, string>
     */
    private static function categoryOptions(): array
    {
        $categories = Category::query()->orderBy('sort_order')->orderBy('name')->orderBy('id')->get()->keyBy('id');
        $children = [];
        $roots = [];

        foreach ($categories as $category) {
            if ($category->parent_id !== null && $categories->has($category->parent_id)) {
                $children[$category->parent_id][] = $category->id;
            } else {
                $roots[] = $category->id;
            }
        }

        $options = [];
        $stack = array_map(static fn (string $id): array => [$id, 0], array_reverse($roots));

        while ($stack !== []) {
            [$id, $depth] = array_pop($stack);
            $category = $categories->get($id);

            if ($category === null || isset($options[$id])) {
                continue;
            }

            $options[$id] = str_repeat('— ', $depth).$category->name;

            foreach (array_reverse($children[$id] ?? []) as $child) {
                $stack[] = [$child, $depth + 1];
            }
        }

        return $options;
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Pricing;

use App\Actions\Fulfillment\Staff\SaveBuyerFreightCondition;
use App\Actions\Fulfillment\Staff\SaveFreightRule;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Fulfillment\FreightConditionKind;
use App\Enums\Identity\Permission;
use App\Livewire\Staff\Pricing\Concerns\InteractsWithPricingForms;
use App\Models\Fulfillment\BuyerFreightCondition;
use App\Models\Fulfillment\FreightRule;
use App\Support\Auth\Access;
use App\Support\Auth\SalesPortfolio;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Frete do distribuidor (RF-092): regras por UF com isenção a partir de um
 * subtotal e condições negociadas por empresa compradora. O frete é
 * calculado no checkout e exibido como frete do distribuidor.
 */
#[Layout('layouts.app')]
#[Title('Frete')]
final class FreightIndex extends Component
{
    use InteractsWithPricingForms;

    #[Locked]
    public ?string $editingId = null;

    public string $name = '';

    public string $states = '';

    public string $amount = '';

    public string $freeFrom = '';

    public string $priority = '0';

    public bool $isActive = true;

    public string $companySearch = '';

    public string $companyId = '';

    public string $conditionKind = '';

    public string $conditionAmount = '';

    public ?string $message = null;

    public function edit(string $ruleId): void
    {
        $rule = FreightRule::query()->findOrFail(UntrustedId::uuid($ruleId, FreightRule::class));
        $this->resetErrorBag();
        $this->editingId = $rule->id;
        $this->name = $rule->name;
        $this->states = implode(', ', $rule->states);
        $this->amount = $rule->amount_cents->format();
        $this->freeFrom = $rule->free_from_cents?->format() ?? '';
        $this->priority = (string) $rule->priority;
        $this->isActive = $rule->is_active;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'name', 'states', 'amount', 'freeFrom', 'priority', 'isActive');
        $this->resetErrorBag();
    }

    public function save(SaveFreightRule $save): void
    {
        if (preg_match('/^\d{1,4}$/D', trim($this->priority)) !== 1) {
            $this->addError('priority', 'Informe a prioridade de 0 a 1.000.');

            return;
        }

        try {
            $rule = $save->handle($this->editingId, $this->name, $this->states, self::plain($this->amount), self::plain($this->freeFrom), (int) $this->priority, $this->isActive);
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, ['name' => 'name', 'states' => 'states', 'amount' => 'amount', 'free_from' => 'freeFrom', 'priority' => 'priority'], 'name');

            return;
        }

        $this->cancel();
        $this->message = "Regra {$rule->name} salva.";
    }

    public function saveCondition(SaveBuyerFreightCondition $save): void
    {
        if ($this->companyId === '') {
            $this->addError('companyId', 'Escolha a empresa compradora.');

            return;
        }

        try {
            $save->handle($this->companyId, FreightConditionKind::tryFrom($this->conditionKind), self::plain($this->conditionAmount));
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, ['condition_amount' => 'conditionAmount'], 'conditionAmount');

            return;
        }

        $this->reset('conditionKind', 'conditionAmount');
        $this->message = 'Condição de frete da empresa atualizada.';
    }

    public function render(Access $access): View
    {
        return view('livewire.staff.pricing.freight-index', [
            'rules' => FreightRule::query()->orderByDesc('is_active')->orderByDesc('priority')->orderBy('name')->get(),
            'conditions' => app(SalesPortfolio::class)->restrict(BuyerFreightCondition::query())->join('buyer_companies', 'buyer_companies.id', '=', 'buyer_freight_conditions.buyer_company_id')
                ->orderBy('buyer_companies.trade_name')->get(['buyer_freight_conditions.*', 'buyer_companies.trade_name']),
            'companies' => $this->searchCompanies($this->companySearch, $this->companyId !== '' ? $this->companyId : null)->mapWithKeys(static fn ($c): array => [$c->id => $c->trade_name])->all(),
            'kinds' => ['' => 'Sem condição (vale a regra)'] + collect(FreightConditionKind::cases())->mapWithKeys(static fn (FreightConditionKind $k): array => [$k->value => $k->label()])->all(),
            'canManage' => $access->can(Permission::PricingManage),
        ]);
    }

    /** "R$ 1.234,56" → "1.234,56"; vazio continua vazio. */
    private static function plain(string $value): string
    {
        return trim(str_replace(['R$', "\u{a0}"], ['', ' '], $value));
    }
}

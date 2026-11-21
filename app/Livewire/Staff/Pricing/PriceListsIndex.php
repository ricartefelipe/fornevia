<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Pricing;

use App\Actions\Pricing\SavePriceList;
use App\Actions\Pricing\UpdatePricingSettings;
use App\Domain\Pricing\Exceptions\InvalidValidityPeriod;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Percentage;
use App\Enums\Identity\Permission;
use App\Enums\Pricing\PriceListScope;
use App\Enums\Pricing\PriceListStatus;
use App\Livewire\Staff\Pricing\Concerns\EditsPriceListHeader;
use App\Livewire\Staff\Pricing\Concerns\InteractsWithPricingForms;
use App\Models\Buyers\CustomerGroup;
use App\Models\Pricing\PriceList;
use App\Models\Pricing\PricingPolicy;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Tenancy\CurrentContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tabelas de preço: filtros por escopo e situação, criação (em rascunho) e
 * margem mínima padrão do tenant. Regras e autorização ficam nas Actions.
 */
#[Layout('layouts.app')]
#[Title('Tabelas de preço')]
final class PriceListsIndex extends Component
{
    use EditsPriceListHeader;
    use InteractsWithPricingForms;
    use WithPagination;

    #[Url(as: 'escopo', except: '')]
    public string $scopeFilter = '';

    #[Url(as: 'situacao', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    public string $minMargin = '';

    public ?string $message = null;

    public function mount(Clock $clock, Access $access): void
    {
        $this->validFrom = $clock->today()->toDateString();

        // A margem é dado interno: só entra no estado do componente (enviado
        // ao navegador) para quem pode alterá-la.
        if ($access->can(Permission::PricingManage)) {
            $this->minMargin = str_replace('%', '', Percentage::ofBasisPoints($this->currentMinMarginBps(), allowOutOfRange: true)->format());
        }
    }

    public function updatedScopeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('scopeFilter', 'statusFilter', 'search');
        $this->resetPage();
    }

    public function create(SavePriceList $save): void
    {
        $data = $this->validateHeader();

        try {
            $list = $save->handle(null, $data);
        } catch (InvalidValidityPeriod $e) {
            $this->addError('validUntil', $e->getMessage());

            return;
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, $this->headerFields(), 'code');

            return;
        }

        session()->flash('success', "Tabela {$list->code} criada como rascunho. Cadastre os itens e ative a tabela.");
        $this->redirectRoute('pricing.lists.edit', ['priceList' => $list->id]);
    }

    public function saveSettings(UpdatePricingSettings $update): void
    {
        $this->validate(
            ['minMargin' => ['required', 'string', 'max:6']],
            [],
            ['minMargin' => 'margem mínima padrão'],
        );

        try {
            $bps = Percentage::parse($this->minMargin)->basisPoints;
        } catch (InvalidArgumentException) {
            $this->addError('minMargin', 'Informe um percentual entre 0 e 100, com até duas casas decimais (por exemplo 12,5).');

            return;
        }

        try {
            $update->handle($bps);
        } catch (BusinessRuleViolation $e) {
            $this->addError('minMargin', $e->getMessage());

            return;
        }

        $this->message = 'Margem mínima padrão atualizada para '.Percentage::ofBasisPoints($bps)->format().'.';
    }

    public function render(Access $access): View
    {
        $scope = PriceListScope::tryFrom($this->scopeFilter);
        $status = PriceListStatus::tryFrom($this->statusFilter);
        $search = trim($this->search);

        $canManage = $access->can(Permission::PricingManage);

        $lists = PriceList::query()
            ->with(['customerGroup', 'buyerCompany'])
            ->withCount('items')
            ->when($scope !== null, static fn (Builder $q) => $q->where('scope', $scope?->value))
            ->when($status !== null, static fn (Builder $q) => $q->where('status', $status?->value))
            ->when($search !== '', static function (Builder $q) use ($search): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $q->where(static fn (Builder $w) => $w->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like));
            })
            ->orderByRaw("case status when 'active' then 0 when 'draft' then 1 else 2 end")
            ->orderBy('code')
            ->paginate(25);

        return view('livewire.staff.pricing.price-lists-index', [
            'lists' => $lists,
            'scopeOptions' => self::scopeOptions(),
            'statusOptions' => self::statusOptions(),
            'groupOptions' => CustomerGroup::query()->orderBy('name')->pluck('name', 'id')->all(),
            'companyOptions' => $this->scope === PriceListScope::Customer->value
                ? $this->companyOptions($this->companySearch, $this->buyerCompanyId !== '' ? $this->buyerCompanyId : null)
                : [],
            'hasFilters' => $scope !== null || $status !== null || $search !== '',
            'canManage' => $canManage,
            'currentMargin' => $canManage ? Percentage::ofBasisPoints($this->currentMinMarginBps(), allowOutOfRange: true)->format() : null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function scopeOptions(): array
    {
        $options = [];

        foreach (PriceListScope::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        $options = [];

        foreach (PriceListStatus::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    private function currentMinMarginBps(): int
    {
        $bps = PricingPolicy::query()->whereKey(app(CurrentContext::class)->tenantId())->value('min_margin_bps');

        return is_numeric($bps) ? (int) $bps : 0;
    }
}

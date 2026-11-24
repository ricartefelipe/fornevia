<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Pricing;

use App\Actions\Pricing\SimulatePrice;
use App\Domain\Shared\BusinessRuleViolation;
use App\Livewire\Staff\Pricing\Concerns\InteractsWithPricingForms;
use App\Models\Catalog\Sku;
use App\Support\Auth\SalesPortfolio;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Validation\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Simulador de preço: empresa, SKU, quantidade e data => preço resolvido,
 * origem, faixa aplicada e todos os candidatos considerados, na ordem de
 * precedência (ADR-011).
 */
#[Layout('layouts.app')]
#[Title('Simulador de preços')]
final class PriceSimulator extends Component
{
    use InteractsWithPricingForms;

    public string $companySearch = '';

    public string $buyerCompanyId = '';

    public string $skuSearch = '';

    #[Locked]
    public ?string $selectedSkuId = null;

    public string $quantity = '1';

    public string $date = '';

    /** @var array<string, mixed>|null resultado da última simulação (somente exibição) */
    #[Locked]
    public ?array $result = null;

    public function mount(Clock $clock): void
    {
        $this->date = $clock->today()->toDateString();
    }

    public function selectSku(string $skuId): void
    {
        $sku = Sku::query()->findOrFail(UntrustedId::uuid($skuId, Sku::class));
        $this->selectedSkuId = $sku->id;
        $this->skuSearch = '';
        $this->result = null;
        $this->resetErrorBag('skuSearch');
    }

    public function clearSku(): void
    {
        $this->reset('selectedSkuId', 'skuSearch', 'result');
    }

    public function simulate(SimulatePrice $simulate): void
    {
        $this->result = null;

        $this->validate([
            'buyerCompanyId' => ['required', 'uuid'],
            'quantity' => ['required', 'string', 'max:18'],
            'date' => ['required', ...BusinessDate::rules()],
        ], [], [
            'buyerCompanyId' => 'empresa compradora',
            'quantity' => 'quantidade',
            'date' => 'data',
        ]);

        if ($this->selectedSkuId === null) {
            $this->addError('skuSearch', 'Selecione um SKU pela busca.');

            return;
        }

        $quantity = $this->quantityOrError('quantity', $this->quantity);

        if ($quantity === null) {
            return;
        }

        $company = app(SalesPortfolio::class)->findCompany($this->buyerCompanyId);
        $sku = Sku::query()->findOrFail($this->selectedSkuId);

        try {
            $simulation = $simulate->handle($company, $sku, $quantity, CarbonImmutable::parse($this->date));
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, ['quantity' => 'quantity'], 'quantity');

            return;
        }

        $this->result = $simulation->toArray();
    }

    public function render(): View
    {
        return view('livewire.staff.pricing.price-simulator', [
            'companyOptions' => $this->companyOptions($this->companySearch, $this->buyerCompanyId !== '' ? $this->buyerCompanyId : null),
            'selectedSku' => $this->selectedSku($this->selectedSkuId),
            'skuResults' => $this->selectedSkuId === null ? $this->searchSkus($this->skuSearch) : collect(),
        ]);
    }
}

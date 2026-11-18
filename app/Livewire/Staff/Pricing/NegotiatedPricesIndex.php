<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Pricing;

use App\Actions\Pricing\RevokeNegotiatedPrice;
use App\Actions\Pricing\SaveNegotiatedPrice;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Identity\Permission;
use App\Enums\Pricing\NegotiatedPriceStatus;
use App\Livewire\Staff\Pricing\Concerns\InteractsWithPricingForms;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Sku;
use App\Models\Identity\User;
use App\Models\Pricing\NegotiatedPrice;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Validation\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Condições negociadas por empresa e SKU: consulta com filtro por empresa,
 * cadastro (aprovado pelo usuário atual) e revogação com justificativa.
 */
#[Layout('layouts.app')]
#[Title('Condições negociadas')]
final class NegotiatedPricesIndex extends Component
{
    use InteractsWithPricingForms;
    use WithPagination;

    /** Empresa filtrada (entrada não confiável; ignorada fora do formato UUID). */
    #[Url(as: 'empresa', except: '')]
    public string $companyFilter = '';

    #[Url(as: 'situacao', except: 'active')]
    public string $statusFilter = 'active';

    public string $filterSearch = '';

    public string $companySearch = '';

    public string $buyerCompanyId = '';

    public string $skuSearch = '';

    #[Locked]
    public ?string $selectedSkuId = null;

    /** Embalagem do SKU quando ele foi selecionado (Sku::packagingFingerprint). */
    #[Locked]
    public ?string $selectedSkuPackaging = null;

    public string $unitPrice = '';

    public string $validFrom = '';

    public string $validUntil = '';

    public string $notes = '';

    public string $revokeReason = '';

    public ?string $message = null;

    public function mount(Clock $clock): void
    {
        $this->validFrom = $clock->today()->toDateString();

        if (Str::isUuid($this->companyFilter)) {
            $this->buyerCompanyId = $this->companyFilter;
        }
    }

    public function updatedCompanyFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function selectSku(string $skuId): void
    {
        $sku = Sku::query()->findOrFail(UntrustedId::uuid($skuId, Sku::class));
        $this->selectedSkuId = $sku->id;
        $this->selectedSkuPackaging = $sku->packagingFingerprint();
        $this->skuSearch = '';
        $this->resetErrorBag('skuSearch');
    }

    public function clearSku(): void
    {
        $this->reset('selectedSkuId', 'selectedSkuPackaging', 'skuSearch');
    }

    public function create(SaveNegotiatedPrice $save, Clock $clock): void
    {
        $this->validate([
            'buyerCompanyId' => ['required', 'uuid'],
            'unitPrice' => ['required', 'string', 'max:18'],
            'validFrom' => ['required', ...BusinessDate::rules()],
            'validUntil' => ['nullable', ...BusinessDate::rules(), 'after_or_equal:validFrom'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'buyerCompanyId' => 'empresa compradora',
            'unitPrice' => 'preço negociado',
            'validFrom' => 'início da vigência',
            'validUntil' => 'término da vigência',
            'notes' => 'observação',
        ]);

        if ($this->selectedSkuId === null) {
            $this->addError('skuSearch', 'Selecione um SKU pela busca.');

            return;
        }

        $price = $this->moneyOrError('unitPrice', $this->unitPrice);

        if ($price === null) {
            return;
        }

        $company = BuyerCompany::query()->findOrFail(UntrustedId::uuid($this->buyerCompanyId, BuyerCompany::class));
        $sku = Sku::query()->findOrFail($this->selectedSkuId);

        try {
            $save->handle(
                $company,
                $sku,
                $price,
                CarbonImmutable::parse($this->validFrom),
                $this->validUntil !== '' ? CarbonImmutable::parse($this->validUntil) : null,
                $this->notes,
                $this->selectedSkuPackaging,
            );
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, ['unit_price' => 'unitPrice', 'sku' => 'skuSearch'], 'validFrom');

            return;
        }

        $this->reset('selectedSkuId', 'selectedSkuPackaging', 'skuSearch', 'unitPrice', 'validUntil', 'notes');
        $this->validFrom = $clock->today()->toDateString();
        $this->resetErrorBag();
        $this->message = "Condição negociada registrada para {$company->trade_name} no SKU {$sku->code}.";
    }

    public function revoke(string $negotiatedPriceId, RevokeNegotiatedPrice $revoke): void
    {
        $negotiated = NegotiatedPrice::query()->findOrFail(UntrustedId::uuid($negotiatedPriceId, NegotiatedPrice::class));

        try {
            $revoke->handle($negotiated, $this->revokeReason);
        } catch (BusinessRuleViolation $e) {
            $this->addError('revokeReason', $e->getMessage());

            return;
        }

        $this->reset('revokeReason');
        $this->resetErrorBag('revokeReason');
        $this->message = 'Condição negociada revogada.';
    }

    public function render(Access $access): View
    {
        $companyFilter = Str::isUuid($this->companyFilter) ? $this->companyFilter : null;
        $status = NegotiatedPriceStatus::tryFrom($this->statusFilter);

        $prices = NegotiatedPrice::query()
            ->with(['buyerCompany', 'sku.product'])
            ->when($companyFilter !== null, static fn (Builder $q) => $q->where('buyer_company_id', $companyFilter))
            ->when($status !== null, static fn (Builder $q) => $q->where('status', $status?->value))
            ->orderByDesc('valid_from')
            ->orderBy('id')
            ->paginate(25);

        $approvers = User::query()
            ->whereIn('id', $prices->getCollection()->pluck('approved_by_user_id')->filter()->unique()->values()->all())
            ->pluck('name', 'id')
            ->all();

        $canManage = $access->can(Permission::PricingManage);

        return view('livewire.staff.pricing.negotiated-prices-index', [
            'prices' => $prices,
            'approvers' => $approvers,
            'canManage' => $canManage,
            'filterOptions' => $this->companyOptions($this->filterSearch, $companyFilter),
            'statusOptions' => [
                NegotiatedPriceStatus::Active->value => 'Ativas',
                NegotiatedPriceStatus::Revoked->value => 'Revogadas',
                'all' => 'Todas',
            ],
            'companyOptions' => $canManage
                ? $this->companyOptions($this->companySearch, $this->buyerCompanyId !== '' ? $this->buyerCompanyId : null)
                : [],
            'selectedSku' => $this->selectedSku($this->selectedSkuId),
            'skuResults' => $canManage && $this->selectedSkuId === null ? $this->searchSkus($this->skuSearch) : collect(),
            'hasFilters' => $companyFilter !== null || $status !== NegotiatedPriceStatus::Active,
        ]);
    }
}

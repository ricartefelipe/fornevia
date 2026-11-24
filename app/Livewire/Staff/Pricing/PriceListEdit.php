<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Pricing;

use App\Actions\Pricing\ActivatePriceList;
use App\Actions\Pricing\ArchivePriceList;
use App\Actions\Pricing\RemovePriceListItem;
use App\Actions\Pricing\SavePriceList;
use App\Actions\Pricing\SetPriceListItem;
use App\Domain\Pricing\Exceptions\InvalidValidityPeriod;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Identity\Permission;
use App\Enums\Pricing\PriceListScope;
use App\Enums\Pricing\PriceListStatus;
use App\Livewire\Staff\Pricing\Concerns\EditsPriceListHeader;
use App\Livewire\Staff\Pricing\Concerns\InteractsWithPricingForms;
use App\Models\Buyers\CustomerGroup;
use App\Models\Catalog\Sku;
use App\Models\Pricing\PriceList;
use App\Models\Pricing\PriceListItem;
use App\Models\Pricing\PriceTier;
use App\Support\Auth\Access;
use App\Support\Auth\SalesPortfolio;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tabela de preço: cabeçalho, ativação e arquivamento, itens por SKU com
 * faixas por volume. A tabela e os itens são recarregados no escopo do
 * tenant a cada ação; identificadores recebidos do cliente passam por
 * UntrustedId.
 */
#[Layout('layouts.app')]
#[Title('Tabela de preço')]
final class PriceListEdit extends Component
{
    use EditsPriceListHeader;
    use InteractsWithPricingForms;
    use WithPagination;

    #[Locked]
    public string $priceListId = '';

    public string $itemSearch = '';

    public string $skuSearch = '';

    #[Locked]
    public ?string $selectedSkuId = null;

    /** Embalagem do SKU quando o formulário foi aberto (Sku::packagingFingerprint). */
    #[Locked]
    public ?string $selectedSkuPackaging = null;

    public string $unitPrice = '';

    /** @var list<array{min: string, price: string}> faixas digitadas (entrada não confiável) */
    public array $tiers = [];

    public string $archiveReason = '';

    public ?string $message = null;

    public function mount(PriceList $priceList): void
    {
        // Tabela de empresa fora da carteira do representante equivale a inexistente.
        abort_unless(app(SalesPortfolio::class)->covers($priceList->buyer_company_id) || $priceList->buyer_company_id === null, 404);
        $this->priceListId = $priceList->id;
        $this->fillHeader($priceList);
    }

    public function updatedItemSearch(): void
    {
        $this->resetPage();
    }

    public function saveHeader(SavePriceList $save): void
    {
        $data = $this->validateHeader();

        try {
            $list = $save->handle($this->priceList(), $data);
        } catch (InvalidValidityPeriod $e) {
            $this->addError('validUntil', $e->getMessage());

            return;
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, $this->headerFields(), 'code');

            return;
        }

        $this->fillHeader($list);
        $this->message = 'Cabeçalho da tabela atualizado.';
    }

    public function activate(ActivatePriceList $activate): void
    {
        try {
            $activate->handle($this->priceList());
        } catch (BusinessRuleViolation $e) {
            $this->addError('status', $e->getMessage());

            return;
        }

        $this->resetErrorBag('status');
        $this->message = 'Tabela ativada: os preços passam a valer na vigência informada.';
    }

    public function archive(ArchivePriceList $archive): void
    {
        $this->validate(['archiveReason' => ['nullable', 'string', 'max:500']], [], ['archiveReason' => 'motivo do arquivamento']);

        try {
            $archive->handle($this->priceList(), $this->archiveReason);
        } catch (BusinessRuleViolation $e) {
            $this->addError('status', $e->getMessage());

            return;
        }

        $this->reset('archiveReason');
        $this->cancelItem();
        $this->message = 'Tabela arquivada: deixou de participar do cálculo de preços.';
    }

    public function selectSku(string $skuId): void
    {
        $sku = Sku::query()->findOrFail(UntrustedId::uuid($skuId, Sku::class));
        $existing = PriceListItem::query()
            ->with('tiers')
            ->where('price_list_id', $this->priceList()->id)
            ->where('sku_id', $sku->id)
            ->first();

        $this->resetErrorBag();
        $this->selectedSkuId = $sku->id;
        $this->selectedSkuPackaging = $sku->packagingFingerprint();
        $this->skuSearch = '';

        if ($existing !== null) {
            $this->fillItem($existing);
        } else {
            $this->unitPrice = '';
            $this->tiers = [];
        }
    }

    public function editItem(string $itemId): void
    {
        $item = PriceListItem::query()
            ->with('tiers')
            ->where('price_list_id', $this->priceList()->id)
            ->findOrFail(UntrustedId::uuid($itemId, PriceListItem::class));

        $this->resetErrorBag();
        $this->selectedSkuId = $item->sku_id;
        $this->selectedSkuPackaging = Sku::query()->findOrFail($item->sku_id)->packagingFingerprint();
        $this->skuSearch = '';
        $this->fillItem($item);
    }

    public function addTier(): void
    {
        if (count($this->tiers) < SetPriceListItem::MAX_TIERS) {
            $this->tiers[] = ['min' => '', 'price' => ''];
        }
    }

    public function removeTier(int $index): void
    {
        $this->tiers = array_values(array_filter(
            $this->tiers,
            static fn (int $position): bool => $position !== $index,
            ARRAY_FILTER_USE_KEY,
        ));
        $this->resetErrorBag();
    }

    public function cancelItem(): void
    {
        $this->reset('selectedSkuId', 'selectedSkuPackaging', 'unitPrice', 'tiers', 'skuSearch');
        $this->resetErrorBag();
    }

    public function saveItem(SetPriceListItem $set): void
    {
        if ($this->selectedSkuId === null) {
            $this->addError('skuSearch', 'Selecione um SKU pela busca.');

            return;
        }

        $this->validate([
            'unitPrice' => ['required', 'string', 'max:18'],
            'tiers' => ['array', 'max:'.SetPriceListItem::MAX_TIERS],
            'tiers.*.min' => ['required', 'string', 'max:18'],
            'tiers.*.price' => ['required', 'string', 'max:18'],
        ], [], [
            'unitPrice' => 'preço unitário',
            'tiers' => 'faixas',
            'tiers.*.min' => 'quantidade mínima',
            'tiers.*.price' => 'preço da faixa',
        ]);

        $price = $this->moneyOrError('unitPrice', $this->unitPrice);
        $pairs = [];

        foreach ($this->tiers as $index => $tier) {
            $minimum = $this->quantityOrError("tiers.{$index}.min", $tier['min']);
            $tierPrice = $this->moneyOrError("tiers.{$index}.price", $tier['price']);

            if ($minimum !== null && $tierPrice !== null) {
                $pairs[] = [$minimum, $tierPrice];
            }
        }

        if ($price === null || count($pairs) !== count($this->tiers)) {
            return;
        }

        $sku = Sku::query()->findOrFail($this->selectedSkuId);

        try {
            $set->handle($this->priceList(), $sku, $price, $pairs, $this->selectedSkuPackaging);
        } catch (BusinessRuleViolation $e) {
            $details = $e->details();

            if (($details['field'] ?? null) === 'tiers' && isset($details['index'], $details['attribute']) && is_int($details['index'])) {
                $this->addError('tiers.'.$details['index'].'.'.($details['attribute'] === 'unit_price' ? 'price' : 'min'), $e->getMessage());
            } else {
                $this->addViolation($e, ['unit_price' => 'unitPrice', 'sku' => 'skuSearch', 'tiers' => 'tiers'], 'unitPrice');
            }

            return;
        }

        $this->cancelItem();
        $this->message = "Preço do SKU {$sku->code} salvo.";
    }

    public function removeItem(string $itemId, RemovePriceListItem $remove): void
    {
        $item = PriceListItem::query()
            ->where('price_list_id', $this->priceList()->id)
            ->findOrFail(UntrustedId::uuid($itemId, PriceListItem::class));

        try {
            $remove->handle($item);
        } catch (BusinessRuleViolation $e) {
            $this->addError('status', $e->getMessage());

            return;
        }

        if ($this->selectedSkuId === $item->sku_id) {
            $this->cancelItem();
        }

        $this->message = 'Item removido da tabela.';
    }

    public function render(Access $access): View
    {
        $list = PriceList::query()->with(['customerGroup', 'buyerCompany'])->findOrFail($this->priceListId);
        $search = trim($this->itemSearch);

        $items = PriceListItem::query()
            ->with(['sku.product', 'sku.salesUnit', 'tiers'])
            ->where('price_list_id', $list->id)
            ->when($search !== '', static function (Builder $q) use ($search): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $q->whereHas('sku', static fn (Builder $sku) => $sku->where('code', 'ilike', $like)
                    ->orWhereHas('product', static fn (Builder $p) => $p->where('name', 'ilike', $like)));
            })
            ->orderBy(Sku::query()->select('code')->whereColumn('skus.id', 'price_list_items.sku_id')->limit(1))
            ->paginate(25);

        $canManage = $access->can(Permission::PricingManage);
        $editable = $canManage && $list->status !== PriceListStatus::Archived;

        return view('livewire.staff.pricing.price-list-edit', [
            'list' => $list,
            'items' => $items,
            'canManage' => $canManage,
            'editable' => $editable,
            'scopeOptions' => PriceListsIndex::scopeOptions(),
            'groupOptions' => $editable && $this->scope === PriceListScope::Group->value
                ? CustomerGroup::query()->orderBy('name')->pluck('name', 'id')->all()
                : [],
            'companyOptions' => $editable && $this->scope === PriceListScope::Customer->value
                ? $this->companyOptions($this->companySearch, $this->buyerCompanyId !== '' ? $this->buyerCompanyId : null)
                : [],
            'selectedSku' => $this->selectedSku($this->selectedSkuId),
            'skuResults' => $editable && $this->selectedSkuId === null ? $this->searchSkus($this->skuSearch) : collect(),
            'maxTiers' => SetPriceListItem::MAX_TIERS,
        ]);
    }

    public static function skuLabel(Sku $sku): string
    {
        return $sku->product->name.($sku->variant_label !== null ? ' ('.$sku->variant_label.')' : '');
    }

    private function priceList(): PriceList
    {
        return PriceList::query()->findOrFail($this->priceListId);
    }

    private function fillItem(PriceListItem $item): void
    {
        $this->unitPrice = self::moneyInput($item->unit_price_cents);
        $this->tiers = array_values($item->tiers->map(static fn (PriceTier $tier): array => [
            'min' => self::quantityInput($tier->min_quantity),
            'price' => self::moneyInput($tier->unit_price_cents),
        ])->all());
    }
}

<?php

declare(strict_types=1);

use App\Data\Catalog\Portal\CatalogProductDetail;
use App\Data\Catalog\Portal\CatalogSku;
use App\Domain\Pricing\PriceSource;
use App\Enums\Pricing\PriceListScope;
use App\Enums\Pricing\PriceListStatus;
use App\Models\Identity\Membership;
use App\Services\Catalog\BuyerCatalog;
use Tests\Integration\Catalog\Portal\PortalCatalogFixtures as Catalog;
use Tests\Support\Builders\TenantScenario;

/*
| Preço exibido no catálogo por empresa compradora (ADR-011): condição
| negociada → tabela do cliente → tabela do grupo → tabela padrão. Faixas
| por volume valem para todas as unidades (ADR-010). Sem preço vigente,
| "Preço sob consulta".
*/

beforeEach(function (): void {
    $this->tenant = TenantScenario::create(name: 'Distribuidora Campo Alegre');
    $this->group = Catalog::group($this->tenant, 'ATACAREJO', 'Atacarejos regionais');

    $this->negotiatedCompany = $this->tenant->addBuyerCompany(['trade_name' => 'Atacarejo Boa Safra', 'customer_group_id' => $this->group->id]);
    $this->groupCompany = $this->tenant->addBuyerCompany(['trade_name' => 'Atacarejo Pé de Serra', 'customer_group_id' => $this->group->id]);
    $this->customerListCompany = $this->tenant->addBuyerCompany(['trade_name' => 'Supermercado Ipiranga', 'customer_group_id' => $this->group->id]);
    $this->defaultCompany = $this->tenant->addBuyerCompany(['trade_name' => 'Mercearia São Bento']);

    $product = Catalog::product($this->tenant, 'Arroz parboilizado 5 kg', ['brand' => 'Grão Nobre']);
    $this->rice = Catalog::sku($this->tenant, $product, 'ARZ-5', ['package' => 'Fardo com 6 pacotes']);

    Catalog::priceList($this->tenant, PriceListScope::Default, [$this->rice->id => 2_990]);
    Catalog::priceList($this->tenant, PriceListScope::Group, [$this->rice->id => 2_790], ['target' => $this->group]);
    Catalog::priceList($this->tenant, PriceListScope::Customer, [$this->rice->id => 2_850], ['target' => $this->customerListCompany]);
    Catalog::negotiated($this->tenant, $this->negotiatedCompany, $this->rice, 2_590);

    $this->skuFor = function (Membership $buyer, string $code): CatalogSku {
        $page = Catalog::browse($this->tenant, $buyer);

        foreach ($page->products->items() as $item) {
            foreach ($item->skus as $sku) {
                if ($sku->code === $code) {
                    return $sku;
                }
            }
        }

        throw new RuntimeException("SKU {$code} não listado.");
    };
});

it('exibe a cada empresa o preço pela precedência de origens', function (string $company, int $cents, PriceSource $source, string $label): void {
    $buyer = $this->tenant->addBuyerUser($this->{$company});
    $sku = ($this->skuFor)($buyer, 'ARZ-5');

    expect($sku->price?->unitPrice->cents)->toBe($cents)
        ->and($sku->price?->source)->toBe($source)
        ->and($sku->priceSourceLabel())->toBe($label)
        ->and($sku->quantity->toDatabase())->toBe('1.000')
        ->and($sku->price?->total->cents)->toBe($cents);
})->with([
    'condição negociada da empresa' => ['negotiatedCompany', 2_590, PriceSource::Negotiated, 'Condição negociada'],
    'tabela da empresa acima da tabela do grupo' => ['customerListCompany', 2_850, PriceSource::CustomerList, 'Tabela do cliente'],
    'tabela do grupo da empresa' => ['groupCompany', 2_790, PriceSource::GroupList, 'Tabela do grupo'],
    'tabela padrão para empresa sem grupo' => ['defaultCompany', 2_990, PriceSource::DefaultList, 'Tabela padrão'],
]);

it('não mistura preços entre empresas consultadas em sequência', function (): void {
    $negotiated = ($this->skuFor)($this->tenant->addBuyerUser($this->negotiatedCompany), 'ARZ-5');
    $group = ($this->skuFor)($this->tenant->addBuyerUser($this->groupCompany), 'ARZ-5');
    $default = ($this->skuFor)($this->tenant->addBuyerUser($this->defaultCompany), 'ARZ-5');

    expect([$negotiated->priceLabel(), $group->priceLabel(), $default->priceLabel()])
        ->toBe(['R$ 25,90', 'R$ 27,90', 'R$ 29,90']);
});

it('volta à tabela do grupo quando a condição negociada venceu', function (): void {
    $product = Catalog::product($this->tenant, 'Feijão carioca 1 kg');
    $beans = Catalog::sku($this->tenant, $product, 'FEI-1');
    Catalog::priceList($this->tenant, PriceListScope::Group, [$beans->id => 890], ['target' => $this->group]);
    Catalog::negotiated($this->tenant, $this->negotiatedCompany, $beans, 790, validUntil: now()->subDays(2)->toDateString());

    $sku = ($this->skuFor)($this->tenant->addBuyerUser($this->negotiatedCompany), 'FEI-1');

    expect($sku->priceLabel())->toBe('R$ 8,90')
        ->and($sku->priceSourceLabel())->toBe('Tabela do grupo');
});

it('indica preço sob consulta quando não há preço vigente', function (string $situation): void {
    $product = Catalog::product($this->tenant, 'Macarrão espaguete 500 g');
    $pasta = Catalog::sku($this->tenant, $product, 'MAC-500');

    match ($situation) {
        'sem tabela' => null,
        'tabela em rascunho' => Catalog::priceList($this->tenant, PriceListScope::Default, [$pasta->id => 450], ['status' => PriceListStatus::Draft]),
        'tabela expirada' => Catalog::priceList($this->tenant, PriceListScope::Default, [$pasta->id => 450], [
            'valid_from' => now()->subMonths(2)->toDateString(),
            'valid_until' => now()->subMonth()->toDateString(),
        ]),
        'tabela de outra empresa' => Catalog::priceList($this->tenant, PriceListScope::Customer, [$pasta->id => 450], ['target' => $this->customerListCompany]),
    };

    $sku = ($this->skuFor)($this->tenant->addBuyerUser($this->defaultCompany), 'MAC-500');

    expect($sku->hasPrice())->toBeFalse()
        ->and($sku->price)->toBeNull()
        ->and($sku->priceLabel())->toBe('Preço sob consulta')
        ->and($sku->priceSourceLabel())->toBeNull();
})->with(['sem tabela', 'tabela em rascunho', 'tabela expirada', 'tabela de outra empresa']);

it('aplica a faixa por volume a todas as unidades da quantidade conferida', function (): void {
    $product = Catalog::product($this->tenant, 'Refrigerante cola 2 l');
    $soda = Catalog::sku($this->tenant, $product, 'REF-2L', ['unit' => 'CX', 'minimum' => '6', 'multiple' => '6', 'package' => 'Caixa com 6 garrafas']);
    Catalog::priceList($this->tenant, PriceListScope::Default, [$soda->id => 1_200], [
        'tiers' => [$soda->id => [['12', 1_100], ['60', 990]]],
    ]);
    $buyer = $this->tenant->addBuyerUser($this->defaultCompany);

    $listed = ($this->skuFor)($buyer, 'REF-2L');
    $detail = fn (string $quantity): CatalogSku => $this->tenant->actingAs(
        $buyer,
        static fn (): CatalogProductDetail => app(BuyerCatalog::class)->product($product->id, [$soda->id => $quantity]),
    )->skus[0];

    $suggested = $detail('13');
    $volume = $detail('60');

    expect($listed->quantity->toDatabase())->toBe('6.000')
        ->and($listed->priceLabel())->toBe('R$ 12,00')
        ->and($listed->price?->total->format())->toBe('R$ 72,00')
        ->and($listed->price?->hasVolumeTier())->toBeFalse()
        // 13 não é múltiplo de 6: o preço é o da quantidade válida mais próxima (18).
        ->and($suggested->feedback?->valid)->toBeFalse()
        ->and($suggested->feedback?->suggestion?->toDatabase())->toBe('18.000')
        ->and($suggested->quantity->toDatabase())->toBe('18.000')
        ->and($suggested->priceLabel())->toBe('R$ 11,00')
        ->and($suggested->price?->total->format())->toBe('R$ 198,00')
        ->and($suggested->price?->tierMinQuantity?->toDatabase())->toBe('12.000')
        ->and($volume->feedback?->valid)->toBeTrue()
        ->and($volume->priceLabel())->toBe('R$ 9,90')
        ->and($volume->price?->total->format())->toBe('R$ 594,00')
        ->and($volume->price?->tierMinQuantity?->toDatabase())->toBe('60.000');
});

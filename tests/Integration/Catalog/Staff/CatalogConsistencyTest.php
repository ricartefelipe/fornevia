<?php

declare(strict_types=1);

use App\Actions\Catalog\SaveSku;
use App\Actions\Catalog\SaveUnitOfMeasure;
use App\Actions\Catalog\SetPrivateCatalogProducts;
use App\Data\Catalog\UnitOfMeasureData;
use App\Domain\Catalog\Exceptions\SkuPackagingInUse;
use App\Domain\Catalog\Exceptions\UnitDecimalsInUse;
use App\Domain\Shared\Quantity;
use App\Models\Catalog\PrivateCatalog;
use App\Models\Catalog\PrivateCatalogProduct;
use App\Models\Catalog\Sku;
use App\Models\Catalog\UnitOfMeasure;
use Brick\Math\BigDecimal;
use Tests\Integration\Catalog\Staff\CatalogFixtures as Fixtures;
use Tests\Integration\Pricing\PricingFixtures as Pricing;
use Tests\Support\Builders\TenantScenario;

/*
| Consistência entre catálogo e preços: embalagem de SKU com preço, redução
| de casas decimais de unidades em uso e alterações pontuais de catálogos
| privados que não desfazem alterações de outros usuários.
*/

beforeEach(function (): void {
    Pricing::freezeClock();
    $this->tenant = TenantScenario::create();
    $this->un = Fixtures::unit($this->tenant, 'UN');
    $this->cx = Fixtures::unit($this->tenant, 'CX');
    $this->kg = Fixtures::unit($this->tenant, 'KG');
    $this->pct = Fixtures::unit($this->tenant, 'PCT');

    $this->saveSku = fn (Sku $sku, array $overrides): Sku => $this->tenant->actingAs(
        $this->tenant->ownerMembership,
        fn (): Sku => app(SaveSku::class)->handle($sku->product()->firstOrFail(), $sku, Fixtures::skuData(
            $overrides['unit'] ?? $this->un,
            $sku->code,
            array_diff_key($overrides, ['unit' => true]),
        )),
    );

    $this->saveUnit = fn (UnitOfMeasure $unit, int $decimals): UnitOfMeasure => $this->tenant->actingAs(
        $this->tenant->ownerMembership,
        static fn (): UnitOfMeasure => app(SaveUnitOfMeasure::class)->handle($unit, new UnitOfMeasureData($unit->code, $unit->name, $decimals)),
    );
});

it('impede trocar unidade de venda ou fator de SKU que já tem preço em tabela', function (): void {
    $product = Fixtures::product($this->tenant, $this->un, ['name' => 'Luva nitrílica M']);
    $sku = Fixtures::sku($this->tenant, $product, $this->un, 'LUV-NIT-M');
    $list = Pricing::priceList($this->tenant, 'PADRAO');
    Pricing::item($this->tenant, $list, $sku, '0,85');

    expect(fn () => ($this->saveSku)($sku, ['unit' => $this->cx, 'conversionFactor' => BigDecimal::of('100')]))
        ->toThrow(SkuPackagingInUse::class)
        ->and(fn () => ($this->saveSku)($sku, ['conversionFactor' => BigDecimal::of('2')]))
        ->toThrow(SkuPackagingInUse::class);

    $updated = ($this->saveSku)($sku, ['gtin' => '7891234567895']);
    $stored = $this->tenant->system(static fn (): Sku => Sku::query()->findOrFail($sku->id));

    expect($updated->gtin)->toBe('7891234567895')
        ->and($stored->sales_unit_id)->toBe($this->un->id)
        ->and((string) $stored->conversion_factor->strippedOfTrailingZeros())->toBe('1');
});

it('impede trocar a embalagem de SKU com condição negociada ativa', function (): void {
    $product = Fixtures::product($this->tenant, $this->un, ['name' => 'Detergente neutro 5 L']);
    $sku = Fixtures::sku($this->tenant, $product, $this->un, 'DET-5L');
    $company = Pricing::company($this->tenant, 'Atacadista Serra Azul');
    Pricing::negotiated($this->tenant, $company, $sku, '18,90', '2026-01-01');

    expect(fn () => ($this->saveSku)($sku, ['unit' => $this->cx]))->toThrow(SkuPackagingInUse::class);
});

it('permite trocar a embalagem de SKU ainda sem preço', function (): void {
    $product = Fixtures::product($this->tenant, $this->un, ['name' => 'Copo descartável 200 ml']);
    $sku = Fixtures::sku($this->tenant, $product, $this->un, 'COPO-200');

    $saved = ($this->saveSku)($sku, ['unit' => $this->cx, 'conversionFactor' => BigDecimal::of('100')]);

    expect($saved->sales_unit_id)->toBe($this->cx->id)
        ->and((string) $saved->conversion_factor->strippedOfTrailingZeros())->toBe('100');
});

it('recusa reduzir casas decimais da unidade base quando a conversão de um SKU deixaria de ser exata', function (): void {
    $product = Fixtures::product($this->tenant, $this->kg, ['name' => 'Farinha de trigo']);
    Fixtures::sku($this->tenant, $product, $this->pct, 'FAR-500G', ['conversion_factor' => BigDecimal::of('0.5')]);

    expect(fn () => ($this->saveUnit)($this->kg, 0))->toThrow(UnitDecimalsInUse::class, 'FAR-500G');

    $stored = $this->tenant->system(fn (): UnitOfMeasure => UnitOfMeasure::query()->findOrFail($this->kg->id));
    expect($stored->decimals)->toBe(3);
});

it('aceita reduzir casas decimais da unidade base quando as quantidades convertidas continuam exatas', function (): void {
    $product = Fixtures::product($this->tenant, $this->kg, ['name' => 'Açúcar cristal']);
    Fixtures::sku($this->tenant, $product, $this->pct, 'ACU-5KG', ['conversion_factor' => BigDecimal::of('5')]);

    expect(($this->saveUnit)($this->kg, 0)->decimals)->toBe(0);
});

it('recusa reduzir casas decimais quando uma faixa de preço deixaria de ser representável', function (): void {
    $product = Fixtures::product($this->tenant, $this->kg, ['name' => 'Café torrado']);
    $sku = Fixtures::sku($this->tenant, $product, $this->kg, 'CAF-GRANEL');
    $list = Pricing::priceList($this->tenant, 'PADRAO');
    Pricing::item($this->tenant, $list, $sku, '42,00', [['2.5', '39,90']]);

    expect(fn () => ($this->saveUnit)($this->kg, 0))->toThrow(UnitDecimalsInUse::class, 'CAF-GRANEL');
});

it('aplica inclusões e remoções pontuais sobre o conteúdo atual do catálogo privado', function (): void {
    $products = collect(['Luva P', 'Luva M', 'Luva G'])->map(
        fn (string $name) => Fixtures::product($this->tenant, $this->un, ['name' => $name]),
    );
    $catalog = $this->tenant->system(static fn (): PrivateCatalog => PrivateCatalog::query()->create([
        'code' => 'REDE-PREMIUM', 'name' => 'Rede Premium', 'is_active' => true,
    ]));
    $change = fn (array $add = [], array $remove = []): array => $this->tenant->actingAs(
        $this->tenant->ownerMembership,
        static fn (): array => app(SetPrivateCatalogProducts::class)->change($catalog, $add, $remove),
    );
    $contents = fn (): array => $this->tenant->system(static fn (): array => PrivateCatalogProduct::query()
        ->where('private_catalog_id', $catalog->id)->pluck('product_id')->sort()->values()->all());

    // Duas telas abertas com o catálogo vazio: cada uma inclui um produto.
    $change(add: [$products[0]->id]);
    $change(add: [$products[1]->id]);
    // Uma terceira remove apenas o seu alvo.
    $change(add: [$products[2]->id]);
    $change(remove: [$products[0]->id]);

    expect($contents())->toBe(collect([$products[1]->id, $products[2]->id])->sort()->values()->all());
});

it('isola cada quantidade convertida na verificação da unidade base', function (array $sku): void {
    $product = Fixtures::product($this->tenant, $this->kg, ['name' => 'Arroz tipo 1']);
    Fixtures::sku($this->tenant, $product, $this->pct, 'ARZ-500G', $sku);

    expect(fn () => ($this->saveUnit)($this->kg, 0))->toThrow(UnitDecimalsInUse::class, 'ARZ-500G');
})->with([
    'só o múltiplo fracionário' => [['conversion_factor' => BigDecimal::of('0.5'), 'min_order_quantity' => Quantity::of(2), 'order_multiple' => Quantity::of(1)]],
    'só o mínimo fracionário' => [['conversion_factor' => BigDecimal::of('0.5'), 'min_order_quantity' => Quantity::of(1), 'order_multiple' => Quantity::of(2)]],
]);

it('não considera SKUs de produtos com outra unidade base', function (): void {
    $flour = Fixtures::product($this->tenant, $this->kg, ['name' => 'Farinha de trigo']);
    Fixtures::sku($this->tenant, $flour, $this->pct, 'FAR-500G', ['conversion_factor' => BigDecimal::of('0.5')]);
    $liter = Fixtures::unit($this->tenant, 'L');

    expect(($this->saveUnit)($liter, 0)->decimals)->toBe(0);
});

it('permite trocar a embalagem quando a única condição negociada foi revogada', function (): void {
    $product = Fixtures::product($this->tenant, $this->un, ['name' => 'Detergente neutro 2 L']);
    $sku = Fixtures::sku($this->tenant, $product, $this->un, 'DET-2L');
    $company = Pricing::company($this->tenant, 'Mercado Bom Preço');
    $negotiated = Pricing::negotiated($this->tenant, $company, $sku, '9,90', '2026-01-01');
    $this->tenant->actingAs($this->tenant->ownerMembership, static fn () => app(App\Actions\Pricing\RevokeNegotiatedPrice::class)->handle($negotiated, 'Contrato encerrado'));

    expect(($this->saveSku)($sku, ['unit' => $this->cx, 'conversionFactor' => BigDecimal::of('6')])->sales_unit_id)->toBe($this->cx->id);
});

it('recusa faixa com mais casas decimais do que a unidade de venda', function (): void {
    $product = Fixtures::product($this->tenant, $this->un, ['name' => 'Caneta esferográfica']);
    $sku = Fixtures::sku($this->tenant, $product, $this->un, 'CAN-AZ');
    $list = Pricing::priceList($this->tenant, 'PADRAO', activate: false);

    expect(fn () => Pricing::item($this->tenant, $list, $sku, '1,50', [['10', '1,40'], ['2,5', '1,30']]))
        ->toThrow(App\Domain\Pricing\Exceptions\InvalidPriceTiers::class);
});

it('recusa gravar preço quando a embalagem mudou desde a abertura do formulário', function (): void {
    $product = Fixtures::product($this->tenant, $this->un, ['name' => 'Luva nitrílica G']);
    $sku = Fixtures::sku($this->tenant, $product, $this->un, 'LUV-NIT-G');
    $seen = $sku->packagingFingerprint();
    ($this->saveSku)($sku, ['unit' => $this->cx, 'conversionFactor' => BigDecimal::of('100')]);
    $list = Pricing::priceList($this->tenant, 'PADRAO', activate: false);
    $company = Pricing::company($this->tenant, 'Clínica Sol');

    expect(fn () => $this->tenant->actingAs($this->tenant->ownerMembership, static fn () => app(App\Actions\Pricing\SetPriceListItem::class)
        ->handle($list, $sku, App\Domain\Shared\Money::parse('0,85'), [], $seen)))
        ->toThrow(App\Domain\Pricing\Exceptions\SkuPackagingChanged::class, 'CX')
        ->and(fn () => $this->tenant->actingAs($this->tenant->ownerMembership, static fn () => app(App\Actions\Pricing\SaveNegotiatedPrice::class)
            ->handle($company, $sku, App\Domain\Shared\Money::parse('0,80'), Carbon\CarbonImmutable::parse('2026-11-17'), null, null, $seen)))
        ->toThrow(App\Domain\Pricing\Exceptions\SkuPackagingChanged::class);

    $current = $this->tenant->system(static fn () => Sku::query()->findOrFail($sku->id))->packagingFingerprint();
    $item = $this->tenant->actingAs($this->tenant->ownerMembership, static fn () => app(App\Actions\Pricing\SetPriceListItem::class)
        ->handle($list, $sku, App\Domain\Shared\Money::parse('85,00'), [], $current));

    expect($item->unit_price_cents->cents)->toBe(8500);
});

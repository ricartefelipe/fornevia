<?php

declare(strict_types=1);

use App\Data\Catalog\Portal\CatalogFilters;
use App\Data\Catalog\Portal\CatalogPage;
use App\Data\Catalog\Portal\CatalogProductDetail;
use App\Data\Catalog\Portal\CategoryOption;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Catalog\MediaKind;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\SkuStatus;
use App\Enums\Pricing\PriceListScope;
use App\Models\Catalog\Product;
use App\Services\Catalog\BuyerCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Integration\Catalog\Portal\PortalCatalogFixtures as Catalog;
use Tests\Support\Builders\TenantScenario;

/*
| Listagem do catálogo do portal: busca, categoria, paginação, carregamento
| antecipado (sem lazy loading), uma única resolução de preços por página e
| ausência de dados internos (custo, margem, política comercial).
*/

beforeEach(function (): void {
    Storage::fake('private');

    $this->tenant = TenantScenario::create(name: 'Distribuidora Rio Claro');
    $this->company = $this->tenant->addBuyerCompany(['trade_name' => 'Mercado Bom Preço']);
    $this->buyer = $this->tenant->addBuyerUser($this->company);
});

/**
 * Converte DTOs (inclusive aninhados) em arrays para inspeção do conteúdo
 * exposto. Identificadores são omitidos para que a busca por valores
 * numéricos não colida com trechos de UUID.
 */
function catalogoExportado(mixed $value): mixed
{
    if (is_object($value) && ! $value instanceof UnitEnum) {
        $value = $value instanceof Money ? ['cents' => $value->cents]
            : ($value instanceof Quantity ? ['value' => $value->toDatabase()] : get_object_vars($value));
        unset($value['id']);
    }

    return is_array($value) ? array_map('catalogoExportado', $value) : $value;
}

it('busca por nome, marca e código do SKU, sem diferenciar maiúsculas', function (CatalogFilters $filters, array $expected): void {
    $coffee = Catalog::product($this->tenant, 'Café torrado e moído 500 g', ['brand' => 'Serra Dourada']);
    Catalog::sku($this->tenant, $coffee, 'CAF-500');
    $sugar = Catalog::product($this->tenant, 'Açúcar cristal 5 kg', ['brand' => 'Doce Vale']);
    Catalog::sku($this->tenant, $sugar, 'ACU-5000');
    Catalog::sku($this->tenant, $sugar, 'ACU-INATIVO', ['status' => SkuStatus::Inactive]);
    Catalog::simpleProduct($this->tenant, 'Filtro de papel 103', 'FIL-103', ['brand' => 'Serra Dourada']);

    expect(Catalog::names(Catalog::browse($this->tenant, $this->buyer, $filters)))->toBe($expected);
})->with([
    'nome' => [new CatalogFilters('torrado'), ['Café torrado e moído 500 g']],
    'marca' => [new CatalogFilters('serra dourada'), ['Café torrado e moído 500 g', 'Filtro de papel 103']],
    'código do SKU' => [new CatalogFilters('acu-50'), ['Açúcar cristal 5 kg']],
    'código de SKU inativo não encontra' => [new CatalogFilters('ACU-INATIVO'), []],
    'curinga digitado é tratado como texto' => [new CatalogFilters('%'), []],
    'espaços nas extremidades são ignorados' => [new CatalogFilters('   FIL-103  '), ['Filtro de papel 103']],
]);

it('filtra por categoria incluindo subcategorias e lista apenas categorias com produtos visíveis', function (): void {
    $food = Catalog::category($this->tenant, 'Mercearia', sortOrder: 1);
    $grains = Catalog::category($this->tenant, 'Grãos', $food);
    $cleaning = Catalog::category($this->tenant, 'Limpeza', sortOrder: 2);
    $hidden = Catalog::category($this->tenant, 'Linha institucional', sortOrder: 3);

    Catalog::simpleProduct($this->tenant, 'Arroz agulhinha 5 kg', 'ARR-5', ['category' => $grains]);
    Catalog::simpleProduct($this->tenant, 'Óleo de soja 900 ml', 'OLE-900', ['category' => $food]);
    Catalog::simpleProduct($this->tenant, 'Detergente neutro 500 ml', 'DET-500', ['category' => $cleaning]);
    Catalog::simpleProduct($this->tenant, 'Produto em revisão', 'REV-001', ['category' => $hidden, 'status' => ProductStatus::Draft]);

    $all = Catalog::browse($this->tenant, $this->buyer);
    $byFood = Catalog::browse($this->tenant, $this->buyer, new CatalogFilters('', $food->id));
    $byGrains = Catalog::browse($this->tenant, $this->buyer, new CatalogFilters('', $grains->id));
    $unavailable = Catalog::browse($this->tenant, $this->buyer, new CatalogFilters('', $hidden->id));

    expect(array_map(static fn (CategoryOption $o): string => $o->label(), $all->categories))
        ->toBe(['Mercearia', '— Grãos', 'Limpeza'])
        ->and(Catalog::names($byFood))->toBe(['Arroz agulhinha 5 kg', 'Óleo de soja 900 ml'])
        ->and(Catalog::names($byGrains))->toBe(['Arroz agulhinha 5 kg'])
        // Categoria sem produtos visíveis deixa de filtrar (não revela a existência).
        ->and($unavailable->filters->categoryId)->toBeNull()
        ->and(Catalog::names($unavailable))->toHaveCount(3);
});

it('pagina a listagem com no máximo 24 produtos por página', function (): void {
    foreach (range(1, 30) as $n) {
        Catalog::simpleProduct($this->tenant, sprintf('Parafuso sextavado M%02d', $n), sprintf('PAR-%02d', $n));
    }

    $first = Catalog::browse($this->tenant, $this->buyer);
    $second = Catalog::browse($this->tenant, $this->buyer, page: 2);

    expect($first->products->total())->toBe(30)
        ->and($first->products->perPage())->toBe(BuyerCatalog::PER_PAGE)
        ->and(BuyerCatalog::PER_PAGE)->toBeLessThanOrEqual(25)
        ->and($first->products->items())->toHaveCount(24)
        ->and(Catalog::names($first)[0])->toBe('Parafuso sextavado M01')
        ->and($second->products->items())->toHaveCount(6)
        ->and(Catalog::names($second))->toBe(array_map(static fn (int $n): string => sprintf('Parafuso sextavado M%02d', $n), range(25, 30)));
});

it('não expõe custo, margem nem política comercial ao comprador', function (): void {
    $product = Catalog::product($this->tenant, 'Cimento CP II 50 kg', ['brand' => 'Pedra Forte']);
    $sku = Catalog::sku($this->tenant, $product, 'CIM-50', ['package' => 'Saco 50 kg']);
    Catalog::priceList($this->tenant, PriceListScope::Default, [$sku->id => 3_890]);
    Catalog::internalCosts($this->tenant, $sku, costCents: 2_731, minimumPriceCents: 3_457, minimumMarginBps: 1_234);

    $page = Catalog::browse($this->tenant, $this->buyer);
    $detail = $this->tenant->actingAs($this->buyer, static fn (): CatalogProductDetail => app(BuyerCatalog::class)->product($product->id));
    $exported = json_encode(catalogoExportado([$page->products->items(), $detail]), JSON_THROW_ON_ERROR);

    expect($page->products->items()[0]->skus[0]->price?->unitPrice->cents)->toBe(3_890)
        ->and($exported)->not->toContain('2731')
        ->and($exported)->not->toContain('3457')
        ->and($exported)->not->toContain('1234')
        ->and(strtolower($exported))->not->toContain('cost')
        ->and(strtolower($exported))->not->toContain('margin')
        ->and(strtolower($exported))->not->toContain('min_unit_price')
        ->and(strtolower($exported))->not->toContain('reference');
});

it('carrega relações antecipadamente e resolve os preços em uma única consulta por página', function (): void {
    expect(Model::preventsLazyLoading())->toBeTrue();

    $category = Catalog::category($this->tenant, 'Ferragens');
    $create = function (int $from, int $to) use ($category): void {
        foreach (range($from, $to) as $n) {
            $product = Catalog::product($this->tenant, sprintf('Dobradiça modelo %02d', $n), ['category' => $category, 'brand' => 'Metal Sul']);
            $first = Catalog::sku($this->tenant, $product, sprintf('DOB-%02d-A', $n), ['unit' => 'CX', 'minimum' => '2', 'multiple' => '2']);
            $second = Catalog::sku($this->tenant, $product, sprintf('DOB-%02d-B', $n));
            Catalog::priceList($this->tenant, PriceListScope::Default, [$first->id => 1_500 + $n, $second->id => 900 + $n]);
            Catalog::media($this->tenant, $product, MediaKind::Image, "dobradica-{$n}.png", 'image/png', "imagem {$n}");
            Catalog::media($this->tenant, $product, MediaKind::Document, "ficha-{$n}.pdf", 'application/pdf', "ficha {$n}");
        }
    };

    $measure = function (): array {
        return $this->tenant->actingAs($this->buyer, static function (): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $page = app(BuyerCatalog::class)->browse(new CatalogFilters);
            $queries = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();

            return [$page, $queries];
        });
    };

    $create(1, 2);
    /** @var CatalogPage $small */
    [$small, $smallQueries] = $measure();

    $create(3, 10);
    /** @var CatalogPage $large */
    [$large, $largeQueries] = $measure();

    $pricingQueries = static fn (array $queries): int => count(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"negotiated_prices"')));

    expect($small->products->items())->toHaveCount(2)
        ->and($large->products->items())->toHaveCount(10)
        ->and(count($largeQueries))->toBe(count($smallQueries))
        ->and($pricingQueries($largeQueries))->toBe(1)
        ->and($large->products->items()[9]->thumbnail?->name)->toBe('dobradica-10.png')
        ->and($large->products->items()[9]->skus[0]->unitCode)->toBe('CX')
        ->and($large->products->items()[9]->skus[0]->price?->unitPrice->cents)->toBe(1_510);
});

it('mantém produtos sem SKU ativo fora da contagem e da paginação', function (): void {
    Catalog::simpleProduct($this->tenant, 'Tinta acrílica 18 l', 'TIN-18');
    $withoutSku = Catalog::product($this->tenant, 'Rolo de lã 23 cm');
    Catalog::sku($this->tenant, $withoutSku, 'ROL-23', ['status' => SkuStatus::Inactive]);
    Catalog::product($this->tenant, 'Pincel 2 polegadas');

    $page = Catalog::browse($this->tenant, $this->buyer);

    expect($page->products->total())->toBe(1)
        ->and(Catalog::names($page))->toBe(['Tinta acrílica 18 l'])
        ->and($this->tenant->system(static fn (): int => Product::query()->count()))->toBe(3);
});

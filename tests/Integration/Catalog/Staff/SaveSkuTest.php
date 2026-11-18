<?php

declare(strict_types=1);

use App\Actions\Catalog\SaveSku;
use App\Data\Catalog\CommercialPolicyData;
use App\Data\Catalog\SkuData;
use App\Domain\Catalog\Exceptions\CatalogIdentifierInUse;
use App\Domain\Catalog\Exceptions\CatalogItemArchived;
use App\Domain\Catalog\Exceptions\InvalidCatalogData;
use App\Domain\Catalog\Exceptions\ProductRequiresActiveSku;
use App\Domain\Shared\Money;
use App\Domain\Shared\Percentage;
use App\Domain\Shared\Quantity;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\SkuStatus;
use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Models\Catalog\Product;
use App\Models\Catalog\Sku;
use App\Models\Catalog\SkuCommercialPolicy;
use App\Models\Catalog\SkuCost;
use App\Models\Identity\Membership;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Catalog\Staff\CatalogFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->save = fn (TenantScenario $tenant, Membership $actor, Product $product, ?Sku $sku, SkuData $data): Sku => $tenant->actingAs(
        $actor,
        static fn (): Sku => app(SaveSku::class)->handle($product, $sku, $data),
    );

    $this->stored = fn (TenantScenario $tenant, string $id): Sku => $tenant->system(
        static fn (): Sku => Sku::query()->findOrFail($id),
    );

    $this->costs = fn (TenantScenario $tenant, Sku $sku): array => $tenant->system(
        static fn (): array => SkuCost::query()->where('sku_id', $sku->id)->orderBy('effective_from')->get()
            ->map(static fn (SkuCost $cost): int => $cost->cost_cents->cents)
            ->all(),
    );

    $this->tenant = TenantScenario::create();
    $this->un = Fixtures::unit($this->tenant, 'UN');
    $this->cx = Fixtures::unit($this->tenant, 'CX');
    $this->kg = Fixtures::unit($this->tenant, 'KG');
    $this->product = Fixtures::product($this->tenant, $this->un, ['name' => 'Copo descartável 180 ml']);
});

it('cadastra SKU com embalagem, conversão, atributos e auditoria', function (): void {
    $sku = ($this->save)($this->tenant, $this->tenant->ownerMembership, $this->product, null, Fixtures::skuData($this->cx, ' copo-180-cx ', [
        'gtin' => '7891234567895',
        'variantLabel' => 'Transparente',
        'attributes' => ['Cor' => ' Transparente ', 'Material' => 'Polipropileno'],
        'packageLabel' => 'Caixa com 2.500',
        'conversionFactor' => BigDecimal::of('2500'),
        'minOrderQuantity' => Quantity::of(2),
        'orderMultiple' => Quantity::of(1),
        'weightGrams' => 4200,
    ]));

    $stored = ($this->stored)($this->tenant, $sku->id);

    expect($stored->code)->toBe('COPO-180-CX')
        ->and($stored->product_id)->toBe($this->product->id)
        ->and($stored->sales_unit_id)->toBe($this->cx->id)
        ->and($stored->gtin)->toBe('7891234567895')
        ->and($stored->attributes)->toEqual(['Cor' => 'Transparente', 'Material' => 'Polipropileno'])
        ->and((string) $stored->conversion_factor)->toBe('2500.000000')
        ->and($stored->min_order_quantity->toDatabase())->toBe('2.000')
        ->and($stored->order_multiple->toDatabase())->toBe('1.000')
        ->and($stored->package_label)->toBe('Caixa com 2.500')
        ->and($stored->weight_grams)->toBe(4200)
        ->and($stored->status)->toBe(SkuStatus::Active)
        ->and($stored->toBaseQuantity(Quantity::of(3))->format())->toBe('7.500');

    $log = Fixtures::audits($this->tenant, 'sku.created')->sole();

    expect($log->subject_type)->toBe('sku')
        ->and($log->data['product_id'])->toBe($this->product->id)
        ->and($log->data['restricted_changes'])->toBe([])
        ->and($log->data['after'])->toMatchArray(['code' => 'COPO-180-CX', 'conversion_factor' => '2500.000000', 'min_order_quantity' => '2.000']);
});

it('grava atributos vazios como objeto JSON e ignora reordenação dos atributos', function (): void {
    $owner = $this->tenant->ownerMembership;
    $empty = ($this->save)($this->tenant, $owner, $this->product, null, Fixtures::skuData($this->un, 'COPO-180-UN'));
    $withAttributes = ($this->save)($this->tenant, $owner, $this->product, null, Fixtures::skuData($this->un, 'COPO-180-AZ', [
        'attributes' => ['Tamanho' => '180 ml', 'Cor' => 'Azul'],
    ]));

    $types = $this->tenant->system(static fn (): array => DB::table('skus')->orderBy('code')
        ->get(['code', DB::raw('jsonb_typeof(attributes) as kind')])
        ->mapWithKeys(static fn (object $row): array => [$row->code => $row->kind])
        ->all());

    ($this->save)($this->tenant, $owner, $this->product, $withAttributes, Fixtures::skuData($this->un, 'COPO-180-AZ', [
        'attributes' => ['Cor' => 'Azul', 'Tamanho' => '180 ml'],
    ]));

    expect($types)->toBe(['COPO-180-AZ' => 'object', 'COPO-180-UN' => 'object'])
        ->and(($this->stored)($this->tenant, $empty->id)->attributes)->toBe([])
        ->and(Fixtures::audits($this->tenant, 'sku.updated'))->toBeEmpty();
});

it('recusa código de SKU repetido no tenant e aceita o mesmo código em outro tenant', function (): void {
    $other = TenantScenario::create();
    $otherUnit = Fixtures::unit($other, 'UN');
    $otherProduct = Fixtures::product($other, $otherUnit);
    Fixtures::sku($this->tenant, $this->product, $this->un, 'COPO-180');

    try {
        ($this->save)($this->tenant, $this->tenant->ownerMembership, $this->product, null, Fixtures::skuData($this->un, 'copo-180'));
        $this->fail('Código repetido deveria ser recusado.');
    } catch (CatalogIdentifierInUse $e) {
        expect($e->field())->toBe('code')
            ->and($e->getMessage())->toBe('Já existe um SKU com o código COPO-180.');
    }

    $foreign = ($this->save)($other, $other->ownerMembership, $otherProduct, null, Fixtures::skuData($otherUnit, 'COPO-180'));

    expect($foreign->code)->toBe('COPO-180');
});

it('valida GTIN, quantidades, casas decimais e conversão para a unidade base', function (string $unit, array $overrides, string $field): void {
    $salesUnit = $this->{$unit};

    try {
        ($this->save)($this->tenant, $this->tenant->ownerMembership, $this->product, null, Fixtures::skuData($salesUnit, 'COPO-TESTE', $overrides));
        $this->fail('Dados inválidos deveriam ser recusados.');
    } catch (InvalidCatalogData $e) {
        expect($e->errorCode())->toBe('invalid_catalog_data')
            ->and($e->fieldName())->toBe($field);
    }

    expect($this->tenant->system(static fn (): int => Sku::query()->count()))->toBe(0);
})->with([
    'GTIN curto' => ['un', ['gtin' => '7891234'], 'gtin'],
    'GTIN com letras' => ['un', ['gtin' => '78912345A7895'], 'gtin'],
    'GTIN longo' => ['un', ['gtin' => '789123456789512'], 'gtin'],
    'código com espaço' => ['un', ['code' => 'COPO 180'], 'code'],
    'mínimo fracionado em UN' => ['un', ['minOrderQuantity' => Quantity::of('1,5')], 'min_order_quantity'],
    'múltiplo fracionado em CX' => ['cx', ['orderMultiple' => Quantity::of('0,5')], 'order_multiple'],
    'mínimo zero' => ['un', ['minOrderQuantity' => Quantity::zero()], 'min_order_quantity'],
    'múltiplo negativo' => ['un', ['orderMultiple' => Quantity::of('-1')], 'order_multiple'],
    'fator zero' => ['un', ['conversionFactor' => BigDecimal::zero()], 'conversion_factor'],
    'fator com 7 casas' => ['un', ['conversionFactor' => BigDecimal::of('0.1234567')], 'conversion_factor'],
    'fração de KG em produto vendido em UN' => ['kg', ['minOrderQuantity' => Quantity::of('0,5'), 'orderMultiple' => Quantity::of('0,5')], 'conversion_factor'],
    'fator gera fração da unidade base' => ['cx', ['conversionFactor' => BigDecimal::of('2.5')], 'conversion_factor'],
    'peso negativo' => ['un', ['weightGrams' => -10], 'weight_grams'],
    'atributo sem valor' => ['un', ['attributes' => ['Cor' => ' ']], 'attributes'],
    'atributos demais' => ['un', ['attributes' => array_combine(array_map(static fn (int $i): string => "Atributo {$i}", range(1, 21)), array_fill(0, 21, 'valor'))], 'attributes'],
]);

it('aceita frações quando a unidade de venda e a base permitem', function (): void {
    $product = Fixtures::product($this->tenant, $this->kg, ['name' => 'Café torrado e moído a granel']);

    $sku = ($this->save)($this->tenant, $this->tenant->ownerMembership, $product, null, Fixtures::skuData($this->kg, 'CAF-GRANEL', [
        'minOrderQuantity' => Quantity::of('0,5'),
        'orderMultiple' => Quantity::of('0,25'),
    ]));

    $rule = $this->tenant->system(static fn () => Sku::query()->with('salesUnit')->findOrFail($sku->id)->purchaseRule());

    expect($rule->accepts(Quantity::of('1,75')))->toBeTrue()
        ->and($rule->accepts(Quantity::of('0,6')))->toBeFalse();
});

it('recusa unidade de venda de outro tenant e SKU de outro produto', function (): void {
    $other = TenantScenario::create();
    $foreignUnit = Fixtures::unit($other, 'UN');
    $anotherProduct = Fixtures::product($this->tenant, $this->un, ['name' => 'Prato descartável']);
    $anotherSku = Fixtures::sku($this->tenant, $anotherProduct, $this->un, 'PRATO-15');

    expect(fn () => ($this->save)($this->tenant, $this->tenant->ownerMembership, $this->product, null, Fixtures::skuData($foreignUnit, 'COPO-180')))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->save)($this->tenant, $this->tenant->ownerMembership, $this->product, $anotherSku, Fixtures::skuData($this->un, 'PRATO-15')))
        ->toThrow(ModelNotFoundException::class);

    expect(($this->stored)($this->tenant, $anotherSku->id)->product_id)->toBe($anotherProduct->id);
});

it('não altera SKU de produto arquivado nem SKU arquivado', function (): void {
    $archivedProduct = Fixtures::product($this->tenant, $this->un, ['status' => ProductStatus::Archived, 'archived_at' => now()]);
    $archivedSku = Fixtures::sku($this->tenant, $this->product, $this->un, 'COPO-ANTIGO', ['status' => SkuStatus::Archived]);

    expect(fn () => ($this->save)($this->tenant, $this->tenant->ownerMembership, $archivedProduct, null, Fixtures::skuData($this->un, 'NOVO-1')))
        ->toThrow(CatalogItemArchived::class, 'O produto está arquivado')
        ->and(fn () => ($this->save)($this->tenant, $this->tenant->ownerMembership, $this->product, $archivedSku, Fixtures::skuData($this->un, 'COPO-ANTIGO')))
        ->toThrow(CatalogItemArchived::class, 'O SKU está arquivado');
});

it('mantém ao menos um SKU ativo em produto ativo', function (): void {
    $product = Fixtures::product($this->tenant, $this->un, ['status' => ProductStatus::Active]);
    $only = Fixtures::sku($this->tenant, $product, $this->un, 'COPO-ATIVO');

    expect(fn () => ($this->save)($this->tenant, $this->tenant->ownerMembership, $product, $only, Fixtures::skuData($this->un, 'COPO-ATIVO', ['status' => SkuStatus::Inactive])))
        ->toThrow(ProductRequiresActiveSku::class);

    expect(($this->stored)($this->tenant, $only->id)->status)->toBe(SkuStatus::Active);

    Fixtures::sku($this->tenant, $product, $this->un, 'COPO-RESERVA');
    ($this->save)($this->tenant, $this->tenant->ownerMembership, $product, $only, Fixtures::skuData($this->un, 'COPO-ATIVO', ['status' => SkuStatus::Inactive]));

    expect(($this->stored)($this->tenant, $only->id)->status)->toBe(SkuStatus::Inactive);
});

it('registra nova linha de custo somente quando o valor muda, sem expor valores na auditoria', function (): void {
    $clock = Fixtures::freezeClock('2026-11-17 10:00:00');
    $owner = $this->tenant->ownerMembership;
    $sku = ($this->save)($this->tenant, $owner, $this->product, null, Fixtures::skuData($this->un, 'COPO-180', ['cost' => Money::parse('1.234,56')]));

    $clock->advance('PT1H');
    ($this->save)($this->tenant, $owner, $this->product, $sku, Fixtures::skuData($this->un, 'COPO-180', ['cost' => Money::ofCents(123456)]));

    $clock->advance('PT1H');
    ($this->save)($this->tenant, $owner, $this->product, $sku, Fixtures::skuData($this->un, 'COPO-180', ['cost' => Money::parse('1.199,90')]));

    $latest = $this->tenant->system(static fn (): SkuCost => SkuCost::query()->where('sku_id', $sku->id)->orderByDesc('effective_from')->firstOrFail());

    expect(($this->costs)($this->tenant, $sku))->toBe([123456, 119990])
        ->and($latest->recorded_by_user_id)->toBe($this->tenant->owner->id)
        ->and($latest->effective_from->equalTo($clock->now()))->toBeTrue();

    $updates = Fixtures::audits($this->tenant, 'sku.updated');

    expect($updates)->toHaveCount(1)
        ->and($updates->sole()->data['restricted_changes'])->toBe(['cost'])
        ->and(json_encode(Fixtures::audits($this->tenant, 'sku.created')->sole()->data))->not->toContain('123456')
        ->and(json_encode($updates->sole()->data))->not->toContain('119990');
});

it('exige catalog.view_costs para gravar custo ou política comercial', function (): void {
    $editor = Fixtures::staffWith($this->tenant, [Permission::CatalogView, Permission::CatalogManage]);

    expect(fn () => ($this->save)($this->tenant, $editor, $this->product, null, Fixtures::skuData($this->un, 'COPO-180', ['cost' => Money::ofCents(1000)])))
        ->toThrow(AuthorizationException::class, 'Ver custos e margens')
        ->and(fn () => ($this->save)($this->tenant, $editor, $this->product, null, Fixtures::skuData($this->un, 'COPO-180', [
            'commercialPolicy' => new CommercialPolicyData(Money::ofCents(500)),
        ])))
        ->toThrow(AuthorizationException::class);

    expect($this->tenant->system(static fn (): int => Sku::query()->count()))->toBe(0);

    // Sem dados internos, quem gerencia o catálogo cadastra normalmente.
    $sku = ($this->save)($this->tenant, $editor, $this->product, null, Fixtures::skuData($this->un, 'COPO-180'));

    expect(($this->costs)($this->tenant, $sku))->toBe([]);
});

it('exige catalog.manage mesmo para quem vê custos', function (SystemRole $role): void {
    expect(fn () => ($this->save)($this->tenant, $this->tenant->addStaff($role), $this->product, null, Fixtures::skuData($this->un, 'COPO-180', ['cost' => Money::ofCents(1000)])))
        ->toThrow(AuthorizationException::class);

    expect($this->tenant->system(static fn (): int => Sku::query()->count()))->toBe(0);
})->with([
    'gerente comercial' => [SystemRole::SalesManager],
    'representante' => [SystemRole::SalesRep],
    'estoque' => [SystemRole::Warehouse],
]);

it('grava a política comercial e altera a versão de preços somente quando ela muda', function (): void {
    $owner = $this->tenant->ownerMembership;
    $initial = Fixtures::priceVersion($this->tenant);
    $policy = new CommercialPolicyData(Money::parse('2,35'), Percentage::parse('12,5'));

    $sku = ($this->save)($this->tenant, $owner, $this->product, null, Fixtures::skuData($this->un, 'COPO-180', ['commercialPolicy' => $policy]));
    $afterCreate = Fixtures::priceVersion($this->tenant);

    ($this->save)($this->tenant, $owner, $this->product, $sku, Fixtures::skuData($this->un, 'COPO-180', ['commercialPolicy' => $policy]));
    $afterSame = Fixtures::priceVersion($this->tenant);

    $stored = $this->tenant->system(static fn (): SkuCommercialPolicy => SkuCommercialPolicy::query()->where('sku_id', $sku->id)->firstOrFail());

    ($this->save)($this->tenant, $owner, $this->product, $sku, Fixtures::skuData($this->un, 'COPO-180', ['commercialPolicy' => new CommercialPolicyData]));

    expect($afterCreate)->toBe($initial + 1)
        ->and($afterSame)->toBe($afterCreate)
        ->and($stored->min_unit_price_cents?->cents)->toBe(235)
        ->and($stored->min_margin_bps)->toBe(1250)
        ->and(Fixtures::priceVersion($this->tenant))->toBe($afterCreate + 1)
        ->and($this->tenant->system(static fn (): int => SkuCommercialPolicy::query()->count()))->toBe(0)
        ->and(Fixtures::audits($this->tenant, 'sku.updated')->sole()->data['restricted_changes'])->toBe(['commercial_policy']);
});

it('não altera SKU de outro tenant', function (): void {
    $other = TenantScenario::create();
    $otherUnit = Fixtures::unit($other, 'UN');
    $otherProduct = Fixtures::product($other, $otherUnit);
    $foreignSku = Fixtures::sku($other, $otherProduct, $otherUnit, 'COPO-180');

    expect(fn () => ($this->save)($this->tenant, $this->tenant->ownerMembership, $otherProduct, $foreignSku, Fixtures::skuData($this->un, 'COPO-180')))
        ->toThrow(ModelNotFoundException::class);

    expect(($this->stored)($other, $foreignSku->id)->sales_unit_id)->toBe($otherUnit->id);
});

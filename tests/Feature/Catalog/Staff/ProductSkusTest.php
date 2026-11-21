<?php

declare(strict_types=1);

use App\Domain\Shared\Money;
use App\Enums\Catalog\SkuStatus;
use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Catalog\ProductSkus;
use App\Models\Catalog\Sku;
use App\Models\Catalog\SkuCommercialPolicy;
use App\Models\Catalog\SkuCost;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Catalog\Staff\CatalogFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->asMember = fn (TenantScenario $tenant, Membership $membership, Closure $callback): mixed => $tenant->actingAs(
        $membership,
        static function () use ($membership, $callback): mixed {
            Livewire::actingAs(User::query()->findOrFail($membership->user_id));

            return $callback();
        },
    );

    $this->tenant = TenantScenario::create();
    $this->owner = $this->tenant->ownerMembership;
    $this->un = Fixtures::unit($this->tenant, 'UN');
    $this->cx = Fixtures::unit($this->tenant, 'CX');
    $this->product = Fixtures::product($this->tenant, $this->un, ['name' => 'Refrigerante lata 350 ml']);

    $this->skus = fn (): array => $this->tenant->system(fn (): array => Sku::query()->where('product_id', $this->product->id)->orderBy('code')->pluck('code')->all());
});

it('cadastra SKU com embalagem, valores em formato brasileiro e dados internos', function (): void {
    ($this->asMember)($this->tenant, $this->owner, fn () => Livewire::test(ProductSkus::class, ['productId' => $this->product->id])
        ->assertSet('salesUnitId', $this->un->id)
        ->set('code', 'ref-lata-cx12')
        ->set('gtin', '7894900011517')
        ->set('variantLabel', 'Sabor guaraná')
        ->set('attributesText', "Sabor: Guaraná\nEmbalagem: Lata")
        ->set('salesUnitId', $this->cx->id)
        ->set('conversionFactor', '12')
        ->set('packageLabel', 'Caixa com 12')
        ->set('minOrderQuantity', '2')
        ->set('orderMultiple', '1')
        ->set('weightGrams', '4300')
        ->set('cost', '1.234,56')
        ->set('minUnitPrice', '19,90')
        ->set('minMargin', '12,5')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('message', 'SKU cadastrado.')
        ->assertSet('code', '')
        ->assertDispatched('catalog-product-changed')
        ->assertSee('REF-LATA-CX12')
        ->assertSee('R$ 1.234,56'));

    [$sku, $costs, $policy] = $this->tenant->system(static function (): array {
        $sku = Sku::query()->where('code', 'REF-LATA-CX12')->firstOrFail();

        return [
            $sku,
            SkuCost::query()->where('sku_id', $sku->id)->get()->map(static fn (SkuCost $c): int => $c->cost_cents->cents)->all(),
            SkuCommercialPolicy::query()->where('sku_id', $sku->id)->firstOrFail(),
        ];
    });

    expect($sku->sales_unit_id)->toBe($this->cx->id)
        ->and((string) $sku->conversion_factor)->toBe('12.000000')
        ->and($sku->package_label)->toBe('Caixa com 12')
        ->and($sku->attributes)->toEqual(['Sabor' => 'Guaraná', 'Embalagem' => 'Lata'])
        ->and($costs)->toBe([123456])
        ->and($policy->min_unit_price_cents?->cents)->toBe(1990)
        ->and($policy->min_margin_bps)->toBe(1250);
});

it('aponta erros de formato sem chamar a Action', function (): void {
    ($this->asMember)($this->tenant, $this->owner, fn () => Livewire::test(ProductSkus::class, ['productId' => $this->product->id])
        ->set('code', 'REF-LATA-UN')
        ->set('conversionFactor', '1.000')
        ->set('minOrderQuantity', 'doze')
        ->set('attributesText', 'Sabor Guaraná')
        ->set('cost', 'R$ 10')
        ->call('save')
        ->assertHasErrors(['conversionFactor', 'minOrderQuantity', 'attributesText', 'cost'])
        ->assertSee('Use números com vírgula decimal e até 6 casas'));

    expect(($this->skus)())->toBe([]);
});

it('exibe nos campos as recusas da Action', function (): void {
    Fixtures::sku($this->tenant, $this->product, $this->un, 'REF-LATA-UN');

    ($this->asMember)($this->tenant, $this->owner, fn () => Livewire::test(ProductSkus::class, ['productId' => $this->product->id])
        ->set('code', 'REF-LATA-FRAC')
        ->set('minOrderQuantity', '1,5')
        ->call('save')
        ->assertHasErrors(['minOrderQuantity' => 'A unidade UN não aceita casas decimais.'])
        ->set('minOrderQuantity', '1')
        ->set('code', 'ref-lata-un')
        ->call('save')
        ->assertHasErrors(['code' => 'Já existe um SKU com o código REF-LATA-UN.']));

    expect(($this->skus)())->toBe(['REF-LATA-UN']);
});

it('simula a regra de compra com mínimo, múltiplo, embalagem e conversão', function (): void {
    ($this->asMember)($this->tenant, $this->owner, fn () => Livewire::test(ProductSkus::class, ['productId' => $this->product->id])
        ->set('minOrderQuantity', '24')
        ->set('orderMultiple', '12')
        ->set('packageLabel', 'Fardo com 12')
        ->set('sampleQuantity', '13')
        ->assertSee('Abaixo da quantidade mínima de 24 UN.')
        ->assertSee('A quantidade deve ser múltipla de 12 UN (Fardo com 12).')
        ->assertSee('Sugestão: 24 UN.')
        ->set('sampleQuantity', '1,5')
        ->assertSee('A unidade UN não aceita casas decimais.')
        ->set('sampleQuantity', '36')
        ->assertSee('Quantidade aceita. 36 UN = 36 UN.')
        ->set('salesUnitId', $this->cx->id)
        ->set('conversionFactor', '12')
        ->set('minOrderQuantity', '1')
        ->set('orderMultiple', '1')
        ->set('sampleQuantity', '3')
        ->assertSee('Quantidade aceita. 3 CX = 36 UN.'));

    expect(($this->skus)())->toBe([]);
});

it('edita SKU carregando custo e política para quem vê custos', function (): void {
    $sku = Fixtures::sku($this->tenant, $this->product, $this->un, 'REF-LATA-UN');
    $this->tenant->system(static function () use ($sku): void {
        $cost = new SkuCost;
        $cost->timestamps = false;
        $cost->forceFill(['sku_id' => $sku->id, 'cost_cents' => Money::ofCents(1234), 'effective_from' => now()->subMinute()])->save();
        (new SkuCommercialPolicy)->forceFill(['sku_id' => $sku->id, 'min_unit_price_cents' => Money::ofCents(2500), 'min_margin_bps' => 1000])->save();
    });

    ($this->asMember)($this->tenant, $this->owner, static function () use ($sku): void {
        $component = Livewire::test(ProductSkus::class, ['productId' => $sku->product_id])
            ->call('edit', $sku->id)
            ->assertSet('editingSkuId', $sku->id)
            ->assertSet('code', 'REF-LATA-UN')
            ->assertSet('conversionFactor', '1')
            ->assertSet('cost', '12,34')
            ->assertSet('minUnitPrice', '25,00')
            ->assertSet('minMargin', '10');

        expect(fn () => $component->set('editingSkuId', (string) str()->uuid7()))->toThrow(CannotUpdateLockedPropertyException::class);

        $component->set('variantLabel', 'Sabor cola')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', 'SKU atualizado.');
    });

    expect($this->tenant->system(static fn (): ?string => Sku::query()->findOrFail($sku->id)->variant_label))->toBe('Sabor cola')
        ->and($this->tenant->system(static fn (): int => SkuCost::query()->where('sku_id', $sku->id)->count()))->toBe(1);
});

it('oculta custos e não os envia para quem gerencia sem catalog.view_costs', function (): void {
    $editor = Fixtures::staffWith($this->tenant, [Permission::CatalogView, Permission::CatalogManage]);

    ($this->asMember)($this->tenant, $editor, fn () => Livewire::test(ProductSkus::class, ['productId' => $this->product->id])
        ->assertSee('Cadastrar SKU')
        ->assertDontSee('Custo (R$)')
        ->assertDontSee('Custo vigente')
        ->set('code', 'REF-LATA-UN')
        ->set('cost', '9,99')
        ->call('save')
        ->assertHasNoErrors());

    expect(($this->skus)())->toBe(['REF-LATA-UN'])
        ->and($this->tenant->system(static fn (): int => SkuCost::query()->count()))->toBe(0);
});

it('mostra custos sem formulário a quem vê custos mas não gerencia o catálogo', function (): void {
    $sku = Fixtures::sku($this->tenant, $this->product, $this->un, 'REF-LATA-UN');
    $this->tenant->system(static function () use ($sku): void {
        $cost = new SkuCost;
        $cost->timestamps = false;
        $cost->forceFill(['sku_id' => $sku->id, 'cost_cents' => Money::ofCents(275), 'effective_from' => now()->subMinute()])->save();
    });

    ($this->asMember)($this->tenant, $this->tenant->addStaff(SystemRole::SalesManager), static fn () => Livewire::test(ProductSkus::class, ['productId' => $sku->product_id])
        ->assertSee('Custo vigente')
        ->assertSee('R$ 2,75')
        ->assertDontSee('Cadastrar SKU')
        ->set('code', 'REF-NOVO')
        ->call('save')
        ->assertForbidden());

    ($this->asMember)($this->tenant, $this->tenant->addStaff(SystemRole::Warehouse), static fn () => Livewire::test(ProductSkus::class, ['productId' => $sku->product_id])
        ->assertSee('REF-LATA-UN')
        ->assertDontSee('Custo vigente')
        ->assertDontSee('2,75'));

    expect(($this->skus)())->toBe(['REF-LATA-UN']);
});

it('trata SKU de outro produto, de outro tenant ou malformado como inexistente', function (string $case): void {
    $otherProduct = Fixtures::product($this->tenant, $this->un, ['name' => 'Água tônica lata']);
    $otherProductSku = Fixtures::sku($this->tenant, $otherProduct, $this->un, 'TONICA-LATA');
    $otherTenant = TenantScenario::create();
    $foreignSku = Fixtures::sku($otherTenant, Fixtures::product($otherTenant, Fixtures::unit($otherTenant, 'UN')), Fixtures::unit($otherTenant, 'UN'), 'REF-LATA-UN');

    $id = match ($case) {
        'produto' => $otherProductSku->id,
        'tenant' => $foreignSku->id,
        default => 'TONICA-LATA',
    };

    ($this->asMember)($this->tenant, $this->owner, fn () => Livewire::test(ProductSkus::class, ['productId' => $this->product->id])
        ->call('edit', $id)
        ->assertNotFound());

    expect($this->tenant->system(static fn (): SkuStatus => Sku::query()->findOrFail($otherProductSku->id)->status))->toBe(SkuStatus::Active);
})->with(['outro produto' => ['produto'], 'outro tenant' => ['tenant'], 'malformado' => ['malformado']]);

it('não monta o componente para produto de outro tenant', function (): void {
    $other = TenantScenario::create();
    $foreign = Fixtures::product($other, Fixtures::unit($other, 'UN'));

    ($this->asMember)($this->tenant, $this->owner, static fn () => Livewire::test(ProductSkus::class, ['productId' => $foreign->id])
        ->assertNotFound());
});

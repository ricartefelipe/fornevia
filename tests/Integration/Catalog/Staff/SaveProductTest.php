<?php

declare(strict_types=1);

use App\Actions\Catalog\SaveProduct;
use App\Data\Catalog\ProductData;
use App\Domain\Catalog\Exceptions\CatalogIdentifierInUse;
use App\Domain\Catalog\Exceptions\CatalogItemArchived;
use App\Domain\Catalog\Exceptions\InvalidCatalogData;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVisibility;
use App\Enums\Identity\SystemRole;
use App\Models\Catalog\Product;
use App\Models\Identity\Membership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Catalog\Staff\CatalogFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->save = fn (TenantScenario $tenant, Membership $actor, ?Product $product, ProductData $data): Product => $tenant->actingAs(
        $actor,
        static fn (): Product => app(SaveProduct::class)->handle($product, $data),
    );

    $this->stored = fn (TenantScenario $tenant, string $id): Product => $tenant->system(
        static fn (): Product => Product::query()->findOrFail($id),
    );
});

it('cadastra produto como rascunho com dados normalizados e auditoria', function (): void {
    $tenant = TenantScenario::create();
    $unit = Fixtures::unit($tenant, 'UN');
    $category = Fixtures::category($tenant, 'Mercearia');

    $product = ($this->save)($tenant, $tenant->ownerMembership, null, new ProductData(
        name: '  Biscoito   cream cracker 400 g ',
        baseUnitId: $unit->id,
        brand: ' Vitória Alimentos ',
        description: "Pacote com 3 unidades.\r\nValidade de 8 meses.",
        ncm: '1905.31.00',
        categoryId: $category->id,
        visibility: ProductVisibility::Restricted,
        allowBackorder: true,
        backorderLeadDays: 12,
    ));

    $stored = ($this->stored)($tenant, $product->id);

    expect($stored->name)->toBe('Biscoito cream cracker 400 g')
        ->and($stored->slug)->toBe('biscoito-cream-cracker-400-g')
        ->and($stored->brand)->toBe('Vitória Alimentos')
        ->and($stored->description)->toBe("Pacote com 3 unidades.\nValidade de 8 meses.")
        ->and($stored->ncm)->toBe('19053100')
        ->and($stored->category_id)->toBe($category->id)
        ->and($stored->base_unit_id)->toBe($unit->id)
        ->and($stored->status)->toBe(ProductStatus::Draft)
        ->and($stored->visibility)->toBe(ProductVisibility::Restricted)
        ->and($stored->allow_backorder)->toBeTrue()
        ->and($stored->backorder_lead_days)->toBe(12);

    $log = Fixtures::audits($tenant, 'product.created')->sole();

    expect($log->subject_type)->toBe('product')
        ->and($log->subject_id)->toBe($product->id)
        ->and($log->data['before'])->toBeNull()
        ->and($log->data['after'])->toMatchArray([
            'name' => 'Biscoito cream cracker 400 g',
            'ncm' => '19053100',
            'visibility' => 'restricted',
            'backorder_lead_days' => 12,
        ]);
});

it('gera slug único a partir do nome e recusa slug informado já utilizado', function (): void {
    $tenant = TenantScenario::create();
    $other = TenantScenario::create();
    $unit = Fixtures::unit($tenant, 'UN');
    $owner = $tenant->ownerMembership;

    $first = ($this->save)($tenant, $owner, null, new ProductData('Detergente neutro 500 ml', $unit->id));
    $second = ($this->save)($tenant, $owner, null, new ProductData('Detergente Neutro 500 ml', $unit->id));
    $foreign = ($this->save)($other, $other->ownerMembership, null, new ProductData('Detergente neutro 500 ml', Fixtures::unit($other, 'UN')->id));

    expect($first->slug)->toBe('detergente-neutro-500-ml')
        ->and($second->slug)->toBe('detergente-neutro-500-ml-2')
        ->and($foreign->slug)->toBe('detergente-neutro-500-ml');

    try {
        ($this->save)($tenant, $owner, null, new ProductData('Detergente coco 500 ml', $unit->id, slug: 'Detergente Neutro 500 ml'));
        $this->fail('Slug já utilizado deveria ser recusado.');
    } catch (CatalogIdentifierInUse $e) {
        expect($e->errorCode())->toBe('catalog_identifier_in_use')
            ->and($e->field())->toBe('slug');
    }

    $renamed = ($this->save)($tenant, $owner, $second, new ProductData('Detergente neutro 500 ml (caixa)', $unit->id, slug: 'detergente-neutro-caixa'));

    expect($renamed->slug)->toBe('detergente-neutro-caixa');
});

it('mantém o slug na edição quando nenhum slug é informado', function (): void {
    $tenant = TenantScenario::create();
    $unit = Fixtures::unit($tenant, 'UN');
    $product = ($this->save)($tenant, $tenant->ownerMembership, null, new ProductData('Papel toalha interfolhado', $unit->id));

    ($this->save)($tenant, $tenant->ownerMembership, $product, new ProductData('Papel toalha interfolhado 20 x 21 cm', $unit->id));

    expect(($this->stored)($tenant, $product->id)->slug)->toBe('papel-toalha-interfolhado');
});

it('valida NCM, prazo de encomenda e tamanhos', function (array $overrides, string $field): void {
    $tenant = TenantScenario::create();
    $unit = Fixtures::unit($tenant, 'UN');

    try {
        ($this->save)($tenant, $tenant->ownerMembership, null, new ProductData(...[
            'name' => 'Guardanapo de papel',
            'baseUnitId' => $unit->id,
            ...$overrides,
        ]));
        $this->fail('Dados inválidos deveriam ser recusados.');
    } catch (InvalidCatalogData $e) {
        expect($e->fieldName())->toBe($field);
    }

    expect($tenant->system(static fn (): int => Product::query()->count()))->toBe(0);
})->with([
    'NCM com 7 dígitos' => [['ncm' => '4818.20.0'], 'ncm'],
    'NCM com letras' => [['ncm' => '4818AB00'], 'ncm'],
    'prazo sem encomenda' => [['backorderLeadDays' => 10], 'backorder_lead_days'],
    'prazo zero' => [['allowBackorder' => true, 'backorderLeadDays' => 0], 'backorder_lead_days'],
    'prazo acima do limite' => [['allowBackorder' => true, 'backorderLeadDays' => 400], 'backorder_lead_days'],
    'nome vazio' => [['name' => ' '], 'name'],
    'marca longa' => [['brand' => str_repeat('m', 81)], 'brand'],
    'slug sem caracteres válidos' => [['slug' => '***'], 'slug'],
]);

it('limpa o prazo quando a encomenda deixa de ser admitida', function (): void {
    $tenant = TenantScenario::create();
    $unit = Fixtures::unit($tenant, 'UN');
    $product = ($this->save)($tenant, $tenant->ownerMembership, null, new ProductData('Copo descartável 200 ml', $unit->id, allowBackorder: true, backorderLeadDays: 5));

    ($this->save)($tenant, $tenant->ownerMembership, $product, new ProductData('Copo descartável 200 ml', $unit->id));
    $stored = ($this->stored)($tenant, $product->id);

    expect($stored->allow_backorder)->toBeFalse()
        ->and($stored->backorder_lead_days)->toBeNull();
});

it('recusa categoria e unidade de outro tenant', function (string $reference): void {
    $tenant = TenantScenario::create();
    $other = TenantScenario::create();
    $unit = Fixtures::unit($tenant, 'UN');
    $foreignUnit = Fixtures::unit($other, 'UN');
    $foreignCategory = Fixtures::category($other, 'Limpeza');

    $data = $reference === 'unit'
        ? new ProductData('Sabão em pó 1 kg', $foreignUnit->id)
        : new ProductData('Sabão em pó 1 kg', $unit->id, categoryId: $foreignCategory->id);

    expect(fn () => ($this->save)($tenant, $tenant->ownerMembership, null, $data))->toThrow(ModelNotFoundException::class);
    expect($tenant->system(static fn (): int => Product::query()->count()))->toBe(0);
})->with(['unidade' => ['unit'], 'categoria' => ['category']]);

it('não troca a unidade base depois que o produto tem SKUs', function (): void {
    $tenant = TenantScenario::create();
    $un = Fixtures::unit($tenant, 'UN');
    $kg = Fixtures::unit($tenant, 'KG');
    $product = Fixtures::product($tenant, $un);
    Fixtures::sku($tenant, $product, $un, 'CAF-UN');

    expect(fn () => ($this->save)($tenant, $tenant->ownerMembership, $product, new ProductData($product->name, $kg->id)))
        ->toThrow(InvalidCatalogData::class, 'A unidade base não pode ser alterada');

    expect(($this->stored)($tenant, $product->id)->base_unit_id)->toBe($un->id);
});

it('não altera produto arquivado', function (): void {
    $tenant = TenantScenario::create();
    $unit = Fixtures::unit($tenant, 'UN');
    $product = Fixtures::product($tenant, $unit, ['status' => ProductStatus::Archived, 'archived_at' => now()]);

    expect(fn () => ($this->save)($tenant, $tenant->ownerMembership, $product, new ProductData('Nome novo', $unit->id)))
        ->toThrow(CatalogItemArchived::class);
});

it('exige catalog.manage', function (SystemRole $role): void {
    $tenant = TenantScenario::create();
    $unit = Fixtures::unit($tenant, 'UN');
    $actor = $tenant->addStaff($role);
    $existing = Fixtures::product($tenant, $unit, ['name' => 'Açúcar refinado 1 kg']);

    expect(fn () => ($this->save)($tenant, $actor, null, new ProductData('Sal refinado 1 kg', $unit->id)))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->save)($tenant, $actor, $existing, new ProductData('Açúcar alterado', $unit->id)))
        ->toThrow(AuthorizationException::class);

    expect(($this->stored)($tenant, $existing->id)->name)->toBe('Açúcar refinado 1 kg')
        ->and(Fixtures::audits($tenant, 'product.created'))->toBeEmpty();
})->with([
    'gerente comercial' => [SystemRole::SalesManager],
    'representante' => [SystemRole::SalesRep],
    'consulta' => [SystemRole::Viewer],
]);

it('não altera produto de outro tenant', function (): void {
    $tenant = TenantScenario::create();
    $other = TenantScenario::create();
    $foreign = Fixtures::product($other, Fixtures::unit($other, 'UN'), ['name' => 'Óleo de soja 900 ml']);

    expect(fn () => ($this->save)($tenant, $tenant->ownerMembership, $foreign, new ProductData('Alterado', Fixtures::unit($tenant, 'UN')->id)))
        ->toThrow(ModelNotFoundException::class);

    expect(($this->stored)($other, $foreign->id)->name)->toBe('Óleo de soja 900 ml');
});

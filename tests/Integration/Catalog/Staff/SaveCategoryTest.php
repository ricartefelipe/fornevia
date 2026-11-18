<?php

declare(strict_types=1);

use App\Actions\Catalog\SaveCategory;
use App\Data\Catalog\CategoryData;
use App\Domain\Catalog\Exceptions\CategoryCycle;
use App\Domain\Catalog\Exceptions\InvalidCatalogData;
use App\Enums\Identity\SystemRole;
use App\Models\Catalog\Category;
use App\Models\Identity\Membership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Catalog\Staff\CatalogFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->save = fn (TenantScenario $tenant, Membership $actor, ?Category $category, CategoryData $data): Category => $tenant->actingAs(
        $actor,
        static fn (): Category => app(SaveCategory::class)->handle($category, $data),
    );

    $this->stored = fn (TenantScenario $tenant, string $id): Category => $tenant->system(
        static fn (): Category => Category::query()->findOrFail($id),
    );
});

it('cadastra categoria com slug gerado do nome e registra a auditoria', function (): void {
    $tenant = TenantScenario::create();

    $category = ($this->save)($tenant, $tenant->ownerMembership, null, new CategoryData('  Cafés   e Chás ', sortOrder: 3));
    $stored = ($this->stored)($tenant, $category->id);

    expect($stored->name)->toBe('Cafés e Chás')
        ->and($stored->slug)->toBe('cafes-e-chas')
        ->and($stored->parent_id)->toBeNull()
        ->and($stored->sort_order)->toBe(3);

    $logs = Fixtures::audits($tenant, 'category.created');

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->subject_type)->toBe('category')
        ->and($logs->first()->subject_id)->toBe($category->id)
        ->and($logs->first()->actor_membership_id)->toBe($tenant->ownerMembership->id)
        ->and($logs->first()->data)->toEqual([
            'before' => null,
            'after' => ['name' => 'Cafés e Chás', 'slug' => 'cafes-e-chas', 'parent_id' => null, 'sort_order' => 3],
        ]);
});

it('gera slugs únicos no tenant e permite o mesmo slug em outro tenant', function (): void {
    $aurora = TenantScenario::create();
    $boreal = TenantScenario::create();

    $first = ($this->save)($aurora, $aurora->ownerMembership, null, new CategoryData('Limpeza'));
    $second = ($this->save)($aurora, $aurora->ownerMembership, null, new CategoryData('Limpeza!'));
    $third = ($this->save)($aurora, $aurora->ownerMembership, null, new CategoryData('LIMPEZA'));
    $foreign = ($this->save)($boreal, $boreal->ownerMembership, null, new CategoryData('Limpeza'));

    expect([$first->slug, $second->slug, $third->slug])->toBe(['limpeza', 'limpeza-2', 'limpeza-3'])
        ->and($foreign->slug)->toBe('limpeza');
});

it('mantém o slug quando o nome não muda e o regenera ao renomear', function (): void {
    $tenant = TenantScenario::create();
    $category = ($this->save)($tenant, $tenant->ownerMembership, null, new CategoryData('Descartáveis'));

    ($this->save)($tenant, $tenant->ownerMembership, $category, new CategoryData('Descartáveis', sortOrder: 5));
    expect(($this->stored)($tenant, $category->id)->slug)->toBe('descartaveis');

    ($this->save)($tenant, $tenant->ownerMembership, $category, new CategoryData('Descartáveis biodegradáveis', sortOrder: 5));
    $stored = ($this->stored)($tenant, $category->id);

    expect($stored->slug)->toBe('descartaveis-biodegradaveis')
        ->and(Fixtures::audits($tenant, 'category.updated'))->toHaveCount(2);
});

it('não registra auditoria quando nada muda', function (): void {
    $tenant = TenantScenario::create();
    $category = ($this->save)($tenant, $tenant->ownerMembership, null, new CategoryData('Bebidas'));

    ($this->save)($tenant, $tenant->ownerMembership, $category, new CategoryData('Bebidas'));

    expect(Fixtures::audits($tenant, 'category.updated'))->toBeEmpty();
});

it('aceita pai do mesmo tenant e recusa pai de outro tenant', function (): void {
    $tenant = TenantScenario::create();
    $other = TenantScenario::create();
    $parent = Fixtures::category($tenant, 'Mercearia');
    $foreignParent = Fixtures::category($other, 'Mercearia');

    $child = ($this->save)($tenant, $tenant->ownerMembership, null, new CategoryData('Grãos', $parent->id));

    expect(($this->stored)($tenant, $child->id)->parent_id)->toBe($parent->id);

    expect(fn () => ($this->save)($tenant, $tenant->ownerMembership, null, new CategoryData('Massas', $foreignParent->id)))
        ->toThrow(ModelNotFoundException::class);

    expect($tenant->system(static fn (): int => Category::query()->where('name', 'Massas')->count()))->toBe(0);
});

it('trata identificador de pai malformado como inexistente', function (): void {
    $tenant = TenantScenario::create();

    expect(fn () => ($this->save)($tenant, $tenant->ownerMembership, null, new CategoryData('Massas', 'mercearia')))
        ->toThrow(ModelNotFoundException::class);
});

it('impede ciclos na hierarquia, inclusive a própria categoria como pai', function (): void {
    $tenant = TenantScenario::create();
    $owner = $tenant->ownerMembership;
    $root = ($this->save)($tenant, $owner, null, new CategoryData('Higiene'));
    $middle = ($this->save)($tenant, $owner, null, new CategoryData('Cabelos', $root->id));
    $leaf = ($this->save)($tenant, $owner, null, new CategoryData('Xampus', $middle->id));

    expect(fn () => ($this->save)($tenant, $owner, $root, new CategoryData('Higiene', $leaf->id)))
        ->toThrow(CategoryCycle::class, 'A categoria não pode ficar abaixo de si mesma');

    expect(fn () => ($this->save)($tenant, $owner, $middle, new CategoryData('Cabelos', $middle->id)))
        ->toThrow(CategoryCycle::class);

    expect(($this->stored)($tenant, $root->id)->parent_id)->toBeNull()
        ->and(($this->stored)($tenant, $middle->id)->parent_id)->toBe($root->id);

    // Mover a folha para a raiz continua permitido.
    ($this->save)($tenant, $owner, $leaf, new CategoryData('Xampus', $root->id));
    expect(($this->stored)($tenant, $leaf->id)->parent_id)->toBe($root->id);
});

it('valida nome e ordem de exibição', function (string $name, int $sortOrder, string $field): void {
    $tenant = TenantScenario::create();

    try {
        ($this->save)($tenant, $tenant->ownerMembership, null, new CategoryData($name, sortOrder: $sortOrder));
        $this->fail('Dados inválidos deveriam ser recusados.');
    } catch (InvalidCatalogData $e) {
        expect($e->errorCode())->toBe('invalid_catalog_data')
            ->and($e->fieldName())->toBe($field);
    }

    expect($tenant->system(static fn (): int => Category::query()->count()))->toBe(0);
})->with([
    'nome vazio' => ['   ', 0, 'name'],
    'nome longo' => [str_repeat('a', 121), 0, 'name'],
    'ordem negativa' => ['Bebidas', -1, 'sort_order'],
]);

it('exige catalog.manage', function (SystemRole $role): void {
    $tenant = TenantScenario::create();
    $actor = $tenant->addStaff($role);
    $existing = Fixtures::category($tenant, 'Bebidas');

    expect(fn () => ($this->save)($tenant, $actor, null, new CategoryData('Laticínios')))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->save)($tenant, $actor, $existing, new CategoryData('Bebidas geladas')))
        ->toThrow(AuthorizationException::class);

    expect(($this->stored)($tenant, $existing->id)->name)->toBe('Bebidas')
        ->and(Fixtures::audits($tenant, 'category.created'))->toBeEmpty();
})->with([
    'gerente comercial' => [SystemRole::SalesManager],
    'representante' => [SystemRole::SalesRep],
    'estoque' => [SystemRole::Warehouse],
    'consulta' => [SystemRole::Viewer],
]);

it('não altera categoria de outro tenant', function (): void {
    $tenant = TenantScenario::create();
    $other = TenantScenario::create();
    $foreign = Fixtures::category($other, 'Congelados');

    expect(fn () => ($this->save)($tenant, $tenant->ownerMembership, $foreign, new CategoryData('Alterada indevidamente')))
        ->toThrow(ModelNotFoundException::class);

    expect(($this->stored)($other, $foreign->id)->name)->toBe('Congelados');
});

<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Catalog\CategoriesIndex;
use App\Livewire\Staff\Catalog\UnitsIndex;
use App\Models\Catalog\Category;
use App\Models\Catalog\UnitOfMeasure;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Illuminate\Pagination\LengthAwarePaginator;
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
});

it('cadastra subcategoria e edita categoria pela tela', function (): void {
    $root = Fixtures::category($this->tenant, 'Limpeza');

    ($this->asMember)($this->tenant, $this->owner, static fn () => Livewire::test(CategoriesIndex::class)
        ->set('name', 'Desinfetantes')
        ->set('parentId', $root->id)
        ->set('sortOrder', '2')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('message', 'Categoria Desinfetantes cadastrada.')
        ->assertSet('name', '')
        ->assertSee('Desinfetantes')
        ->call('edit', $root->id)
        ->assertSet('editingId', $root->id)
        ->set('name', 'Limpeza profissional')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('message', 'Categoria Limpeza profissional atualizada.'));

    $stored = $this->tenant->system(static fn (): array => Category::query()->orderBy('name')->get()
        ->mapWithKeys(static fn (Category $c): array => [$c->name => $c->parent_id])->all());

    expect($stored)->toBe(['Desinfetantes' => $root->id, 'Limpeza profissional' => null]);
});

it('mostra o erro de ciclo no campo da categoria superior', function (): void {
    $root = Fixtures::category($this->tenant, 'Higiene');
    $child = Fixtures::category($this->tenant, 'Cabelos', $root);

    ($this->asMember)($this->tenant, $this->owner, static fn () => Livewire::test(CategoriesIndex::class)
        ->call('edit', $root->id)
        ->set('parentId', $child->id)
        ->call('save')
        ->assertHasErrors(['parentId' => 'A categoria não pode ficar abaixo de si mesma nem de uma de suas subcategorias.']));

    expect($this->tenant->system(static fn (): ?string => Category::query()->findOrFail($root->id)->parent_id))->toBeNull();
});

it('protege a categoria em edição e trata ids de outro tenant como inexistentes', function (): void {
    $own = Fixtures::category($this->tenant, 'Bebidas');
    $other = TenantScenario::create();
    $foreign = Fixtures::category($other, 'Bebidas');

    ($this->asMember)($this->tenant, $this->owner, static function () use ($own, $foreign): void {
        $component = Livewire::test(CategoriesIndex::class)->call('edit', $own->id);

        expect(fn () => $component->set('editingId', $foreign->id))->toThrow(CannotUpdateLockedPropertyException::class);

        Livewire::test(CategoriesIndex::class)->call('edit', $foreign->id)->assertNotFound();

        Livewire::test(CategoriesIndex::class)
            ->set('name', 'Sucos')
            ->set('parentId', $foreign->id)
            ->call('save')
            ->assertNotFound();
    });

    expect($other->system(static fn (): int => Category::query()->count()))->toBe(1);
});

it('pagina categorias e esconde o formulário de quem só consulta', function (): void {
    foreach (range(1, 26) as $i) {
        Fixtures::category($this->tenant, sprintf('Categoria %02d', $i));
    }

    ($this->asMember)($this->tenant, $this->tenant->addStaff(SystemRole::Viewer), static fn () => Livewire::test(CategoriesIndex::class)
        ->assertViewHas('categories', static fn (LengthAwarePaginator $p): bool => $p->count() === 25 && $p->total() === 26)
        ->assertDontSee('Nova categoria')
        ->set('name', 'Indevida')
        ->call('save')
        ->assertForbidden());
});

it('cadastra e edita unidade de medida, exibindo conflitos de código', function (): void {
    ($this->asMember)($this->tenant, $this->owner, static fn () => Livewire::test(UnitsIndex::class)
        ->assertSee('KG')
        ->set('code', 'fd')
        ->set('name', 'Fardo')
        ->set('decimals', '0')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('message', 'Unidade FD cadastrada.')
        ->set('code', 'cx')
        ->set('name', 'Caixa master')
        ->call('save')
        ->assertHasErrors(['code' => 'Já existe uma unidade de medida com o código CX.'])
        ->set('decimals', '5')
        ->call('save')
        ->assertHasErrors(['decimals']));

    $kg = Fixtures::unit($this->tenant, 'KG');

    ($this->asMember)($this->tenant, $this->owner, static fn () => Livewire::test(UnitsIndex::class)
        ->call('edit', $kg->id)
        ->assertSet('decimals', '3')
        ->set('name', 'Quilo')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('message', 'Unidade KG atualizada.'));

    expect($this->tenant->system(static fn (): array => UnitOfMeasure::query()->whereIn('code', ['FD', 'KG'])->orderBy('code')->pluck('name')->all()))
        ->toBe(['Fardo', 'Quilo']);
});

it('recusa gravação de unidade a quem não gerencia o catálogo e ids de outro tenant', function (): void {
    $other = TenantScenario::create();
    $foreign = Fixtures::unit($other, 'UN');

    ($this->asMember)($this->tenant, $this->tenant->addStaff(SystemRole::Warehouse), static fn () => Livewire::test(UnitsIndex::class)
        ->assertDontSee('Nova unidade')
        ->set('code', 'FD')
        ->set('name', 'Fardo')
        ->call('save')
        ->assertForbidden());

    ($this->asMember)($this->tenant, $this->owner, static fn () => Livewire::test(UnitsIndex::class)
        ->call('edit', $foreign->id)
        ->assertNotFound());

    expect($this->tenant->system(static fn (): int => UnitOfMeasure::query()->where('code', 'FD')->count()))->toBe(0);
});

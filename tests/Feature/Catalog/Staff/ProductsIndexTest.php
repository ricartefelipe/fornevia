<?php

declare(strict_types=1);

use App\Enums\Catalog\ProductStatus;
use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Catalog\ProductsIndex;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Illuminate\Pagination\LengthAwarePaginator;
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

    $this->names = static fn (LengthAwarePaginator $products): array => collect($products->items())->pluck('name')->sort()->values()->all();

    $this->tenant = TenantScenario::create();
    $this->viewer = $this->tenant->addStaff(SystemRole::Viewer);
    $this->unit = Fixtures::unit($this->tenant, 'UN');
});

it('busca por nome, marca e código de SKU, sem tratar curingas como padrão', function (): void {
    $coffee = Fixtures::product($this->tenant, $this->unit, ['name' => 'Café torrado 500 g', 'brand' => 'Serra Azul', 'status' => ProductStatus::Active]);
    $sugar = Fixtures::product($this->tenant, $this->unit, ['name' => 'Açúcar refinado 1 kg', 'brand' => 'Doce Vale']);
    Fixtures::sku($this->tenant, $sugar, $this->unit, 'ACU-REF-1KG');
    Fixtures::product($this->tenant, $this->unit, ['name' => 'Detergente 100% biodegradável']);
    $other = TenantScenario::create();
    Fixtures::product($other, Fixtures::unit($other, 'UN'), ['name' => 'Café de outro distribuidor']);

    ($this->asMember)($this->tenant, $this->viewer, function () use ($coffee, $sugar): void {
        $component = Livewire::test(ProductsIndex::class);

        $component->set('search', 'café')
            ->assertViewHas('products', fn (LengthAwarePaginator $p): bool => ($this->names)($p) === [$coffee->name]);

        $component->set('search', 'doce vale')
            ->assertViewHas('products', fn (LengthAwarePaginator $p): bool => ($this->names)($p) === [$sugar->name]);

        $component->set('search', 'acu-ref')
            ->assertViewHas('products', fn (LengthAwarePaginator $p): bool => ($this->names)($p) === [$sugar->name])
            ->assertSee('Açúcar refinado 1 kg');

        $component->set('search', '%')
            ->assertViewHas('products', fn (LengthAwarePaginator $p): bool => ($this->names)($p) === ['Detergente 100% biodegradável']);

        $component->set('search', '_')
            ->assertViewHas('products', fn (LengthAwarePaginator $p): bool => $p->total() === 0)
            ->assertSee('Nenhum produto encontrado');
    });
});

it('filtra por categoria e situação e ignora filtros malformados', function (): void {
    $beverages = Fixtures::category($this->tenant, 'Bebidas');
    $juice = Fixtures::product($this->tenant, $this->unit, ['name' => 'Suco de uva integral 1 L', 'category_id' => $beverages->id, 'status' => ProductStatus::Active]);
    $water = Fixtures::product($this->tenant, $this->unit, ['name' => 'Água mineral 500 ml', 'category_id' => $beverages->id]);
    $rice = Fixtures::product($this->tenant, $this->unit, ['name' => 'Arroz agulhinha 5 kg', 'status' => ProductStatus::Active]);

    ($this->asMember)($this->tenant, $this->viewer, function () use ($beverages, $juice, $water, $rice): void {
        $all = collect([$juice->name, $water->name, $rice->name])->sort()->values()->all();

        Livewire::test(ProductsIndex::class)
            ->set('categoryId', $beverages->id)
            ->assertViewHas('products', fn (LengthAwarePaginator $p): bool => ($this->names)($p) === collect([$juice->name, $water->name])->sort()->values()->all())
            ->set('status', 'active')
            ->assertViewHas('products', fn (LengthAwarePaginator $p): bool => ($this->names)($p) === [$juice->name])
            ->call('clearFilters')
            ->assertSet('categoryId', '')
            ->assertViewHas('products', fn (LengthAwarePaginator $p): bool => ($this->names)($p) === $all)
            ->set('categoryId', 'bebidas')
            ->set('status', 'publicado')
            ->assertViewHas('products', fn (LengthAwarePaginator $p): bool => ($this->names)($p) === $all);
    });
});

it('pagina a lista com 25 produtos por página', function (): void {
    foreach (range(1, 27) as $i) {
        Fixtures::product($this->tenant, $this->unit, ['name' => sprintf('Parafuso sextavado M%02d', $i)]);
    }

    ($this->asMember)($this->tenant, $this->viewer, static fn () => Livewire::test(ProductsIndex::class)
        ->assertViewHas('products', static fn (LengthAwarePaginator $p): bool => $p->count() === 25 && $p->total() === 27)
        ->assertSee('Parafuso sextavado M01')
        ->assertDontSee('Parafuso sextavado M27')
        ->call('gotoPage', 2)
        ->assertViewHas('products', static fn (LengthAwarePaginator $p): bool => $p->count() === 2 && $p->currentPage() === 2)
        ->assertSee('Parafuso sextavado M27'));
});

it('exibe estado vazio com atalho de cadastro apenas a quem gerencia o catálogo', function (): void {
    ($this->asMember)($this->tenant, $this->viewer, static fn () => Livewire::test(ProductsIndex::class)
        ->assertSee('Nenhum produto cadastrado')
        ->assertDontSee('Novo produto'));

    ($this->asMember)($this->tenant, $this->tenant->ownerMembership, static fn () => Livewire::test(ProductsIndex::class)
        ->assertSee('Nenhum produto cadastrado')
        ->assertSee('Novo produto'));
});

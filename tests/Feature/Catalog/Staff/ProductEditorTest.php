<?php

declare(strict_types=1);

use App\Domain\Shared\Money;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVisibility;
use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Catalog\ProductEditor;
use App\Models\Catalog\Product;
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

    $this->stored = fn (string $id): Product => $this->tenant->system(static fn (): Product => Product::query()->findOrFail($id));

    $this->tenant = TenantScenario::create();
    $this->owner = $this->tenant->ownerMembership;
    $this->unit = Fixtures::unit($this->tenant, 'UN');
});

it('cadastra produto e redireciona para a edição', function (): void {
    $category = Fixtures::category($this->tenant, 'Papelaria');

    $component = ($this->asMember)($this->tenant, $this->owner, fn () => Livewire::test(ProductEditor::class)
        ->set('name', 'Caderno espiral 200 folhas')
        ->set('brand', 'Folha Viva')
        ->set('categoryId', $category->id)
        ->set('baseUnitId', $this->unit->id)
        ->set('ncm', '4820.20.00')
        ->set('visibility', 'restricted')
        ->set('allowBackorder', true)
        ->set('backorderLeadDays', '15')
        ->call('save')
        ->assertHasNoErrors());

    $stored = $this->tenant->system(static fn (): Product => Product::query()->where('name', 'Caderno espiral 200 folhas')->firstOrFail());

    $component->assertRedirect(route('catalog.products.edit', ['product' => $stored->id]));

    expect($stored->slug)->toBe('caderno-espiral-200-folhas')
        ->and($stored->ncm)->toBe('48202000')
        ->and($stored->visibility)->toBe(ProductVisibility::Restricted)
        ->and($stored->backorder_lead_days)->toBe(15)
        ->and($stored->status)->toBe(ProductStatus::Draft)
        ->and(session('success'))->toBe('Produto cadastrado como rascunho. Cadastre os SKUs para ativá-lo.');
});

it('exibe erros de validação de formato e das regras da Action nos campos', function (): void {
    ($this->asMember)($this->tenant, $this->owner, function (): void {
        Livewire::test(ProductEditor::class)
            ->call('save')
            ->assertHasErrors(['name' => 'required', 'baseUnitId' => 'required']);

        Livewire::test(ProductEditor::class)
            ->set('name', 'Caneta gel preta')
            ->set('baseUnitId', $this->unit->id)
            ->set('ncm', '9608.10')
            ->call('save')
            ->assertHasErrors(['ncm'])
            ->assertSee('O NCM deve ter 8 dígitos')
            ->assertNoRedirect();
    });

    expect($this->tenant->system(static fn (): int => Product::query()->count()))->toBe(0);
});

it('mostra conflito de slug no campo correspondente', function (): void {
    Fixtures::product($this->tenant, $this->unit, ['name' => 'Grampeador', 'slug' => 'grampeador-de-mesa']);

    ($this->asMember)($this->tenant, $this->owner, fn () => Livewire::test(ProductEditor::class)
        ->set('name', 'Grampeador 26/6')
        ->set('slug', 'Grampeador de mesa')
        ->set('baseUnitId', $this->unit->id)
        ->call('save')
        ->assertHasErrors(['slug' => 'Já existe um produto com este endereço (slug).']));
});

it('edita produto existente mantendo o identificador bloqueado', function (): void {
    $product = Fixtures::product($this->tenant, $this->unit, ['name' => 'Clips nº 2']);
    $other = Fixtures::product($this->tenant, $this->unit, ['name' => 'Elástico látex']);

    ($this->asMember)($this->tenant, $this->owner, function () use ($product, $other): void {
        $component = Livewire::test(ProductEditor::class, ['product' => $product])
            ->assertSet('productId', $product->id)
            ->assertSet('name', 'Clips nº 2');

        expect(fn () => $component->set('productId', $other->id))->toThrow(CannotUpdateLockedPropertyException::class);

        $component->set('name', 'Clips nº 2 galvanizado')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', 'Dados do produto salvos.');
    });

    expect(($this->stored)($product->id)->name)->toBe('Clips nº 2 galvanizado')
        ->and(($this->stored)($other->id)->name)->toBe('Elástico látex');
});

it('recusa a gravação a quem só consulta o catálogo', function (SystemRole $role): void {
    $product = Fixtures::product($this->tenant, $this->unit, ['name' => 'Fita adesiva 48 mm']);

    ($this->asMember)($this->tenant, $this->tenant->addStaff($role), fn () => Livewire::test(ProductEditor::class, ['product' => $product])
        ->set('name', 'Fita alterada')
        ->call('save')
        ->assertForbidden());

    expect(($this->stored)($product->id)->name)->toBe('Fita adesiva 48 mm');
})->with([
    'gerente comercial' => [SystemRole::SalesManager],
    'consulta' => [SystemRole::Viewer],
]);

it('altera a situação e exibe a recusa de ativação sem SKU ativo', function (): void {
    $product = Fixtures::product($this->tenant, $this->unit, ['name' => 'Marcador permanente']);

    ($this->asMember)($this->tenant, $this->owner, static fn () => Livewire::test(ProductEditor::class, ['product' => $product])
        ->call('changeStatus', 'active')
        ->assertHasErrors(['status' => 'Cadastre ou ative ao menos um SKU antes de ativar o produto.'])
        ->assertSee('Cadastre ou ative ao menos um SKU'));

    Fixtures::sku($this->tenant, $product, $this->unit, 'MARC-PERM-PT');

    ($this->asMember)($this->tenant, $this->owner, static fn () => Livewire::test(ProductEditor::class, ['product' => $product])
        ->call('changeStatus', 'active')
        ->assertHasNoErrors()
        ->assertSet('message', 'Situação alterada para ativo.')
        ->call('changeStatus', 'publicado')
        ->assertHasErrors(['status' => 'Situação inválida.']));

    expect(($this->stored)($product->id)->status)->toBe(ProductStatus::Active);
});

it('exclui produto sem histórico e explica a recusa quando há histórico', function (): void {
    $free = Fixtures::product($this->tenant, $this->unit, ['name' => 'Cola branca 90 g']);
    $withHistory = Fixtures::product($this->tenant, $this->unit, ['name' => 'Papel cartão A4']);
    $sku = Fixtures::sku($this->tenant, $withHistory, $this->unit, 'CARTAO-A4');
    $this->tenant->system(static function () use ($sku): void {
        $cost = new SkuCost;
        $cost->timestamps = false;
        $cost->forceFill(['sku_id' => $sku->id, 'cost_cents' => Money::ofCents(450), 'effective_from' => now()->subMinute()])->save();
    });

    ($this->asMember)($this->tenant, $this->owner, function () use ($free, $withHistory): void {
        Livewire::test(ProductEditor::class, ['product' => $free])
            ->call('delete')
            ->assertRedirect(route('catalog.products'));

        Livewire::test(ProductEditor::class, ['product' => $withHistory])
            ->call('delete')
            ->assertHasErrors(['delete'])
            ->assertSee('Arquive-o para retirá-lo do catálogo')
            ->assertNoRedirect();
    });

    expect($this->tenant->system(static fn (): array => Product::query()->pluck('name')->all()))->toBe(['Papel cartão A4']);
});

it('trata produto de outro tenant como inexistente', function (): void {
    $other = TenantScenario::create();
    $foreign = Fixtures::product($other, Fixtures::unit($other, 'UN'), ['name' => 'Produto de outro distribuidor']);

    ($this->asMember)($this->tenant, $this->owner, static fn () => Livewire::test(ProductEditor::class, ['product' => $foreign])
        ->assertNotFound());
});

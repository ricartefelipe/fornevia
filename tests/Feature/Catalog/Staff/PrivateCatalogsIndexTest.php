<?php

declare(strict_types=1);

use App\Enums\Catalog\ProductVisibility;
use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Catalog\PrivateCatalogsIndex;
use App\Models\Buyers\CustomerGroup;
use App\Models\Catalog\PrivateCatalog;
use App\Models\Catalog\PrivateCatalogAssignment;
use App\Models\Catalog\PrivateCatalogProduct;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
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
    $this->unit = Fixtures::unit($this->tenant, 'UN');

    $this->catalogProducts = fn (): array => $this->tenant->system(static fn (): array => PrivateCatalogProduct::query()->pluck('product_id')->all());
    $this->assignments = fn (): array => $this->tenant->system(static fn (): array => PrivateCatalogAssignment::query()->get()
        ->map(static fn (PrivateCatalogAssignment $a): ?string => $a->buyer_company_id ?? $a->customer_group_id)->all());
});

it('cadastra catálogo, inclui produtos pela busca e atribui empresa e grupo', function (): void {
    $restricted = Fixtures::product($this->tenant, $this->unit, ['name' => 'Seringa descartável 5 ml', 'visibility' => ProductVisibility::Restricted]);
    Fixtures::product($this->tenant, $this->unit, ['name' => 'Algodão hidrófilo 500 g']);
    $company = $this->tenant->addBuyerCompany(['trade_name' => 'Hospital Santa Clara', 'legal_name' => 'Associação Santa Clara de Saúde']);
    $group = $this->tenant->system(static fn (): CustomerGroup => CustomerGroup::query()->create(['code' => 'SAUDE', 'name' => 'Saúde']));

    ($this->asMember)($this->tenant, $this->owner, static function () use ($restricted, $company, $group): void {
        $component = Livewire::test(PrivateCatalogsIndex::class)
            ->assertSee('Nenhum catálogo privado')
            ->set('code', 'rede-hospitalar')
            ->set('name', 'Rede hospitalar')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Inclua produtos e atribua empresas ou grupos');

        $catalogId = $component->get('selectedId');
        expect($catalogId)->not->toBeNull();

        $component->set('productSearch', 'seringa')
            ->assertSee('Seringa descartável 5 ml')
            ->assertDontSee('Algodão hidrófilo 500 g')
            ->call('addProduct', $restricted->id)
            ->assertSet('message', 'Produto Seringa descartável 5 ml incluído.')
            ->set('companySearch', 'santa clara')
            ->assertSee('Hospital Santa Clara')
            ->call('assignCompany', $company->id)
            ->assertSet('message', 'Catálogo atribuído.')
            ->set('groupId', $group->id)
            ->call('assignGroup')
            ->assertHasNoErrors()
            ->assertSee('Saúde');
    });

    expect(($this->catalogProducts)())->toBe([$restricted->id])
        ->and(($this->assignments)())->toEqualCanonicalizing([$company->id, $group->id]);
});

it('remove produto e atribuição do catálogo selecionado', function (): void {
    $product = Fixtures::product($this->tenant, $this->unit, ['name' => 'Luva de procedimento']);
    $company = $this->tenant->addBuyerCompany();
    [$catalog, $assignment] = $this->tenant->system(static function () use ($product, $company): array {
        $catalog = new PrivateCatalog;
        $catalog->forceFill(['code' => 'CLINICAS', 'name' => 'Clínicas'])->save();
        (new PrivateCatalogProduct)->forceFill(['private_catalog_id' => $catalog->id, 'product_id' => $product->id])->save();
        $assignment = new PrivateCatalogAssignment;
        $assignment->forceFill(['private_catalog_id' => $catalog->id, 'buyer_company_id' => $company->id])->save();

        return [$catalog, $assignment];
    });

    ($this->asMember)($this->tenant, $this->owner, static fn () => Livewire::test(PrivateCatalogsIndex::class)
        ->call('manage', $catalog->id)
        ->assertSee('Luva de procedimento')
        ->call('removeProduct', $product->id)
        ->assertSet('message', 'Produto Luva de procedimento removido do catálogo.')
        ->call('unassign', $assignment->id)
        ->assertSet('message', 'Atribuição removida.')
        ->assertSee('Nenhum produto incluído.')
        ->assertSee('Nenhuma empresa ou grupo atribuído.'));

    expect(($this->catalogProducts)())->toBe([])
        ->and(($this->assignments)())->toBe([]);
});

it('trata catálogo, produto, empresa e atribuição de outro tenant como inexistentes', function (string $action): void {
    $other = TenantScenario::create();
    $foreignProduct = Fixtures::product($other, Fixtures::unit($other, 'UN'));
    $foreignCompany = $other->addBuyerCompany();
    [$foreignCatalog, $foreignAssignment] = $other->system(static function () use ($foreignCompany): array {
        $catalog = new PrivateCatalog;
        $catalog->forceFill(['code' => 'EXTERNO', 'name' => 'Catálogo externo'])->save();
        $assignment = new PrivateCatalogAssignment;
        $assignment->forceFill(['private_catalog_id' => $catalog->id, 'buyer_company_id' => $foreignCompany->id])->save();

        return [$catalog, $assignment];
    });
    $own = $this->tenant->system(static function (): PrivateCatalog {
        $catalog = new PrivateCatalog;
        $catalog->forceFill(['code' => 'PROPRIO', 'name' => 'Catálogo próprio'])->save();

        return $catalog;
    });

    ($this->asMember)($this->tenant, $this->owner, static function () use ($action, $own, $foreignCatalog, $foreignProduct, $foreignCompany, $foreignAssignment): void {
        $component = Livewire::test(PrivateCatalogsIndex::class);

        match ($action) {
            'catalog' => $component->call('manage', $foreignCatalog->id)->assertNotFound(),
            'edit' => $component->call('edit', $foreignCatalog->id)->assertNotFound(),
            'product' => $component->call('manage', $own->id)->call('addProduct', $foreignProduct->id)->assertNotFound(),
            'company' => $component->call('manage', $own->id)->call('assignCompany', $foreignCompany->id)->assertNotFound(),
            'assignment' => $component->call('manage', $own->id)->call('unassign', $foreignAssignment->id)->assertNotFound(),
            default => $component->call('manage', 'catalogo-externo')->assertNotFound(),
        };
    });

    expect($other->system(static fn (): int => PrivateCatalogAssignment::query()->count()))->toBe(1)
        ->and($this->tenant->system(static fn (): array => [PrivateCatalogProduct::query()->count(), PrivateCatalogAssignment::query()->count()]))->toBe([0, 0]);
})->with([
    'gerenciar catálogo' => ['catalog'],
    'editar catálogo' => ['edit'],
    'incluir produto' => ['product'],
    'atribuir empresa' => ['company'],
    'remover atribuição' => ['assignment'],
    'identificador malformado' => ['malformed'],
]);

it('exibe catálogos a quem só consulta e recusa alterações no servidor', function (): void {
    $product = Fixtures::product($this->tenant, $this->unit, ['name' => 'Máscara cirúrgica']);
    $catalog = $this->tenant->system(static function (): PrivateCatalog {
        $catalog = new PrivateCatalog;
        $catalog->forceFill(['code' => 'CLINICAS', 'name' => 'Clínicas'])->save();

        return $catalog;
    });

    ($this->asMember)($this->tenant, $this->tenant->addStaff(SystemRole::Viewer), static fn () => Livewire::test(PrivateCatalogsIndex::class)
        ->assertSee('Clínicas')
        ->assertDontSee('Novo catálogo')
        ->call('manage', $catalog->id)
        ->assertDontSee('Incluir produto')
        ->call('addProduct', $product->id)
        ->assertForbidden());

    expect(($this->catalogProducts)())->toBe([]);
});

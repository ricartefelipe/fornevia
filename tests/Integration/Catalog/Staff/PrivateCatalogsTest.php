<?php

declare(strict_types=1);

use App\Actions\Catalog\AssignPrivateCatalog;
use App\Actions\Catalog\SavePrivateCatalog;
use App\Actions\Catalog\SetPrivateCatalogProducts;
use App\Actions\Catalog\UnassignPrivateCatalog;
use App\Data\Catalog\PrivateCatalogData;
use App\Data\Catalog\PrivateCatalogTarget;
use App\Domain\Catalog\Exceptions\CatalogIdentifierInUse;
use App\Domain\Catalog\Exceptions\InvalidCatalogData;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVisibility;
use App\Enums\Identity\SystemRole;
use App\Models\Buyers\CustomerGroup;
use App\Models\Catalog\PrivateCatalog;
use App\Models\Catalog\PrivateCatalogAssignment;
use App\Models\Catalog\PrivateCatalogProduct;
use App\Models\Catalog\Product;
use App\Models\Identity\Membership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Catalog\Staff\CatalogFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->as = fn (TenantScenario $tenant, Membership $actor, Closure $callback): mixed => $tenant->actingAs($actor, $callback);

    $this->saveCatalog = fn (TenantScenario $tenant, ?PrivateCatalog $catalog, PrivateCatalogData $data, ?Membership $actor = null): PrivateCatalog => $tenant->actingAs(
        $actor ?? $tenant->ownerMembership,
        static fn (): PrivateCatalog => app(SavePrivateCatalog::class)->handle($catalog, $data),
    );

    $this->setProducts = fn (TenantScenario $tenant, PrivateCatalog $catalog, array $ids, ?Membership $actor = null): array => $tenant->actingAs(
        $actor ?? $tenant->ownerMembership,
        static fn (): array => app(SetPrivateCatalogProducts::class)->handle($catalog, $ids),
    );

    $this->assign = fn (TenantScenario $tenant, PrivateCatalog $catalog, PrivateCatalogTarget $target, ?Membership $actor = null): PrivateCatalogAssignment => $tenant->actingAs(
        $actor ?? $tenant->ownerMembership,
        static fn (): PrivateCatalogAssignment => app(AssignPrivateCatalog::class)->handle($catalog, $target),
    );

    $this->catalogProducts = fn (TenantScenario $tenant, PrivateCatalog $catalog): array => $tenant->system(
        static fn (): array => PrivateCatalogProduct::query()->where('private_catalog_id', $catalog->id)->pluck('product_id')->sort()->values()->all(),
    );

    $this->visibleTo = fn (TenantScenario $tenant, Membership $buyer): array => $tenant->actingAs(
        $buyer,
        static fn (): array => Product::query()->pluck('name')->sort()->values()->all(),
    );

    $this->tenant = TenantScenario::create();
    $this->unit = Fixtures::unit($this->tenant, 'UN');
});

it('cadastra e altera catálogo privado com código normalizado e auditoria', function (): void {
    $catalog = ($this->saveCatalog)($this->tenant, null, new PrivateCatalogData(' rede-hospitalar ', ' Rede hospitalar ', 'Itens de uso clínico.'));

    ($this->saveCatalog)($this->tenant, $catalog, new PrivateCatalogData('REDE-HOSPITALAR', 'Rede hospitalar regional', null, false));
    $stored = $this->tenant->system(static fn (): PrivateCatalog => PrivateCatalog::query()->findOrFail($catalog->id));

    expect($stored->code)->toBe('REDE-HOSPITALAR')
        ->and($stored->name)->toBe('Rede hospitalar regional')
        ->and($stored->description)->toBeNull()
        ->and($stored->is_active)->toBeFalse()
        ->and(Fixtures::audits($this->tenant, 'private_catalog.created'))->toHaveCount(1)
        ->and(Fixtures::audits($this->tenant, 'private_catalog.updated')->sole()->data)->toEqual([
            'before' => ['code' => 'REDE-HOSPITALAR', 'name' => 'Rede hospitalar', 'is_active' => true],
            'after' => ['code' => 'REDE-HOSPITALAR', 'name' => 'Rede hospitalar regional', 'is_active' => false],
        ]);
});

it('recusa código repetido e código inválido', function (): void {
    ($this->saveCatalog)($this->tenant, null, new PrivateCatalogData('ESCOLAS', 'Escolas particulares'));

    expect(fn () => ($this->saveCatalog)($this->tenant, null, new PrivateCatalogData('escolas', 'Outro')))
        ->toThrow(CatalogIdentifierInUse::class, 'Já existe um catálogo privado com o código ESCOLAS.')
        ->and(fn () => ($this->saveCatalog)($this->tenant, null, new PrivateCatalogData('ESCOLAS PÚBLICAS', 'Outro')))
        ->toThrow(InvalidCatalogData::class);
});

it('sincroniza os produtos do catálogo e audita inclusões e remoções', function (): void {
    $catalog = ($this->saveCatalog)($this->tenant, null, new PrivateCatalogData('ESCOLAS', 'Escolas particulares'));
    $notebook = Fixtures::product($this->tenant, $this->unit, ['name' => 'Caderno universitário']);
    $pencil = Fixtures::product($this->tenant, $this->unit, ['name' => 'Lápis grafite']);
    $eraser = Fixtures::product($this->tenant, $this->unit, ['name' => 'Borracha branca']);

    ($this->setProducts)($this->tenant, $catalog, [$notebook->id, $pencil->id, $notebook->id]);
    ($this->setProducts)($this->tenant, $catalog, [$pencil->id, $eraser->id]);
    ($this->setProducts)($this->tenant, $catalog, [$pencil->id, $eraser->id]);

    $expected = [$pencil->id, $eraser->id];
    sort($expected);

    expect(($this->catalogProducts)($this->tenant, $catalog))->toBe($expected)
        ->and(Fixtures::audits($this->tenant, 'private_catalog.products_updated')->map(static fn ($log): array => $log->data)->all())->toEqual([
            ['added' => [$notebook->id, $pencil->id], 'removed' => []],
            ['added' => [$eraser->id], 'removed' => [$notebook->id]],
        ]);
});

it('recusa produto de outro tenant ou identificador malformado sem alterar o catálogo', function (string $kind): void {
    $other = TenantScenario::create();
    $catalog = ($this->saveCatalog)($this->tenant, null, new PrivateCatalogData('ESCOLAS', 'Escolas particulares'));
    $own = Fixtures::product($this->tenant, $this->unit, ['name' => 'Caderno universitário']);
    $foreign = Fixtures::product($other, Fixtures::unit($other, 'UN'), ['name' => 'Caderno de outro distribuidor']);
    ($this->setProducts)($this->tenant, $catalog, [$own->id]);

    $invalid = $kind === 'foreign' ? $foreign->id : 'caderno-universitario';

    expect(fn () => ($this->setProducts)($this->tenant, $catalog, [$own->id, $invalid]))->toThrow(ModelNotFoundException::class);
    expect(($this->catalogProducts)($this->tenant, $catalog))->toBe([$own->id]);
})->with(['outro tenant' => ['foreign'], 'malformado' => ['malformed']]);

it('atribui a empresa e a grupo sem duplicar e remove a atribuição', function (): void {
    $catalog = ($this->saveCatalog)($this->tenant, null, new PrivateCatalogData('ESCOLAS', 'Escolas particulares'));
    $company = $this->tenant->addBuyerCompany(['trade_name' => 'Colégio Horizonte']);
    $group = $this->tenant->system(static fn (): CustomerGroup => CustomerGroup::query()->create(['code' => 'EDU', 'name' => 'Educação']));

    $first = ($this->assign)($this->tenant, $catalog, PrivateCatalogTarget::buyerCompany($company->id));
    $again = ($this->assign)($this->tenant, $catalog, PrivateCatalogTarget::buyerCompany($company->id));
    $byGroup = ($this->assign)($this->tenant, $catalog, PrivateCatalogTarget::customerGroup($group->id));

    expect($again->id)->toBe($first->id)
        ->and($this->tenant->system(static fn (): int => PrivateCatalogAssignment::query()->count()))->toBe(2)
        ->and(Fixtures::audits($this->tenant, 'private_catalog.assigned')->map(static fn ($log): array => [$log->data['target_type'], $log->data['target_name']])->all())
        ->toBe([['buyer_company', 'Colégio Horizonte'], ['customer_group', 'Educação']]);

    $this->tenant->actingAs($this->tenant->ownerMembership, static fn () => app(UnassignPrivateCatalog::class)->handle($byGroup));

    expect($this->tenant->system(static fn (): array => PrivateCatalogAssignment::query()->pluck('id')->all()))->toBe([$first->id])
        ->and(Fixtures::audits($this->tenant, 'private_catalog.unassigned')->sole()->data)->toEqual([
            'assignment_id' => $byGroup->id,
            'target_type' => 'customer_group',
            'target_id' => $group->id,
        ]);
});

it('recusa atribuição a empresa ou grupo de outro tenant', function (): void {
    $other = TenantScenario::create();
    $catalog = ($this->saveCatalog)($this->tenant, null, new PrivateCatalogData('ESCOLAS', 'Escolas particulares'));
    $foreignCompany = $other->addBuyerCompany();
    $foreignGroup = $other->system(static fn (): CustomerGroup => CustomerGroup::query()->create(['code' => 'EDU', 'name' => 'Educação']));

    expect(fn () => ($this->assign)($this->tenant, $catalog, PrivateCatalogTarget::buyerCompany($foreignCompany->id)))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->assign)($this->tenant, $catalog, PrivateCatalogTarget::customerGroup($foreignGroup->id)))
        ->toThrow(ModelNotFoundException::class);

    expect($this->tenant->system(static fn (): int => PrivateCatalogAssignment::query()->count()))->toBe(0);
});

it('trata identificador de destino malformado como inexistente', function (): void {
    $catalog = ($this->saveCatalog)($this->tenant, null, new PrivateCatalogData('ESCOLAS', 'Escolas particulares'));

    expect(fn () => ($this->assign)($this->tenant, $catalog, PrivateCatalogTarget::buyerCompany('colegio-horizonte')))
        ->toThrow(ModelNotFoundException::class);
});

it('libera produtos restritos apenas aos compradores atribuídos a catálogo ativo', function (): void {
    $catalog = ($this->saveCatalog)($this->tenant, null, new PrivateCatalogData('HOSPITAIS', 'Rede hospitalar'));
    $public = Fixtures::product($this->tenant, $this->unit, ['name' => 'Álcool 70% 1 L', 'status' => ProductStatus::Active]);
    $restricted = Fixtures::product($this->tenant, $this->unit, ['name' => 'Luva cirúrgica estéril', 'status' => ProductStatus::Active, 'visibility' => ProductVisibility::Restricted]);
    $group = $this->tenant->system(static fn (): CustomerGroup => CustomerGroup::query()->create(['code' => 'SAUDE', 'name' => 'Saúde']));
    $hospital = $this->tenant->addBuyerUser($this->tenant->addBuyerCompany(['trade_name' => 'Hospital Vale Verde']));
    $clinic = $this->tenant->addBuyerUser($this->tenant->addBuyerCompany(['trade_name' => 'Clínica Bem Estar', 'customer_group_id' => $group->id]));
    $market = $this->tenant->addBuyerUser($this->tenant->addBuyerCompany(['trade_name' => 'Mercado Central']));
    $hospitalCompany = $this->tenant->system(static fn () => Membership::query()->findOrFail($hospital->id)->buyer_company_id);

    ($this->setProducts)($this->tenant, $catalog, [$restricted->id]);

    expect(($this->visibleTo)($this->tenant, $hospital))->toBe([$public->name]);

    $assignment = ($this->assign)($this->tenant, $catalog, PrivateCatalogTarget::buyerCompany($hospitalCompany));
    ($this->assign)($this->tenant, $catalog, PrivateCatalogTarget::customerGroup($group->id));

    expect(($this->visibleTo)($this->tenant, $hospital))->toBe(collect([$public->name, $restricted->name])->sort()->values()->all())
        ->and(($this->visibleTo)($this->tenant, $clinic))->toBe(collect([$public->name, $restricted->name])->sort()->values()->all())
        ->and(($this->visibleTo)($this->tenant, $market))->toBe([$public->name]);

    ($this->saveCatalog)($this->tenant, $catalog, new PrivateCatalogData('HOSPITAIS', 'Rede hospitalar', null, false));
    expect(($this->visibleTo)($this->tenant, $clinic))->toBe([$public->name]);

    ($this->saveCatalog)($this->tenant, $catalog, new PrivateCatalogData('HOSPITAIS', 'Rede hospitalar'));
    $this->tenant->actingAs($this->tenant->ownerMembership, static fn () => app(UnassignPrivateCatalog::class)->handle($assignment));

    expect(($this->visibleTo)($this->tenant, $hospital))->toBe([$public->name])
        ->and(($this->visibleTo)($this->tenant, $clinic))->toBe(collect([$public->name, $restricted->name])->sort()->values()->all());
});

it('exige catalog.manage em todas as operações de catálogo privado', function (SystemRole $role): void {
    $actor = $this->tenant->addStaff($role);
    $catalog = ($this->saveCatalog)($this->tenant, null, new PrivateCatalogData('ESCOLAS', 'Escolas particulares'));
    $product = Fixtures::product($this->tenant, $this->unit);
    $company = $this->tenant->addBuyerCompany();
    $assignment = ($this->assign)($this->tenant, $catalog, PrivateCatalogTarget::buyerCompany($company->id));

    expect(fn () => ($this->saveCatalog)($this->tenant, null, new PrivateCatalogData('OUTRO', 'Outro'), $actor))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->setProducts)($this->tenant, $catalog, [$product->id], $actor))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->assign)($this->tenant, $catalog, PrivateCatalogTarget::buyerCompany($company->id), $actor))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->tenant->actingAs($actor, static fn () => app(UnassignPrivateCatalog::class)->handle($assignment)))->toThrow(AuthorizationException::class);

    expect(($this->catalogProducts)($this->tenant, $catalog))->toBe([])
        ->and($this->tenant->system(static fn (): int => PrivateCatalogAssignment::query()->count()))->toBe(1);
})->with([
    'gerente comercial' => [SystemRole::SalesManager],
    'representante' => [SystemRole::SalesRep],
]);

it('não altera catálogo privado de outro tenant', function (): void {
    $other = TenantScenario::create();
    $foreign = ($this->saveCatalog)($other, null, new PrivateCatalogData('ESCOLAS', 'Escolas particulares'));
    $product = Fixtures::product($this->tenant, $this->unit);

    expect(fn () => ($this->saveCatalog)($this->tenant, $foreign, new PrivateCatalogData('ESCOLAS', 'Alterado')))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->setProducts)($this->tenant, $foreign, [$product->id]))->toThrow(ModelNotFoundException::class);

    expect($other->system(static fn (): string => PrivateCatalog::query()->findOrFail($foreign->id)->name))->toBe('Escolas particulares');
});

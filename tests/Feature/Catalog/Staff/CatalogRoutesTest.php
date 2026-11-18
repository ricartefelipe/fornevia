<?php

declare(strict_types=1);

use App\Domain\Shared\Money;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Catalog\Product;
use App\Models\Catalog\SkuCost;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Tests\Integration\Catalog\Staff\CatalogFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

/*
| Acesso HTTP às telas do catálogo da equipe: permissão catalog.view em
| todas as rotas, contexto de equipe obrigatório e produtos sempre
| resolvidos no escopo do tenant.
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->tenant = TenantScenario::create();
    $this->unit = Fixtures::unit($this->tenant, 'UN');
    $this->product = Fixtures::product($this->tenant, $this->unit, ['name' => 'Papel sulfite A4 75 g']);

    $this->member = function (SystemRole $role): array {
        $user = User::factory()->withTwoFactor()->create()->fresh();
        $membership = $role === SystemRole::Owner
            ? $this->tenant->ownerMembership
            : $this->tenant->addStaff($role, $user);

        return [$role === SystemRole::Owner ? $this->tenant->owner->fresh() : $user, $membership];
    };

    $this->get = fn (User $user, Membership $membership, string $url) => $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get($url);

    $this->urls = fn (): array => [
        route('catalog.products'),
        route('catalog.products.create'),
        route('catalog.products.edit', $this->product),
        route('catalog.categories'),
        route('catalog.units'),
        route('catalog.private'),
    ];
});

it('nega as telas do catálogo a quem não tem catalog.view', function (): void {
    [$user, $membership] = ($this->member)(SystemRole::Finance);

    foreach (($this->urls)() as $url) {
        ($this->get)($user, $membership, $url)->assertForbidden();
    }
});

it('nega as telas do catálogo da equipe no contexto de empresa compradora', function (): void {
    $user = User::factory()->withTwoFactor()->create()->fresh();
    $membership = $this->tenant->addBuyerUser($this->tenant->addBuyerCompany(), SystemRole::BuyerAdmin, $user);

    foreach ([route('catalog.products'), route('catalog.products.create'), route('catalog.categories'), route('catalog.units'), route('catalog.private')] as $url) {
        ($this->get)($user, $membership, $url)->assertForbidden();
    }

    // O produto é resolvido no escopo do comprador antes da verificação de
    // contexto: o rascunho não é visível pela RLS (404) e o produto ativo e
    // público, visível no portal, esbarra no contexto de equipe (403).
    $active = Fixtures::product($this->tenant, $this->unit, ['name' => 'Caneta esferográfica azul', 'status' => ProductStatus::Active]);

    ($this->get)($user, $membership, route('catalog.products.edit', $this->product))->assertNotFound();
    ($this->get)($user, $membership, route('catalog.products.edit', $active))->assertForbidden();
});

it('redireciona visitantes não autenticados para o login', function (): void {
    $this->get(route('catalog.products'))->assertRedirect(route('login'));
});

it('exibe as telas a perfis de consulta, sem ações de gerenciamento', function (SystemRole $role): void {
    [$user, $membership] = ($this->member)($role);

    ($this->get)($user, $membership, route('catalog.products'))
        ->assertOk()
        ->assertSee('Papel sulfite A4 75 g')
        ->assertDontSee('Novo produto');

    ($this->get)($user, $membership, route('catalog.products.edit', $this->product))
        ->assertOk()
        ->assertSee('Seu perfil permite apenas consultar o catálogo')
        ->assertDontSee('Salvar alterações')
        ->assertDontSee('Cadastrar SKU');

    foreach ([route('catalog.categories'), route('catalog.units'), route('catalog.private')] as $url) {
        ($this->get)($user, $membership, $url)->assertOk();
    }
})->with([
    'consulta' => [SystemRole::Viewer],
    'estoque e expedição' => [SystemRole::Warehouse],
    'representante comercial' => [SystemRole::SalesRep],
]);

it('exibe as ações de gerenciamento ao proprietário com segundo fator', function (): void {
    $owner = $this->tenant->owner;
    $owner->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();

    ($this->get)($owner->fresh(), $this->tenant->ownerMembership, route('catalog.products'))
        ->assertOk()
        ->assertSee('Novo produto');

    ($this->get)($owner->fresh(), $this->tenant->ownerMembership, route('catalog.products.edit', $this->product))
        ->assertOk()
        ->assertSee('Salvar alterações')
        ->assertSee('Cadastrar SKU')
        ->assertSee('Anexar arquivo');
});

it('responde 404 para produto de outro tenant ou identificador malformado', function (): void {
    $other = TenantScenario::create();
    $foreign = Fixtures::product($other, Fixtures::unit($other, 'UN'), ['name' => 'Produto de outro distribuidor']);
    [$user, $membership] = ($this->member)(SystemRole::Viewer);

    ($this->get)($user, $membership, route('catalog.products.edit', ['product' => $foreign->id]))->assertNotFound();
    ($this->get)($user, $membership, '/catalogo/produtos/papel-sulfite')->assertNotFound();

    expect($other->system(static fn (): bool => Product::query()->whereKey($foreign->id)->exists()))->toBeTrue();
});

it('mostra custos e política comercial somente a quem tem catalog.view_costs', function (): void {
    $sku = Fixtures::sku($this->tenant, $this->product, $this->unit, 'SULFITE-A4-500');
    $this->tenant->system(static function () use ($sku): void {
        $cost = new SkuCost;
        $cost->timestamps = false;
        $cost->forceFill(['sku_id' => $sku->id, 'cost_cents' => Money::ofCents(1987), 'effective_from' => now()->subMinute()])->save();
    });

    [$manager, $managerMembership] = ($this->member)(SystemRole::SalesManager);
    [$warehouse, $warehouseMembership] = ($this->member)(SystemRole::Warehouse);

    ($this->get)($manager, $managerMembership, route('catalog.products.edit', $this->product))
        ->assertOk()
        ->assertSee('SULFITE-A4-500')
        ->assertSee('Custo vigente')
        ->assertSee('R$ 19,87');

    ($this->get)($warehouse, $warehouseMembership, route('catalog.products.edit', $this->product))
        ->assertOk()
        ->assertSee('SULFITE-A4-500')
        ->assertDontSee('Custo vigente')
        ->assertDontSee('19,87');
});

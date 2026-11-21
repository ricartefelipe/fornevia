<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Enums\Pricing\PriceListScope;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Models\Pricing\PriceList;
use Tests\Integration\Pricing\PricingFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

/*
| Acesso às telas de preços pelo pipeline HTTP completo (contexto, permissão
| e segundo fator), incluindo route model binding no escopo do tenant.
*/

beforeEach(function (): void {
    $this->withoutVite();
    Fx::freezeClock('2026-11-17T15:00:00Z');
    $this->tenant = TenantScenario::create();
    $this->list = Fx::priceList($this->tenant, 'PADRAO-2026');

    $this->member = function (SystemRole $role): array {
        $user = User::factory()->withTwoFactor()->create()->fresh();
        $membership = $role->kind()->value === 'buyer'
            ? $this->tenant->addBuyerUser(Fx::company($this->tenant, 'Mercado Bom Preço'), $role, $user)
            : $this->tenant->addStaff($role, $user);

        return [$user, $membership];
    };

    $this->visit = fn (User $user, Membership $membership, string $url) => $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get($url);

    $this->urls = fn (): array => [
        route('pricing.lists'),
        route('pricing.lists.edit', ['priceList' => $this->list->id]),
        route('pricing.negotiated'),
        route('pricing.payment-terms'),
        route('pricing.simulator'),
    ];
});

it('nega as telas de preços a perfis sem pricing.view', function (SystemRole $role): void {
    [$user, $membership] = ($this->member)($role);

    foreach (($this->urls)() as $url) {
        ($this->visit)($user, $membership, $url)->assertForbidden();
    }
})->with([
    'estoque e expedição' => [SystemRole::Warehouse],
    'financeiro e crédito' => [SystemRole::Finance],
]);

it('nega as telas de preços da equipe no contexto de empresa compradora', function (): void {
    [$user, $membership] = ($this->member)(SystemRole::BuyerAdmin);

    foreach (($this->urls)() as $url) {
        ($this->visit)($user, $membership, $url)->assertForbidden();
    }
});

it('exige autenticação', function (): void {
    foreach (($this->urls)() as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
});

it('permite consultar as telas com pricing.view, sem controles de alteração', function (SystemRole $role): void {
    [$user, $membership] = ($this->member)($role);

    ($this->visit)($user, $membership, route('pricing.lists'))
        ->assertOk()
        ->assertSee('PADRAO-2026')
        ->assertSee('Seu perfil permite consultar as tabelas')
        ->assertDontSee('Criar tabela');

    ($this->visit)($user, $membership, route('pricing.lists.edit', ['priceList' => $this->list->id]))
        ->assertOk()
        ->assertSee('Tabela PADRAO-2026')
        ->assertDontSee('Salvar cabeçalho');

    ($this->visit)($user, $membership, route('pricing.negotiated'))->assertOk()->assertSee('Condições negociadas')->assertDontSee('Registrar condição');
    ($this->visit)($user, $membership, route('pricing.payment-terms'))->assertOk()->assertSee('AVISTA');
    ($this->visit)($user, $membership, route('pricing.simulator'))->assertOk()->assertSee('Simular');
})->with([
    'consulta' => [SystemRole::Viewer],
    'representante comercial' => [SystemRole::SalesRep],
]);

it('exibe os formulários de gestão a quem tem pricing.manage', function (): void {
    [$user, $membership] = ($this->member)(SystemRole::SalesManager);

    ($this->visit)($user, $membership, route('pricing.lists'))
        ->assertOk()
        ->assertSee('Criar tabela')
        ->assertSee('Margem mínima padrão');

    ($this->visit)($user, $membership, route('pricing.lists.edit', ['priceList' => $this->list->id]))
        ->assertOk()
        ->assertSee('Salvar cabeçalho')
        ->assertSee('Buscar SKU por código ou nome');

    ($this->visit)($user, $membership, route('pricing.negotiated'))->assertOk()->assertSee('Registrar condição');
});

it('responde 404 para tabela de outro tenant ou identificador malformado', function (): void {
    [$user, $membership] = ($this->member)(SystemRole::SalesManager);
    $other = TenantScenario::create();
    $foreign = Fx::priceList($other, 'EXCLUSIVA', PriceListScope::Default);

    ($this->visit)($user, $membership, route('pricing.lists.edit', ['priceList' => $foreign->id]))->assertNotFound();
    ($this->visit)($user, $membership, '/precos/tabelas/PADRAO-2026')->assertNotFound();

    expect($other->system(static fn (): string => PriceList::query()->findOrFail($foreign->id)->code))->toBe('EXCLUSIVA');
});

it('mantém o item de menu de tabelas de preço visível somente com pricing.view', function (): void {
    [$viewerUser, $viewer] = ($this->member)(SystemRole::Viewer);
    [$warehouseUser, $warehouse] = ($this->member)(SystemRole::Warehouse);

    ($this->visit)($viewerUser, $viewer, route('dashboard'))->assertOk()->assertSee(route('pricing.lists'), escape: false);
    ($this->visit)($warehouseUser, $warehouse, route('dashboard'))->assertOk()->assertDontSee(route('pricing.lists'), escape: false);
});

<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Tests\Integration\Inventory\InventoryFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

/*
| Acesso às telas de estoque pelo pipeline HTTP completo (autenticação,
| contexto de equipe, permissões e segundo fator): inventory.view para
| consultar; a tela de movimentação também exige inventory.manage.
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->tenant = TenantScenario::create();
    $this->warehouse = Fx::warehouse($this->tenant, 'CD1', ['name' => 'Centro de distribuição Campinas']);
    $this->sku = Fx::sku($this->tenant, 'PAR-M8', 'UN', 'Parafuso sextavado M8');
    Fx::receive($this->tenant, $this->warehouse, $this->sku, '1250');

    $this->member = function (SystemRole $role): array {
        $user = User::factory()->withTwoFactor()->create()->fresh();
        $membership = $role->kind()->value === 'buyer'
            ? $this->tenant->addBuyerUser($this->tenant->addBuyerCompany(), $role, $user)
            : $this->tenant->addStaff($role, $user);

        return [$user, $membership];
    };

    $this->visit = fn (User $user, Membership $membership, string $url) => $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get($url);

    $this->viewUrls = fn (): array => [route('inventory.index'), route('inventory.movements'), route('inventory.warehouses')];
});

it('usa o prefixo /estoque e o nome inventory.index na tela principal', function (): void {
    expect(route('inventory.index', absolute: false))->toBe('/estoque')
        ->and(route('inventory.movements', absolute: false))->toBe('/estoque/movimentacoes')
        ->and(route('inventory.operations', absolute: false))->toBe('/estoque/movimentar')
        ->and(route('inventory.warehouses', absolute: false))->toBe('/estoque/depositos');
});

it('nega as telas de estoque a perfis sem inventory.view', function (): void {
    [$user, $membership] = ($this->member)(SystemRole::Finance);

    foreach ([...($this->viewUrls)(), route('inventory.operations')] as $url) {
        ($this->visit)($user, $membership, $url)->assertForbidden();
    }
});

it('nega as telas de estoque da equipe no contexto de empresa compradora', function (SystemRole $role): void {
    [$user, $membership] = ($this->member)($role);

    foreach ([...($this->viewUrls)(), route('inventory.operations')] as $url) {
        ($this->visit)($user, $membership, $url)->assertForbidden();
    }
})->with([
    'administrador da empresa' => [SystemRole::BuyerAdmin],
    'comprador' => [SystemRole::Purchaser],
]);

it('exige autenticação', function (): void {
    foreach ([...($this->viewUrls)(), route('inventory.operations')] as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
});

it('permite consultar a perfis com inventory.view, sem ações de movimentação', function (SystemRole $role): void {
    [$user, $membership] = ($this->member)($role);

    ($this->visit)($user, $membership, route('inventory.index'))
        ->assertOk()
        ->assertSee('PAR-M8')
        ->assertSee('1.250 UN')
        ->assertDontSee('Movimentar estoque');

    ($this->visit)($user, $membership, route('inventory.movements'))->assertOk()->assertSee('Entrada');
    ($this->visit)($user, $membership, route('inventory.warehouses'))
        ->assertOk()
        ->assertSee('Centro de distribuição Campinas')
        ->assertDontSee('Novo depósito');

    ($this->visit)($user, $membership, route('inventory.operations'))->assertForbidden();
})->with([
    'consulta' => [SystemRole::Viewer],
    'representante comercial' => [SystemRole::SalesRep],
    'gerente comercial' => [SystemRole::SalesManager],
]);

it('exibe movimentação e cadastro de depósitos a quem tem inventory.manage', function (): void {
    [$user, $membership] = ($this->member)(SystemRole::Warehouse);

    ($this->visit)($user, $membership, route('inventory.index'))
        ->assertOk()
        ->assertSee('Movimentar estoque')
        ->assertSee('Entradas, ajustes e transferências');

    ($this->visit)($user, $membership, route('inventory.operations'))->assertOk()->assertSee('Entrada de mercadoria');
    ($this->visit)($user, $membership, route('inventory.warehouses'))->assertOk()->assertSee('Novo depósito');
});

it('mostra o item Estoque no menu apenas a quem tem inventory.view', function (): void {
    [$stockist, $stockistMembership] = ($this->member)(SystemRole::Warehouse);
    [$finance, $financeMembership] = ($this->member)(SystemRole::Finance);

    ($this->visit)($stockist, $stockistMembership, route('dashboard'))->assertOk()->assertSee(route('inventory.index'));
    ($this->visit)($finance, $financeMembership, route('dashboard'))->assertOk()->assertDontSee(route('inventory.index'));
});

it('não exibe saldos de outro distribuidor', function (): void {
    $other = TenantScenario::create();
    Fx::receive($other, Fx::warehouse($other, 'CD-OUTRO'), Fx::sku($other, 'SKU-ALHEIO'), '77');
    [$user, $membership] = ($this->member)(SystemRole::Viewer);

    ($this->visit)($user, $membership, route('inventory.index'))
        ->assertOk()
        ->assertSee('PAR-M8')
        ->assertDontSee('SKU-ALHEIO')
        ->assertDontSee('CD-OUTRO');

    ($this->visit)($user, $membership, route('inventory.movements'))->assertOk()->assertDontSee('SKU-ALHEIO');
});

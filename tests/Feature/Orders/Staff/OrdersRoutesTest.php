<?php

declare(strict_types=1);

use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Tests\Integration\Ordering\Staff\StaffOrderFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Telas de pedidos pelo pipeline HTTP completo: autenticação, contexto de
| equipe, segundo fator, permission:orders.view e escopo da consulta
| (tenant e carteira do representante).
*/

beforeEach(function (): void {
    $this->withoutVite();
    $this->scenario = OrderingScenario::create(stock: '20');
    $this->tenant = $this->scenario->tenant;
    $this->placed = Fx::place($this->scenario, '2', ['purchaseOrderReference' => 'OC-5512']);
    $this->placed['company'] = Fx::rename($this->tenant, $this->placed['company'], 'Construtora Serra Azul');

    $this->visit = fn (User $user, Membership $membership, string $url) => $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get($url);
    $this->urls = fn (): array => [route('orders.index'), route('orders.show', ['order' => $this->placed['order']->id])];
});

it('exige autenticação', function (): void {
    foreach (($this->urls)() as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
});

it('registra as rotas da equipe no prefixo /pedidos', function (): void {
    expect(route('orders.index', absolute: false))->toBe('/pedidos')
        ->and(route('orders.show', ['order' => $this->placed['order']->id], absolute: false))->toBe('/pedidos/'.$this->placed['order']->id);
});

it('nega as telas de pedidos da equipe no contexto de empresa compradora', function (): void {
    $user = User::factory()->withTwoFactor()->create()->fresh();
    $membership = $this->tenant->addBuyerUser($this->placed['company'], SystemRole::BuyerAdmin, $user);

    foreach (($this->urls)() as $url) {
        ($this->visit)($user, $membership, $url)->assertForbidden();
    }
});

it('nega as telas a papel sem orders.view', function (): void {
    [$user, $membership] = Fx::staffUser($this->tenant, SystemRole::Viewer);
    Fx::adjustRole($this->tenant, SystemRole::Viewer, revoke: [Permission::OrdersView]);

    foreach (($this->urls)() as $url) {
        ($this->visit)($user, $membership, $url)->assertForbidden();
    }
});

it('exibe a lista e o detalhe a quem tem orders.view', function (SystemRole $role): void {
    [$user, $membership] = Fx::staffUser($this->tenant, $role);
    $number = $this->placed['order']->number;

    ($this->visit)($user, $membership, route('orders.index'))
        ->assertOk()
        ->assertSee('<title>Pedidos · Fornevia</title>', escape: false)
        ->assertSee($number)
        ->assertSee('Construtora Serra Azul')
        ->assertSee('OC-5512')
        ->assertSee(route('orders.show', ['order' => $this->placed['order']->id]), escape: false);

    ($this->visit)($user, $membership, route('orders.show', ['order' => $this->placed['order']->id]))
        ->assertOk()
        ->assertSee('<title>Pedido '.$number.' · Fornevia</title>', escape: false)
        ->assertSee('Construtora Serra Azul')
        ->assertSee('Itens')
        ->assertSee('Histórico');
})->with([
    'gerente comercial' => [SystemRole::SalesManager],
    'estoque e expedição' => [SystemRole::Warehouse],
    'financeiro e crédito' => [SystemRole::Finance],
    'consulta' => [SystemRole::Viewer],
]);

it('oferece o cancelamento apenas a quem tem orders.manage', function (): void {
    [$managerUser, $manager] = Fx::staffUser($this->tenant, SystemRole::SalesManager);
    [$viewerUser, $viewer] = Fx::staffUser($this->tenant, SystemRole::Viewer);
    $url = route('orders.show', ['order' => $this->placed['order']->id]);

    ($this->visit)($managerUser, $manager, $url)->assertOk()->assertSee('Cancelar itens não expedidos');
    ($this->visit)($viewerUser, $viewer, $url)->assertOk()
        ->assertDontSee('Cancelar itens não expedidos')
        ->assertSee('Seu perfil permite consultar o pedido');
});

it('responde 404 para pedido de outro distribuidor, fora da carteira ou identificador malformado', function (): void {
    [$user, $membership] = Fx::staffUser($this->tenant, SystemRole::SalesManager);
    [$repUser, $rep] = Fx::staffUser($this->tenant, SystemRole::SalesRep);
    $foreign = Fx::place(OrderingScenario::create(), '1')['order'];

    ($this->visit)($user, $membership, route('orders.show', ['order' => $foreign->id]))->assertNotFound();
    ($this->visit)($user, $membership, '/pedidos/'.$this->placed['order']->number)->assertNotFound();
    ($this->visit)($repUser, $rep, route('orders.show', ['order' => $this->placed['order']->id]))->assertNotFound();

    Fx::assignRep($this->tenant, $this->placed['company'], $rep);

    ($this->visit)($repUser, $rep, route('orders.show', ['order' => $this->placed['order']->id]))->assertOk();
});

it('lista ao representante apenas os pedidos da sua carteira', function (): void {
    [$repUser, $rep] = Fx::staffUser($this->tenant, SystemRole::SalesRep);
    $other = Fx::place($this->scenario, '1');
    Fx::rename($this->tenant, $other['company'], 'Mercado Bom Preço');
    Fx::assignRep($this->tenant, $other['company'], $rep);

    ($this->visit)($repUser, $rep, route('orders.index'))
        ->assertOk()
        ->assertSee('Você vê os pedidos das empresas da sua carteira.')
        ->assertSee($other['order']->number)
        ->assertSee('Mercado Bom Preço')
        ->assertDontSee($this->placed['order']->number)
        ->assertDontSee('Construtora Serra Azul');
});

it('mostra o item de menu Pedidos à equipe com orders.view', function (): void {
    [$user, $membership] = Fx::staffUser($this->tenant, SystemRole::Warehouse);

    ($this->visit)($user, $membership, route('dashboard'))->assertOk()->assertSee(route('orders.index'), escape: false);

    Fx::adjustRole($this->tenant, SystemRole::Warehouse, revoke: [Permission::OrdersView]);

    ($this->visit)($user, $membership, route('dashboard'))->assertOk()->assertDontSee(route('orders.index'), escape: false);
});

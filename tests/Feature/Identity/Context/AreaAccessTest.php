<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Models\Identity\User;
use Tests\Support\Builders\TenantScenario;

/*
| RequireContext: a área da equipe (/painel) e o portal do comprador
| (/portal) exigem contexto ativo do tipo correspondente. Sem contexto, o
| usuário é levado à escolha de contexto; com contexto de outro tipo, 403.
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->tenant = TenantScenario::create(name: 'Distribuidora Pedra Branca');
    $this->company = $this->tenant->addBuyerCompany(['trade_name' => 'Mercado Bom Preço']);
    $this->buyer = $this->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);
    $this->buyerUser = User::query()->findOrFail($this->buyer->user_id);
    $this->staff = $this->tenant->addStaff(SystemRole::Viewer);
    $this->staffUser = User::query()->findOrFail($this->staff->user_id);
});

it('permite à equipe acessar o painel do distribuidor', function (): void {
    $this->actingAs($this->staffUser)
        ->withSession(TenantScenario::sessionFor($this->staff))
        ->get('/painel')
        ->assertOk()
        ->assertSeeText('Equipe do distribuidor');
});

it('permite ao comprador acessar o portal da própria empresa', function (): void {
    $this->actingAs($this->buyerUser)
        ->withSession(TenantScenario::sessionFor($this->buyer))
        ->get('/portal')
        ->assertOk()
        ->assertSeeText('Mercado Bom Preço')
        ->assertSeeText('Portal do comprador');
});

it('nega ao comprador a área da equipe do distribuidor', function (string $uri): void {
    $this->actingAs($this->buyerUser)
        ->withSession(TenantScenario::sessionFor($this->buyer))
        ->get($uri)
        ->assertForbidden();
})->with([
    'painel' => ['/painel'],
    'equipe' => ['/equipe'],
    'filiais' => ['/filiais'],
]);

it('nega à equipe o portal do comprador, inclusive ao proprietário', function (): void {
    $this->actingAs($this->staffUser)
        ->withSession(TenantScenario::sessionFor($this->staff))
        ->get('/portal')
        ->assertForbidden();

    app()->forgetScopedInstances();

    $this->actingAs($this->tenant->owner->refresh())
        ->withSession(TenantScenario::sessionFor($this->tenant->ownerMembership))
        ->get('/portal')
        ->assertForbidden();
});

it('leva à escolha de contexto quando não há contexto ativo', function (string $uri): void {
    $this->actingAs($this->staffUser)
        ->get($uri)
        ->assertRedirect(route('contexts.index'));

    expect(session('url.intended'))->toBe(url($uri));
})->with([
    'painel' => ['/painel'],
    'portal' => ['/portal'],
]);

it('responde 409 em JSON quando não há contexto ativo', function (): void {
    $this->actingAs($this->buyerUser)
        ->getJson('/portal')
        ->assertStatus(409)
        ->assertExactJson(['message' => 'Selecione um contexto.']);
});

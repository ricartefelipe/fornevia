<?php

declare(strict_types=1);

use App\Domain\Shared\Cnpj;
use App\Enums\Credit\CreditMovementType;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Tests\Integration\Credit\CreditFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

/*
| Telas de crédito pelo pipeline HTTP completo: contexto de equipe,
| permissão credit.view, segundo fator para perfis privilegiados e route
| model binding no escopo do tenant.
*/

beforeEach(function (): void {
    $this->withoutVite();
    Fx::freezeClock();
    $this->tenant = TenantScenario::create();
    $this->company = Fx::company($this->tenant, 'Construtora Serra Azul');
    $this->withoutAccount = Fx::company($this->tenant, 'Revenda Vale Verde');
    $account = Fx::open($this->tenant, $this->company, '50.000,00');
    Fx::movement($this->tenant, $account, CreditMovementType::OrderCommitment, 1_250_000, 0);

    $this->visit = fn (User $user, Membership $membership, string $url) => $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get($url);
    $this->urls = fn (): array => [
        route('credit.index'),
        route('credit.show', ['buyer' => $this->company->id]),
    ];
});

it('nega as telas de crédito a perfis sem credit.view', function (): void {
    [$user, $membership] = Fx::staffUser($this->tenant, SystemRole::Warehouse);

    foreach (($this->urls)() as $url) {
        ($this->visit)($user, $membership, $url)->assertForbidden();
    }
});

it('nega as telas de crédito da equipe no contexto de comprador, inclusive da própria empresa', function (): void {
    $user = User::factory()->withTwoFactor()->create()->fresh();
    $membership = $this->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin, $user);

    foreach (($this->urls)() as $url) {
        ($this->visit)($user, $membership, $url)->assertForbidden();
    }
});

it('exige autenticação', function (): void {
    foreach (($this->urls)() as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
});

it('exige segundo fator do perfil que gerencia crédito', function (): void {
    $user = User::factory()->create()->fresh();
    $membership = $this->tenant->addStaff(SystemRole::Finance, $user);

    ($this->visit)($user, $membership, route('credit.index'))->assertRedirect(route('profile.security'));
});

it('responde 404 para empresa de outro distribuidor e identificador malformado', function (): void {
    $foreign = Fx::company(TenantScenario::create(), 'Empresa de outro distribuidor');
    [$user, $membership] = Fx::staffUser($this->tenant, SystemRole::Finance);

    ($this->visit)($user, $membership, route('credit.show', ['buyer' => $foreign->id]))
        ->assertNotFound()
        ->assertDontSee('Empresa de outro distribuidor');

    ($this->visit)($user, $membership, '/credito/'.rawurlencode("{$this->company->id}' or '1'='1"))->assertNotFound();
});

it('mostra lista e detalhe a quem só consulta, sem controles de alteração', function (): void {
    [$user, $membership] = Fx::staffUser($this->tenant, SystemRole::SalesRep);

    ($this->visit)($user, $membership, route('credit.index'))
        ->assertOk()
        ->assertSee('Crédito comercial')
        ->assertSee('Construtora Serra Azul')
        ->assertSee(Cnpj::format($this->company->document))
        ->assertSee('R$ 50.000,00')
        ->assertSee('R$ 12.500,00')
        ->assertSee('R$ 37.500,00')
        ->assertSee('Revenda Vale Verde')
        ->assertSee('Sem conta de crédito: pedidos a prazo são recusados.')
        ->assertDontSee('Abrir conta');

    ($this->visit)($user, $membership, route('credit.show', ['buyer' => $this->company->id]))
        ->assertOk()
        ->assertSee('Posição de crédito')
        ->assertSee('R$ 37.500,00')
        ->assertSee('Novos pedidos a prazo são aceitos até R$ 37.500,00.')
        ->assertSee('Exceções temporárias de limite')
        ->assertSee('Alterações de limite e situação')
        ->assertSee('Limite aprovado pelo comitê de crédito')
        ->assertSee('Comprometimento de pedido')
        ->assertSee('Seu perfil permite consultar o crédito.')
        ->assertDontSee('Alterar limite')
        ->assertDontSee('Bloquear crédito')
        ->assertDontSee('Conceder exceção');
});

it('mostra os controles de gestão a quem gerencia crédito', function (): void {
    [$user, $membership] = Fx::staffUser($this->tenant, SystemRole::Finance);

    ($this->visit)($user, $membership, route('credit.index'))
        ->assertOk()
        ->assertSee('Abrir conta');

    ($this->visit)($user, $membership, route('credit.show', ['buyer' => $this->company->id]))
        ->assertOk()
        ->assertSee('Alterar limite')
        ->assertSee('Bloquear crédito')
        ->assertSee('Alterar tolerância')
        ->assertSee('Conceder exceção');

    ($this->visit)($user, $membership, route('credit.show', ['buyer' => $this->withoutAccount->id]))
        ->assertOk()
        ->assertSee('Esta empresa ainda não tem conta de crédito')
        ->assertSee('Abrir conta de crédito');
});

it('exibe o item de menu de crédito a quem tem credit.view', function (): void {
    [$user, $membership] = Fx::staffUser($this->tenant, SystemRole::Viewer);

    ($this->visit)($user, $membership, route('dashboard'))
        ->assertOk()
        ->assertSee(route('credit.index'), false);
});

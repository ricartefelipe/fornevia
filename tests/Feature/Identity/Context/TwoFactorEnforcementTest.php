<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Models\Identity\User;
use Tests\Support\Builders\TenantScenario;

/*
| RequireTwoFactor: papéis com permissões privilegiadas (gestão de equipe,
| crédito, financeiro, integrações, organização, assinatura) e
| administradores da plataforma só acessam com segundo fator confirmado.
*/

beforeEach(function (): void {
    $this->withoutVite();
    config(['security.two_factor.enforce' => true]);

    $this->tenant = TenantScenario::create(name: 'Distribuidora Pedra Branca');
});

it('redireciona papéis privilegiados sem segundo fator para a página de segurança', function (SystemRole $role): void {
    $membership = $this->tenant->addStaff($role);

    $this->actingAs(User::query()->findOrFail($membership->user_id))
        ->withSession(TenantScenario::sessionFor($membership))
        ->get('/painel')
        ->assertRedirect(route('profile.security'))
        ->assertSessionHas('warning', 'Seu perfil exige autenticação em dois fatores. Ative-a para continuar.');
})->with([
    'administrador' => [SystemRole::Admin],
    'financeiro e crédito' => [SystemRole::Finance],
]);

it('redireciona o proprietário que iniciou mas não confirmou o segundo fator', function (): void {
    $owner = $this->tenant->owner->refresh();
    $owner->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['codigo-recuperacao-1', 'codigo-recuperacao-2'])),
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->actingAs($owner)
        ->withSession(TenantScenario::sessionFor($this->tenant->ownerMembership))
        ->get('/painel')
        ->assertRedirect(route('profile.security'));
});

it('libera papéis sem permissões privilegiadas mesmo sem segundo fator', function (SystemRole $role): void {
    $membership = $this->tenant->addStaff($role);

    $this->actingAs(User::query()->findOrFail($membership->user_id))
        ->withSession(TenantScenario::sessionFor($membership))
        ->get('/painel')
        ->assertOk();
})->with([
    'consulta' => [SystemRole::Viewer],
    'representante comercial' => [SystemRole::SalesRep],
    'estoque e expedição' => [SystemRole::Warehouse],
]);

it('libera o papel privilegiado com segundo fator confirmado', function (): void {
    $membership = $this->tenant->addStaff(SystemRole::Admin, User::factory()->withTwoFactor()->create());

    $this->actingAs(User::query()->findOrFail($membership->user_id))
        ->withSession(TenantScenario::sessionFor($membership))
        ->get('/painel')
        ->assertOk();
});

it('libera o proprietário depois de confirmar o segundo fator', function (): void {
    $owner = $this->tenant->owner->refresh();
    $owner->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['codigo-recuperacao-1', 'codigo-recuperacao-2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($owner)
        ->withSession(TenantScenario::sessionFor($this->tenant->ownerMembership))
        ->get('/equipe')
        ->assertOk();
});

it('avalia o papel do contexto ativo e não de outras associações do usuário', function (): void {
    $user = User::factory()->create()->refresh();
    $viewer = $this->tenant->addStaff(SystemRole::Viewer, $user);
    $other = TenantScenario::create(name: 'Atacado Lagoa Funda');
    $admin = $other->addStaff(SystemRole::Admin, $user);

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($viewer))
        ->get('/painel')
        ->assertOk();

    app()->forgetScopedInstances();

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($admin))
        ->get('/painel')
        ->assertRedirect(route('profile.security'));
});

it('responde 403 em JSON ao papel privilegiado sem segundo fator', function (): void {
    $this->actingAs($this->tenant->owner->refresh())
        ->withSession(TenantScenario::sessionFor($this->tenant->ownerMembership))
        ->getJson('/painel')
        ->assertForbidden()
        ->assertExactJson(['message' => 'Ative a autenticação em dois fatores.']);
});

it('exige segundo fator do administrador da plataforma', function (): void {
    $this->actingAs(User::factory()->platformAdmin()->create()->refresh())
        ->get('/plataforma/organizacoes')
        ->assertRedirect(route('profile.security'));
});

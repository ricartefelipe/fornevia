<?php

declare(strict_types=1);

use App\Domain\Shared\Cnpj;
use App\Models\Identity\User;
use Database\Seeders\PlanSeeder;
use Tests\Support\Builders\TenantScenario;

/*
 * Usuários autenticados são recarregados do banco (fresh): o modelo devolvido
 * pela factory não traz colunas preenchidas por valor padrão, como
 * is_platform_admin e disabled_at, lidas pelos middlewares.
 */
beforeEach(function (): void {
    $this->withoutVite();
    (new PlanSeeder)->run();
});

dataset('rotas da plataforma', [
    'planos' => ['platform.plans'],
    'organizações' => ['platform.organizations'],
]);

it('redireciona visitantes não autenticados para o login', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('login'));
})->with('rotas da plataforma');

it('responde 403 a usuários que não são administradores da plataforma', function (string $route): void {
    $common = User::factory()->create()->fresh();
    $withTwoFactor = User::factory()->withTwoFactor()->create()->fresh();

    $this->actingAs($common)->get(route($route))->assertForbidden();
    $this->actingAs($withTwoFactor)->get(route($route))->assertForbidden();
})->with('rotas da plataforma');

it('responde 403 ao proprietário de um distribuidor, mesmo dentro do contexto da própria organização', function (string $route): void {
    $tenant = TenantScenario::create();
    $owner = $tenant->owner->fresh();
    $owner->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($owner)
        ->withSession(TenantScenario::sessionFor($tenant->ownerMembership))
        ->get(route($route))
        ->assertForbidden();
})->with('rotas da plataforma');

it('leva o administrador sem 2FA confirmado à configuração de segurança da conta', function (string $route, Closure $state): void {
    $admin = User::factory()->platformAdmin()->create($state())->fresh();

    $this->actingAs($admin)
        ->get(route($route))
        ->assertRedirect(route('profile.security'))
        ->assertSessionHas('warning', 'Seu perfil exige autenticação em dois fatores. Ative-a para continuar.');

    $this->actingAs($admin)
        ->getJson(route($route))
        ->assertForbidden()
        ->assertExactJson(['message' => 'Ative a autenticação em dois fatores.']);
})->with('rotas da plataforma')->with([
    'sem 2FA' => [fn (): array => []],
    '2FA iniciado e não confirmado' => [fn (): array => ['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => null]],
]);

it('exige e-mail confirmado também do administrador da plataforma', function (string $route): void {
    $admin = User::factory()->platformAdmin()->withTwoFactor()->unverified()->create()->fresh();

    $this->actingAs($admin)->get(route($route))->assertRedirect(route('verification.notice'));
})->with('rotas da plataforma');

it('exibe os planos ao administrador com 2FA confirmado', function (): void {
    $admin = User::factory()->platformAdmin()->withTwoFactor()->create()->fresh();

    $this->actingAs($admin)
        ->get(route('platform.plans'))
        ->assertOk()
        ->assertSeeInOrder(['Essencial', 'Profissional', 'Corporativo'])
        ->assertSee('R$ 2.490,00')
        ->assertSee('quotes, approvals, returns, imports');
});

it('exibe as organizações com plano e situação, sem dados comerciais do tenant', function (): void {
    $admin = User::factory()->platformAdmin()->withTwoFactor()->create()->fresh();
    $tenant = TenantScenario::create('corporativo', 'Mercantil Rio Claro');
    $company = $tenant->addBuyerCompany(['trade_name' => 'Mercearia Recanto das Flores']);

    $this->actingAs($admin)
        ->get(route('platform.organizations'))
        ->assertOk()
        ->assertSee('Mercantil Rio Claro')
        ->assertSee(Cnpj::format($tenant->organization->document))
        ->assertSee('Corporativo')
        ->assertSee('Em avaliação')
        ->assertSee('Período de avaliação')
        ->assertDontSee($company->trade_name);
});

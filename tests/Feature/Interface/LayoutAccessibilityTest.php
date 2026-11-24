<?php

declare(strict_types=1);

use App\Models\Identity\User;
use Tests\Support\Builders\TenantScenario;

/*
| Layout autenticado: nome acessível do perfil em qualquer largura, acesso à
| escolha de contexto mesmo sem contexto ativo e iniciais do avatar.
*/

beforeEach(function (): void {
    $this->withoutVite();
});

it('dá nome ao link do perfil e oferece a escolha de contexto sem contexto ativo', function (): void {
    $user = User::factory()->create(['name' => '3M'])->fresh();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get(route('profile.security'))->assertOk()
        ->assertSee('aria-label="Perfil e segurança de 3M"', escape: false)
        ->assertSee('Escolher contexto')
        ->assertSee('>3<', escape: false);
});

it('mostra a troca de contexto e as iniciais com contexto ativo', function (): void {
    $tenant = TenantScenario::create(name: 'Distribuidora Pedra Branca');
    $user = User::factory()->create(['name' => 'Ana · Beatriz Souza'])->fresh();
    $membership = $tenant->addStaff(App\Enums\Identity\SystemRole::SalesRep, $user);

    $this->actingAs($user)->withSession([...TenantScenario::sessionFor($membership), 'auth.password_confirmed_at' => time()])->get(route('profile.security'))->assertOk()
        ->assertSee('Trocar contexto')
        ->assertSee('>AB<', escape: false)
        ->assertDontSee('Escolher contexto');
});

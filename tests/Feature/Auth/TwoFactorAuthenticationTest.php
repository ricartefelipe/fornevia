<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Models\Identity\User;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\Builders\TenantScenario;

function codigoTotpAtual(User $user): string
{
    return (new Google2FA)->getCurrentOtp(decrypt((string) $user->fresh()->two_factor_secret));
}

function codigoTotpVencido(User $user): string
{
    $google2fa = new Google2FA;

    // Código de 10 minutos atrás: formato válido, fora da janela de tolerância.
    return $google2fa->oathTotp(decrypt((string) $user->fresh()->two_factor_secret), $google2fa->getTimestamp() - 20);
}

/**
 * @return list<string>
 */
function codigosDeRecuperacao(User $user): array
{
    /** @var list<string> $codes */
    $codes = json_decode((string) decrypt((string) $user->fresh()->two_factor_recovery_codes), true);

    return $codes;
}

it('exige confirmação de senha antes de ativar a autenticação em dois fatores', function (): void {
    $user = User::factory()->create()->fresh();

    $this->actingAs($user)
        ->post('/user/two-factor-authentication')
        ->assertRedirect(route('password.confirm'));
    expect($user->fresh()->two_factor_secret)->toBeNull();

    $this->postJson('/user/two-factor-authentication')->assertStatus(423);
    expect($user->fresh()->two_factor_secret)->toBeNull();

    $this->from('/user/confirm-password')
        ->post('/user/confirm-password', ['password' => 'senha-incorreta-999'])
        ->assertSessionHasErrors('password');
    $this->post('/user/two-factor-authentication')->assertRedirect(route('password.confirm'));
    expect($user->fresh()->two_factor_secret)->toBeNull();

    $this->post('/user/confirm-password', ['password' => 'senha-segura-123'])->assertSessionHasNoErrors();

    $this->from('/perfil/seguranca')
        ->post('/user/two-factor-authentication')
        ->assertRedirect('/perfil/seguranca')
        ->assertSessionHas('status', 'two-factor-authentication-enabled');

    $ativado = $user->fresh();
    expect($ativado->two_factor_secret)->not->toBeNull()
        ->and($ativado->two_factor_confirmed_at)->toBeNull()
        ->and($ativado->hasConfirmedTwoFactor())->toBeFalse()
        ->and(codigosDeRecuperacao($ativado))->toHaveCount(8);
});

it('confirma a ativação apenas com código TOTP válido e passa a exigir o desafio no login', function (): void {
    $user = User::factory()->create(['email' => 'lucas.ferraz@atacado-exemplo.com.br'])->fresh();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/user/two-factor-authentication')
        ->assertSessionHasNoErrors();

    // Enquanto não confirmado, o login não pede o segundo fator.
    expect($user->fresh()->hasConfirmedTwoFactor())->toBeFalse();

    $this->from('/perfil/seguranca')
        ->post('/user/confirmed-two-factor-authentication', ['code' => codigoTotpVencido($user)])
        ->assertRedirect('/perfil/seguranca')
        ->assertSessionHasErrorsIn('confirmTwoFactorAuthentication', 'code');
    $this->post('/user/confirmed-two-factor-authentication', ['code' => ''])
        ->assertSessionHasErrorsIn('confirmTwoFactorAuthentication', 'code');
    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();

    $codigo = codigoTotpAtual($user);
    $this->from('/perfil/seguranca')
        ->post('/user/confirmed-two-factor-authentication', ['code' => $codigo])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'two-factor-authentication-confirmed');
    expect($user->fresh()->hasConfirmedTwoFactor())->toBeTrue();

    $this->post('/logout');
    $this->assertGuest();

    $this->post('/login', ['email' => $user->email, 'password' => 'senha-segura-123'])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
    expect(session('login.id'))->toBe($user->id);

    $this->get('/two-factor-challenge')->assertOk();
    $this->get('/contextos')->assertRedirect('/login');

    // O código já usado na confirmação não pode ser reaproveitado.
    $this->post('/two-factor-challenge', ['code' => $codigo])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHasErrors('code');
    $this->assertGuest();

    $recuperacao = codigosDeRecuperacao($user)[0];
    $this->post('/two-factor-challenge', ['recovery_code' => $recuperacao])
        ->assertRedirect('/contextos');
    $this->assertAuthenticatedAs($user);

    expect(codigosDeRecuperacao($user))->toHaveCount(8)->not->toContain($recuperacao);
});

it('conclui o login com código TOTP válido no desafio', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'senha-segura-123'])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();

    $this->from('/two-factor-challenge')
        ->post('/two-factor-challenge', ['code' => codigoTotpVencido($user)])
        ->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->post('/two-factor-challenge', ['code' => codigoTotpAtual($user)])->assertRedirect('/contextos');
    $this->assertAuthenticatedAs($user);
});

it('recusa código de recuperação inválido ou já utilizado no desafio', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'senha-segura-123']);
    $this->post('/two-factor-challenge', ['recovery_code' => 'codigo-recuperacao-1'])->assertRedirect('/contextos');
    $this->assertAuthenticatedAs($user);

    $this->post('/logout');
    $this->post('/login', ['email' => $user->email, 'password' => 'senha-segura-123'])
        ->assertRedirect(route('two-factor.login'));

    $this->post('/two-factor-challenge', ['recovery_code' => 'codigo-recuperacao-1'])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHasErrors('recovery_code');
    $this->post('/two-factor-challenge', ['recovery_code' => 'codigo-inexistente'])
        ->assertSessionHasErrors('recovery_code');
    $this->assertGuest();
});

it('limita as tentativas do desafio de dois fatores a 5 por minuto', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'senha-segura-123']);

    for ($i = 0; $i < 5; $i++) {
        $this->post('/two-factor-challenge', ['code' => codigoTotpVencido($user)])->assertSessionHasErrors('code');
    }

    $this->post('/two-factor-challenge', ['code' => codigoTotpAtual($user)])->assertStatus(429);
    $this->assertGuest();
});

it('não exibe o desafio de dois fatores sem login pendente', function (): void {
    $this->get('/two-factor-challenge')->assertRedirect('/login');
    $this->assertGuest();

    $this->post('/two-factor-challenge', ['code' => '123456'])->assertRedirect();
    $this->post('/two-factor-challenge', ['recovery_code' => 'codigo-recuperacao-1'])->assertRedirect();
    $this->assertGuest();
});

it('exige confirmação de senha para desativar o segundo fator ou consultar seus segredos', function (string $metodo, string $uri): void {
    $user = User::factory()->withTwoFactor()->create()->fresh();
    $segredo = $user->two_factor_secret;

    $this->actingAs($user)->call($metodo, $uri)->assertRedirect(route('password.confirm'));

    expect($user->fresh()->two_factor_secret)->toBe($segredo)
        ->and($user->fresh()->hasConfirmedTwoFactor())->toBeTrue();
})->with([
    'desativar' => ['DELETE', '/user/two-factor-authentication'],
    'códigos de recuperação' => ['GET', '/user/two-factor-recovery-codes'],
    'regenerar códigos' => ['POST', '/user/two-factor-recovery-codes'],
    'chave secreta' => ['GET', '/user/two-factor-secret-key'],
    'QR Code' => ['GET', '/user/two-factor-qr-code'],
]);

it('exige segundo fator confirmado do proprietário antes de liberar o painel', function (): void {
    $tenant = TenantScenario::create();
    $owner = $tenant->owner->fresh();

    $this->actingAs($owner)
        ->withSession(TenantScenario::sessionFor($tenant->ownerMembership))
        ->get('/painel')
        ->assertRedirect(route('profile.security'));

    $owner->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['codigo-recuperacao-1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($owner->fresh())
        ->withSession(TenantScenario::sessionFor($tenant->ownerMembership))
        ->get('/painel')
        ->assertOk();
});

it('não exige segundo fator de perfil sem permissões privilegiadas', function (): void {
    $tenant = TenantScenario::create();
    $vendedor = $tenant->addStaff(SystemRole::SalesRep);

    $this->actingAs($vendedor->user()->firstOrFail())
        ->withSession(TenantScenario::sessionFor($vendedor))
        ->get('/painel')
        ->assertOk();
});

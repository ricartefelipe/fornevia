<?php

declare(strict_types=1);

use App\Models\Identity\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
});

it('exige nova verificação ao alterar o e-mail pelo perfil', function (): void {
    $user = User::factory()->create(['email' => 'renato.lima@atacado-exemplo.com.br'])->fresh();

    $this->actingAs($user)
        ->from('/perfil/seguranca')
        ->put('/user/profile-information', ['name' => 'Renato Lima', 'email' => 'Renato.Lima@Novo-Atacado-Exemplo.com.br'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/perfil/seguranca');

    $atualizado = $user->fresh();
    expect($atualizado->email)->toBe('renato.lima@novo-atacado-exemplo.com.br')
        ->and($atualizado->email_verified_at)->toBeNull();
    Notification::assertSentTo($atualizado, VerifyEmail::class);

    $this->actingAs($atualizado)->get('/contextos')->assertRedirect(route('verification.notice'));
});

it('mantém a verificação ao salvar o perfil sem alterar o e-mail', function (): void {
    $user = User::factory()->create(['email' => 'renato.lima@atacado-exemplo.com.br'])->fresh();
    $verificadoEm = $user->email_verified_at;

    $this->actingAs($user)
        ->put('/user/profile-information', ['name' => 'Renato Lima Filho', 'email' => 'Renato.Lima@Atacado-Exemplo.com.br'])
        ->assertSessionHasNoErrors();

    $atualizado = $user->fresh();
    expect($atualizado->name)->toBe('Renato Lima Filho')
        ->and($atualizado->email)->toBe('renato.lima@atacado-exemplo.com.br')
        ->and($atualizado->email_verified_at?->equalTo($verificadoEm))->toBeTrue();
    Notification::assertNothingSent();
});

it('recusa no perfil e-mail já usado por outro usuário em qualquer caixa', function (): void {
    User::factory()->create(['email' => 'fernanda.rocha@atacado-exemplo.com.br']);
    $user = User::factory()->create(['email' => 'renato.lima@atacado-exemplo.com.br'])->fresh();

    $this->actingAs($user)
        ->from('/perfil/seguranca')
        ->put('/user/profile-information', ['name' => 'Renato Lima', 'email' => 'FERNANDA.ROCHA@atacado-exemplo.com.br'])
        ->assertRedirect('/perfil/seguranca')
        ->assertSessionHasErrorsIn('updateProfileInformation', 'email');

    expect($user->fresh()->email)->toBe('renato.lima@atacado-exemplo.com.br');
    Notification::assertNothingSent();
});

it('exige a senha atual e senha forte para trocar a senha', function (array $dados, string $campo): void {
    $user = User::factory()->create()->fresh();

    $this->actingAs($user)
        ->from('/perfil/seguranca')
        ->put('/user/password', $dados)
        ->assertRedirect('/perfil/seguranca')
        ->assertSessionHasErrorsIn('updatePassword', $campo);

    expect(password_verify('senha-segura-123', $user->fresh()->password))->toBeTrue();
})->with([
    'senha atual incorreta' => [[
        'current_password' => 'senha-incorreta-999',
        'password' => 'NovaSenhaComercial2026',
        'password_confirmation' => 'NovaSenhaComercial2026',
    ], 'current_password'],
    'nova senha fraca' => [[
        'current_password' => 'senha-segura-123',
        'password' => 'curta',
        'password_confirmation' => 'curta',
    ], 'password'],
]);

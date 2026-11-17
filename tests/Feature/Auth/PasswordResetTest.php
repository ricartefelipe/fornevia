<?php

declare(strict_types=1);

use App\Models\Identity\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * Solicita o link de redefinição e devolve o token enviado por e-mail.
 */
function solicitarTokenDeRedefinicao(User $user, ?string $emailInformado = null): string
{
    test()->post('/forgot-password', ['email' => $emailInformado ?? $user->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    expect($token)->toBeString()->not->toBe('');

    return (string) $token;
}

beforeEach(function (): void {
    Notification::fake();
    $this->user = User::factory()->create(['email' => 'beatriz.campos@atacado-exemplo.com.br']);
});

it('envia o link de redefinição e permite redefinir a senha com o token recebido', function (): void {
    $token = solicitarTokenDeRedefinicao($this->user, 'Beatriz.Campos@Atacado-Exemplo.com.br');

    $this->get('/reset-password/'.$token.'?email='.urlencode($this->user->email))->assertOk();

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $this->user->email,
        'password' => 'NovaSenhaComercial2026',
        'password_confirmation' => 'NovaSenhaComercial2026',
    ])->assertSessionHasNoErrors()->assertRedirect('/login');

    $this->assertGuest();
    expect(Hash::check('NovaSenhaComercial2026', $this->user->fresh()->password))->toBeTrue();

    $this->from('/login')
        ->post('/login', ['email' => $this->user->email, 'password' => 'senha-segura-123'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->post('/login', ['email' => $this->user->email, 'password' => 'NovaSenhaComercial2026'])
        ->assertRedirect('/contextos');
    $this->assertAuthenticatedAs($this->user);
});

it('não aceita reutilizar o token de redefinição', function (): void {
    $token = solicitarTokenDeRedefinicao($this->user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $this->user->email,
        'password' => 'NovaSenhaComercial2026',
        'password_confirmation' => 'NovaSenhaComercial2026',
    ])->assertSessionHasNoErrors();

    $this->from('/reset-password/'.$token)
        ->post('/reset-password', [
            'token' => $token,
            'email' => $this->user->email,
            'password' => 'TentativaDeReuso2026',
            'password_confirmation' => 'TentativaDeReuso2026',
        ])
        ->assertRedirect('/reset-password/'.$token)
        ->assertSessionHasErrors('email');

    $senhaAtual = $this->user->fresh()->password;
    expect(Hash::check('NovaSenhaComercial2026', $senhaAtual))->toBeTrue()
        ->and(Hash::check('TentativaDeReuso2026', $senhaAtual))->toBeFalse();
});

it('recusa token inválido, de outro usuário ou expirado', function (): void {
    $outro = User::factory()->create(['email' => 'henrique.dias@atacado-exemplo.com.br']);
    $tokenDoOutro = solicitarTokenDeRedefinicao($outro);
    $token = solicitarTokenDeRedefinicao($this->user);

    $tentar = fn (string $tokenUsado) => $this->from('/login')->post('/reset-password', [
        'token' => $tokenUsado,
        'email' => $this->user->email,
        'password' => 'SenhaInvasora2026',
        'password_confirmation' => 'SenhaInvasora2026',
    ]);

    $tentar('token-forjado-0000000000000000')->assertSessionHasErrors('email');
    $tentar($tokenDoOutro)->assertSessionHasErrors('email');

    // Tokens expiram em 60 minutos.
    $this->travel(61)->minutes();
    $tentar($token)->assertSessionHasErrors('email');

    expect(Hash::check('senha-segura-123', $this->user->fresh()->password))->toBeTrue()
        ->and(Hash::check('senha-segura-123', $outro->fresh()->password))->toBeTrue();
});

it('exige senha forte na redefinição sem consumir o token', function (): void {
    $token = solicitarTokenDeRedefinicao($this->user);

    $this->from('/reset-password/'.$token)
        ->post('/reset-password', [
            'token' => $token,
            'email' => $this->user->email,
            'password' => 'fraca',
            'password_confirmation' => 'fraca',
        ])
        ->assertSessionHasErrors('password');

    expect(Hash::check('senha-segura-123', $this->user->fresh()->password))->toBeTrue();

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $this->user->email,
        'password' => 'NovaSenhaComercial2026',
        'password_confirmation' => 'NovaSenhaComercial2026',
    ])->assertSessionHasNoErrors()->assertRedirect('/login');

    expect(Hash::check('NovaSenhaComercial2026', $this->user->fresh()->password))->toBeTrue();
});

it('não envia link de redefinição para e-mail sem cadastro', function (): void {
    $this->from('/forgot-password')->post('/forgot-password', ['email' => 'desconhecido@atacado-exemplo.com.br']);

    Notification::assertNothingSent();
});

it('renova o token de lembrar acesso ao redefinir a senha', function (): void {
    $anterior = $this->user->fresh()->getRememberToken();
    $token = solicitarTokenDeRedefinicao($this->user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $this->user->email,
        'password' => 'NovaSenhaComercial2026',
        'password_confirmation' => 'NovaSenhaComercial2026',
    ])->assertSessionHasNoErrors();

    expect($this->user->fresh()->getRememberToken())->not->toBe($anterior)->not->toBeNull();
});

<?php

declare(strict_types=1);

use App\Models\Identity\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cadastroValido(array $overrides = []): array
{
    return [
        'name' => 'Mariana Albuquerque',
        'email' => 'mariana.albuquerque@atacadista-exemplo.com.br',
        'password' => 'Distribuicao2026Segura',
        'password_confirmation' => 'Distribuicao2026Segura',
        'terms' => '1',
        ...$overrides,
    ];
}

beforeEach(function (): void {
    Notification::fake();
});

it('cadastra o usuário com e-mail normalizado, autentica e envia a verificação de e-mail', function (): void {
    $this->post('/register', cadastroValido(['email' => 'Mariana.Albuquerque@Atacadista-Exemplo.COM.BR']))
        ->assertSessionHasNoErrors()
        ->assertRedirect('/contextos');

    $user = User::query()->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->email)->toBe('mariana.albuquerque@atacadista-exemplo.com.br')
        ->and($user->name)->toBe('Mariana Albuquerque')
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->is_platform_admin)->toBeFalse()
        ->and($user->getAttributes()['password'])->not->toBe('Distribuicao2026Segura')
        ->and(Hash::check('Distribuicao2026Segura', $user->password))->toBeTrue();

    Notification::assertSentTo($user, VerifyEmail::class);

    // Até confirmar o e-mail, a seleção de contexto continua bloqueada.
    $this->get('/contextos')->assertRedirect(route('verification.notice'));
});

it('recusa o cadastro sem o aceite dos termos de uso', function (array $termos): void {
    $dados = cadastroValido();
    unset($dados['terms']);

    $this->from('/register')
        ->post('/register', [...$dados, ...$termos])
        ->assertRedirect('/register')
        ->assertSessionHasErrors('terms')
        ->assertSessionDoesntHaveErrors(['name', 'email', 'password']);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'campo ausente' => [[]],
    'caixa desmarcada' => [['terms' => '0']],
    'valor vazio' => [['terms' => '']],
]);

it('recusa e-mail já cadastrado mesmo com diferença de maiúsculas e minúsculas', function (string $email): void {
    $existente = User::factory()->create(['email' => 'joana.prado@comercial-exemplo.com.br']);

    $this->from('/register')
        ->post('/register', cadastroValido(['email' => $email]))
        ->assertRedirect('/register')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::query()->pluck('id')->all())->toBe([$existente->id]);
})->with([
    'idêntico' => 'joana.prado@comercial-exemplo.com.br',
    'domínio em maiúsculas' => 'joana.prado@COMERCIAL-EXEMPLO.COM.BR',
    'caixa mista' => 'Joana.Prado@Comercial-Exemplo.com.br',
]);

it('exige senha forte e confirmada no cadastro', function (string $senha, string $confirmacao): void {
    $this->from('/register')
        ->post('/register', cadastroValido(['password' => $senha, 'password_confirmation' => $confirmacao]))
        ->assertRedirect('/register')
        ->assertSessionHasErrors('password')
        ->assertSessionDoesntHaveErrors(['name', 'email', 'terms']);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
})->with([
    'menos de 12 caracteres' => ['Pedido2026', 'Pedido2026'],
    'sem números' => ['SomenteLetrasNaSenha', 'SomenteLetrasNaSenha'],
    'sem letras' => ['202611170000', '202611170000'],
    'confirmação divergente' => ['Distribuicao2026Segura', 'Distribuicao2026Segur4'],
    'vazia' => ['', ''],
]);

it('recusa e-mail em formato inválido', function (): void {
    $this->from('/register')
        ->post('/register', cadastroValido(['email' => 'mariana.albuquerque@']))
        ->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe(0);
});

it('trata e-mail em formato de lista no cadastro como erro de validação, sem erro interno', function (): void {
    $this->from('/register')
        ->post('/register', cadastroValido(['email' => ['mariana.albuquerque@atacadista-exemplo.com.br']]))
        ->assertRedirect('/register')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('não permite acessar o formulário de cadastro estando autenticado', function (): void {
    $user = User::factory()->create()->fresh();

    $this->actingAs($user)->get('/register')->assertRedirect('/contextos');

    $this->post('/register', cadastroValido())->assertRedirect('/contextos');

    expect(User::query()->count())->toBe(1);
});

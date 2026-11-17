<?php

declare(strict_types=1);

use App\Models\Identity\User;
use Illuminate\Support\Facades\URL;

const SENHA_PADRAO_DA_FABRICA = 'senha-segura-123';

it('autentica com credenciais válidas e redireciona para a seleção de contexto', function (): void {
    $user = User::factory()->create(['email' => 'rafael.monteiro@distribuidora-exemplo.com.br']);

    $this->post('/login', ['email' => 'rafael.monteiro@distribuidora-exemplo.com.br', 'password' => SENHA_PADRAO_DA_FABRICA])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/contextos');

    $this->assertAuthenticatedAs($user);
});

it('normaliza o e-mail informado no login', function (): void {
    $user = User::factory()->create(['email' => 'rafael.monteiro@distribuidora-exemplo.com.br']);

    $this->post('/login', ['email' => 'Rafael.Monteiro@Distribuidora-Exemplo.COM.BR', 'password' => SENHA_PADRAO_DA_FABRICA])
        ->assertRedirect('/contextos');

    $this->assertAuthenticatedAs($user);
});

it('recusa credenciais inválidas com a mesma mensagem para senha errada e e-mail inexistente', function (): void {
    User::factory()->create(['email' => 'rafael.monteiro@distribuidora-exemplo.com.br']);

    $this->from('/login')
        ->post('/login', ['email' => 'rafael.monteiro@distribuidora-exemplo.com.br', 'password' => 'senha-incorreta-999'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email')
        ->assertSessionDoesntHaveErrors('password');
    $this->assertGuest();
    $senhaErrada = session('errors')->get('email');

    $this->from('/login')
        ->post('/login', ['email' => 'ninguem@distribuidora-exemplo.com.br', 'password' => SENHA_PADRAO_DA_FABRICA])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    expect(session('errors')->get('email'))->toBe($senhaErrada);
});

it('exige e-mail e senha no login', function (): void {
    $this->from('/login')
        ->post('/login', ['email' => '', 'password' => ''])
        ->assertSessionHasErrors(['email', 'password']);

    $this->assertGuest();
});

it('trata e-mail em formato de lista no login como erro de validação, sem erro interno', function (): void {
    $user = User::factory()->create(['email' => 'rafael.monteiro@distribuidora-exemplo.com.br']);

    $this->from('/login')
        ->post('/login', ['email' => [$user->email], 'password' => SENHA_PADRAO_DA_FABRICA])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('limita a 5 tentativas de login por minuto para o mesmo e-mail e IP', function (): void {
    config(['app.trusted_proxies' => '']);
    $user = User::factory()->create(['email' => 'carla.nogueira@distribuidora-exemplo.com.br']);

    // Variações de caixa no e-mail compartilham o mesmo limite.
    $variacoes = [
        'carla.nogueira@distribuidora-exemplo.com.br',
        'Carla.Nogueira@distribuidora-exemplo.com.br',
        'CARLA.NOGUEIRA@DISTRIBUIDORA-EXEMPLO.COM.BR',
        'carla.nogueira@Distribuidora-Exemplo.com.br',
        'cArLa.nOgUeIrA@distribuidora-exemplo.com.br',
    ];

    foreach ($variacoes as $email) {
        $this->from('/login')
            ->post('/login', ['email' => $email, 'password' => 'tentativa-errada-1'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
    }

    // A sexta tentativa é bloqueada mesmo com a senha correta.
    $this->post('/login', ['email' => $user->email, 'password' => SENHA_PADRAO_DA_FABRICA])->assertStatus(429);
    $this->assertGuest();

    // Cabeçalho de proxy não confiável não altera o IP considerado no limite.
    $this->withHeader('X-Forwarded-For', '198.51.100.77')
        ->post('/login', ['email' => $user->email, 'password' => SENHA_PADRAO_DA_FABRICA])
        ->assertStatus(429);
    $this->assertGuest();

    // Outro e-mail no mesmo IP não é afetado.
    $this->from('/login')
        ->post('/login', ['email' => 'outro.usuario@distribuidora-exemplo.com.br', 'password' => 'tentativa-errada-1'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    // Passado o intervalo de um minuto, o login volta a ser aceito.
    $this->travel(61)->seconds();

    $this->post('/login', ['email' => $user->email, 'password' => SENHA_PADRAO_DA_FABRICA])->assertRedirect('/contextos');
    $this->assertAuthenticatedAs($user);
});

it('aplica o limite de tentativas separadamente por IP', function (): void {
    User::factory()->create(['email' => 'carla.nogueira@distribuidora-exemplo.com.br']);

    for ($i = 0; $i < 5; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/login', ['email' => 'carla.nogueira@distribuidora-exemplo.com.br', 'password' => 'tentativa-errada-1']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->post('/login', ['email' => 'carla.nogueira@distribuidora-exemplo.com.br', 'password' => SENHA_PADRAO_DA_FABRICA])
        ->assertStatus(429);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
        ->post('/login', ['email' => 'carla.nogueira@distribuidora-exemplo.com.br', 'password' => SENHA_PADRAO_DA_FABRICA])
        ->assertRedirect('/contextos');

    $this->assertAuthenticated();
});

it('impede que usuário com e-mail não verificado acesse a seleção de contexto', function (): void {
    $user = User::factory()->unverified()->create()->fresh();

    $this->actingAs($user)->get('/contextos')->assertRedirect(route('verification.notice'));
    // A segurança da conta fica fora do grupo 'verified' (correção de e-mail digitado
    // errado), mas exige confirmação recente de senha.
    $this->get('/perfil/seguranca')->assertRedirect(route('password.confirm'));
    $this->get('/email/verify')->assertOk();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('libera a seleção de contexto após a verificação por link assinado', function (): void {
    $user = User::factory()->unverified()->create()->fresh();

    $adulterado = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1('outro.endereco@distribuidora-exemplo.com.br'),
    ]);
    $this->actingAs($user)->get($adulterado)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();

    $outroUsuario = User::factory()->unverified()->create()->fresh();
    $linkDoOutro = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $outroUsuario->id,
        'hash' => sha1($outroUsuario->email),
    ]);
    $this->get($linkDoOutro)->assertForbidden();
    expect($outroUsuario->fresh()->hasVerifiedEmail())->toBeFalse();

    $vencido = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);
    $this->get($vencido)->assertForbidden();

    $semAssinatura = route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->get($semAssinatura)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();

    $valido = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);
    $this->get($valido)->assertRedirect('/contextos?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->actingAs($user->fresh())->get('/contextos')->assertOk();
});

it('redireciona visitantes não autenticados para o login', function (string $uri): void {
    $this->get($uri)->assertRedirect('/login');
    $this->assertGuest();
})->with(['/contextos', '/perfil/seguranca', '/painel', '/onboarding', '/portal', '/plataforma/planos']);

it('encerra a sessão no logout e volta a exigir login', function (): void {
    $user = User::factory()->create()->fresh();

    $this->actingAs($user)->post('/logout')->assertRedirect();
    $this->assertGuest();

    $this->get('/contextos')->assertRedirect('/login');
});

it('desconecta conta desativada na requisição seguinte ao login', function (): void {
    $user = User::factory()->create(['disabled_at' => now()]);

    $this->post('/login', ['email' => $user->email, 'password' => SENHA_PADRAO_DA_FABRICA]);

    $this->get('/contextos')->assertRedirect('/login');
    $this->assertGuest();
});

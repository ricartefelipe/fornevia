<?php

declare(strict_types=1);

use App\Models\Identity\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Testing\TestResponse;
use Tests\Support\Builders\TenantScenario;

/**
 * O framework dispensa a verificação de CSRF durante testes automatizados.
 * Este binding reativa a verificação real para os cenários deste arquivo.
 */
function ativarVerificacaoCsrf(): void
{
    app()->bind(PreventRequestForgery::class, static fn (Application $app): PreventRequestForgery => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
}

/**
 * Valida cabeçalhos de segurança, a CSP com nonce e o uso do nonce em todos
 * os scripts e estilos do documento. Retorna o nonce da resposta.
 *
 * @param  TestResponse<Symfony\Component\HttpFoundation\Response>  $response
 */
function assertPaginaProtegidaPorCsp(TestResponse $response): string
{
    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'")
        ->toContain("frame-ancestors 'none'")
        ->not->toContain('unsafe-eval')
        ->not->toContain('unsafe-inline')
        ->not->toContain('*');

    expect(preg_match("/script-src 'self' 'nonce-([A-Za-z0-9+\\/=_-]{16,})'(;|$)/", $csp, $script))->toBe(1);
    $nonce = $script[1];
    expect($csp)->toContain("style-src 'self' 'nonce-{$nonce}'");

    $html = (string) $response->getContent();

    preg_match_all('/<(script|style)\b[^>]*>/i', $html, $tags);
    expect($tags[0])->not->toBeEmpty();

    foreach ($tags[0] as $tag) {
        expect($tag)->toContain('nonce="'.$nonce.'"');
    }

    // A CSP bloquearia atributos style e manipuladores de evento inline.
    expect($html)->not->toMatch('/<[^>]+\sstyle\s*=/i')
        ->not->toMatch('/<[^>]+\son[a-z]+\s*=/i')
        ->not->toMatch('/href\s*=\s*["\']\s*javascript:/i');

    return $nonce;
}

it('recusa requisições POST sem token CSRF com status 419', function (): void {
    ativarVerificacaoCsrf();
    $user = User::factory()->create(['email' => 'paula.rezende@atacado-exemplo.com.br']);

    $this->post('/login', ['email' => $user->email, 'password' => 'senha-segura-123'])->assertStatus(419);
    $this->assertGuest();

    $this->withSession(['_token' => 'token-da-sessao-legitima'])
        ->post('/login', ['_token' => 'token-forjado-pelo-atacante', 'email' => $user->email, 'password' => 'senha-segura-123'])
        ->assertStatus(419);
    $this->assertGuest();

    // Requisição originada de outro site também é recusada sem o token.
    $this->withHeader('Sec-Fetch-Site', 'cross-site')
        ->post('/login', ['email' => $user->email, 'password' => 'senha-segura-123'])
        ->assertStatus(419);
    $this->assertGuest();

    $this->post('/register', [
        'name' => 'Cadastro Forjado',
        'email' => 'cadastro.forjado@atacado-exemplo.com.br',
        'password' => 'Distribuicao2026Segura',
        'password_confirmation' => 'Distribuicao2026Segura',
        'terms' => '1',
    ])->assertStatus(419);
    expect(User::query()->where('email', 'cadastro.forjado@atacado-exemplo.com.br')->exists())->toBeFalse();
});

it('recusa alterações autenticadas sem token CSRF', function (): void {
    ativarVerificacaoCsrf();
    $user = User::factory()->create(['name' => 'Paula Rezende'])->fresh();

    $this->actingAs($user)
        ->put('/user/profile-information', ['name' => 'Nome Alterado Por Terceiro', 'email' => $user->email])
        ->assertStatus(419);
    $this->put('/user/password', [
        'current_password' => 'senha-segura-123',
        'password' => 'SenhaTrocadaPorTerceiro2026',
        'password_confirmation' => 'SenhaTrocadaPorTerceiro2026',
    ])->assertStatus(419);
    $this->post('/logout')->assertStatus(419);

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->name)->toBe('Paula Rezende');
});

it('aceita a requisição quando o token CSRF confere com a sessão', function (): void {
    ativarVerificacaoCsrf();
    $user = User::factory()->create();

    $this->withSession(['_token' => 'token-da-sessao-legitima'])
        ->post('/login', ['_token' => 'token-da-sessao-legitima', 'email' => $user->email, 'password' => 'senha-segura-123'])
        ->assertRedirect('/contextos');

    $this->assertAuthenticatedAs($user);
});

it('aplica cabeçalhos de segurança e CSP com nonce nas páginas públicas de autenticação', function (string $uri): void {
    $primeiro = assertPaginaProtegidaPorCsp($this->get($uri));
    $segundo = assertPaginaProtegidaPorCsp($this->get($uri));

    // O nonce é renovado a cada resposta.
    expect($segundo)->not->toBe($primeiro);
})->with(['/login', '/register', '/forgot-password']);

it('aplica CSP com nonce na seleção de contexto e na segurança da conta', function (): void {
    $tenant = TenantScenario::create();
    $owner = $tenant->owner->fresh();

    assertPaginaProtegidaPorCsp($this->actingAs($owner)->get('/contextos?escolher=1'));

    assertPaginaProtegidaPorCsp(
        $this->actingAs($owner)
            ->withSession([...TenantScenario::sessionFor($tenant->ownerMembership), 'auth.password_confirmed_at' => time()])
            ->get('/perfil/seguranca'),
    );
});

it('aplica CSP com nonce na página de ativação do segundo fator com QR Code', function (): void {
    $user = User::factory()->create()->fresh();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/user/two-factor-authentication');

    $response = $this->get('/perfil/seguranca');

    assertPaginaProtegidaPorCsp($response);
    expect((string) $response->getContent())->toContain('<svg');
});

it('aplica CSP com nonce nas páginas com componentes Livewire', function (): void {
    $tenant = TenantScenario::create();
    $owner = $tenant->owner;
    $owner->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['codigo-recuperacao-1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $response = $this->actingAs($owner->fresh())
        ->withSession(TenantScenario::sessionFor($tenant->ownerMembership))
        ->get('/painel');

    $nonce = assertPaginaProtegidaPorCsp($response);
    expect((string) $response->getContent())->toMatch('/<script[^>]+nonce="'.preg_quote($nonce, '/').'"[^>]*data-csrf=/');
});

it('envia HSTS apenas em conexões seguras', function (): void {
    $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');

    $this->get('https://localhost/login')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('emite o cookie de sessão com HttpOnly e SameSite=lax', function (): void {
    $response = $this->get('/login')->assertOk();

    $cookie = $response->getCookie((string) config('session.cookie'), decrypt: false);

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getPath())->toBe('/');

    $xsrf = $response->getCookie('XSRF-TOKEN', decrypt: false);
    expect($xsrf)->not->toBeNull()
        ->and($xsrf->getSameSite())->toBe('lax');
});

it('mantém o cookie de sessão protegido após o login', function (): void {
    $user = User::factory()->create();

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'senha-segura-123'])->assertRedirect('/contextos');

    $cookie = $response->getCookie((string) config('session.cookie'), decrypt: false);
    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
});

it('emite o cookie de lembrar acesso com HttpOnly e SameSite=lax', function (): void {
    $user = User::factory()->create();

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'senha-segura-123', 'remember' => 'on'])
        ->assertRedirect('/contextos');

    $cookie = $response->getCookie(auth()->guard('web')->getRecallerName(), decrypt: false);

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($user->fresh()->getRememberToken())->not->toBeNull();
});

it('renova o identificador de sessão no login para impedir fixação de sessão', function (): void {
    $user = User::factory()->create();
    $nome = (string) config('session.cookie');

    $anterior = $this->get('/login')->getCookie($nome)?->getValue();
    expect($anterior)->toBeString()->not->toBe('');

    $novo = $this->withCookie($nome, (string) $anterior)
        ->post('/login', ['email' => $user->email, 'password' => 'senha-segura-123'])
        ->assertRedirect('/contextos')
        ->getCookie($nome)?->getValue();

    expect($novo)->toBeString()->not->toBe($anterior);
    $this->assertAuthenticatedAs($user);
});

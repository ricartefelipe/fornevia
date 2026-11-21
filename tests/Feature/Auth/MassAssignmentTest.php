<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Models\Identity\Invitation;
use App\Models\Identity\Membership;
use App\Models\Identity\Role;
use App\Models\Identity\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Builders\TenantScenario;

/**
 * Executa o callback com o comportamento de produção para atributos não
 * atribuíveis (descarte silencioso, sem exceção).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function comDescarteSilenciosoDeAtributos(Closure $callback): mixed
{
    Model::preventSilentlyDiscardingAttributes(false);

    try {
        return $callback();
    } finally {
        Model::preventSilentlyDiscardingAttributes(true);
    }
}

it('aceita em massa apenas nome, e-mail e senha no usuário', function (): void {
    $user = (new User)->fill([
        'name' => 'Otávio Siqueira',
        'email' => 'otavio.siqueira@atacado-exemplo.com.br',
        'password' => 'Distribuicao2026Segura',
    ]);

    expect($user->name)->toBe('Otávio Siqueira')
        ->and($user->email)->toBe('otavio.siqueira@atacado-exemplo.com.br')
        ->and($user->getFillable())->toEqualCanonicalizing(['name', 'email', 'password']);
});

it('recusa atributos privilegiados do usuário na atribuição em massa', function (string $atributo, mixed $valor): void {
    expect((new User)->isFillable($atributo))->toBeFalse();

    expect(fn () => (new User)->fill(['name' => 'Otávio Siqueira', $atributo => $valor]))
        ->toThrow(MassAssignmentException::class);

    $descartado = comDescarteSilenciosoDeAtributos(
        fn (): User => (new User)->fill(['name' => 'Otávio Siqueira', $atributo => $valor]),
    );
    expect($descartado->getAttributes())->toBe(['name' => 'Otávio Siqueira']);
})->with([
    'administrador da plataforma' => ['is_platform_admin', true],
    'segredo do segundo fator' => ['two_factor_secret', 'JBSWY3DPEHPK3PXP'],
    'códigos de recuperação' => ['two_factor_recovery_codes', '["codigo-recuperacao-1"]'],
    'confirmação do segundo fator' => ['two_factor_confirmed_at', '2026-11-17 10:00:00'],
    'verificação de e-mail' => ['email_verified_at', '2026-11-17 10:00:00'],
    'desativação da conta' => ['disabled_at', null],
    'último contexto' => ['last_membership_id', '0195a3c2-0000-7000-8000-000000000001'],
]);

it('recusa qualquer atribuição em massa em associações e convites', function (string $modelo, array $atributos): void {
    expect(fn () => (new $modelo)->fill($atributos))->toThrow(MassAssignmentException::class);

    // Mesmo com o descarte silencioso de produção, o modelo totalmente protegido falha.
    expect(fn () => comDescarteSilenciosoDeAtributos(fn () => (new $modelo)->fill($atributos)))
        ->toThrow(MassAssignmentException::class);
})->with([
    'associação: papel' => [Membership::class, ['role_id' => '0195a3c2-0000-7000-8000-000000000002']],
    'associação: status' => [Membership::class, ['status' => 'active']],
    'associação: tenant' => [Membership::class, ['tenant_id' => '0195a3c2-0000-7000-8000-000000000003']],
    'convite: aceite' => [Invitation::class, ['accepted_at' => '2026-11-17 10:00:00']],
    'convite: token' => [Invitation::class, ['token_hash' => str_repeat('a', 64)]],
    'convite: e-mail' => [Invitation::class, ['email' => 'convidado@atacado-exemplo.com.br']],
]);

it('impede a criação de associação por atribuição em massa', function (): void {
    $tenant = TenantScenario::create();
    $user = User::factory()->create();
    $roleId = $tenant->role(SystemRole::Owner)->id;

    expect(fn () => $tenant->system(fn () => Membership::query()->create([
        'user_id' => $user->id,
        'kind' => 'staff',
        'role_id' => $roleId,
        'status' => 'active',
    ])))->toThrow(MassAssignmentException::class);

    expect($tenant->system(fn (): int => Membership::query()->where('user_id', $user->id)->count()))->toBe(0);
});

it('aceita apenas o nome do papel na atribuição em massa', function (): void {
    $role = (new Role)->fill(['name' => 'Compras corporativas']);
    expect($role->name)->toBe('Compras corporativas')
        ->and($role->getFillable())->toBe(['name']);
});

it('recusa atributos de autorização do papel na atribuição em massa', function (string $atributo, mixed $valor): void {
    expect(fn () => (new Role)->fill(['name' => 'Compras corporativas', $atributo => $valor]))
        ->toThrow(MassAssignmentException::class);

    $descartado = comDescarteSilenciosoDeAtributos(
        fn (): Role => (new Role)->fill(['name' => 'Compras corporativas', $atributo => $valor]),
    );
    expect($descartado->getAttributes())->toBe(['name' => 'Compras corporativas']);
})->with([
    'permissões' => ['permissions', ['members.manage', 'finance.manage']],
    'limite de desconto' => ['max_discount_bps', 10_000],
    'chave do papel' => ['key', 'owner'],
    'papel de sistema' => ['is_system', true],
    'tipo de associação' => ['kind', 'staff'],
    'tenant' => ['tenant_id', '0195a3c2-0000-7000-8000-000000000004'],
]);

it('ignora atributos privilegiados enviados na atualização de perfil', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create([
        'name' => 'Otávio Siqueira',
        'email' => 'otavio.siqueira@atacado-exemplo.com.br',
    ])->fresh();

    $this->actingAs($user)
        ->from('/perfil/seguranca')
        ->put('/user/profile-information', [
            'name' => 'Otávio Siqueira Neto',
            'email' => 'otavio.siqueira@atacado-exemplo.com.br',
            'is_platform_admin' => '1',
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => '2026-11-17 10:00:00',
            'email_verified_at' => '2026-11-17 10:00:00',
            'disabled_at' => null,
            'password' => 'SenhaSemConfirmacao2026',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/perfil/seguranca');

    $atualizado = $user->fresh();
    expect($atualizado->name)->toBe('Otávio Siqueira Neto')
        ->and($atualizado->is_platform_admin)->toBeFalse()
        ->and($atualizado->two_factor_secret)->toBeNull()
        ->and($atualizado->two_factor_confirmed_at)->toBeNull()
        ->and($atualizado->email_verified_at)->toBeNull()
        ->and(password_verify('senha-segura-123', $atualizado->password))->toBeTrue();
});

it('ignora atributos privilegiados enviados no cadastro', function (): void {
    Notification::fake();

    $this->post('/register', [
        'name' => 'Sílvia Toledo',
        'email' => 'silvia.toledo@atacado-exemplo.com.br',
        'password' => 'Distribuicao2026Segura',
        'password_confirmation' => 'Distribuicao2026Segura',
        'terms' => '1',
        'is_platform_admin' => '1',
        'email_verified_at' => '2026-11-17 10:00:00',
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => '2026-11-17 10:00:00',
        'last_membership_id' => '0195a3c2-0000-7000-8000-000000000005',
    ])->assertSessionHasNoErrors()->assertRedirect('/contextos');

    $user = User::query()->where('email', 'silvia.toledo@atacado-exemplo.com.br')->sole();
    expect($user->is_platform_admin)->toBeFalse()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->last_membership_id)->toBeNull();
});

it('ignora atributos privilegiados enviados na troca de senha', function (): void {
    $user = User::factory()->create()->fresh();

    $this->actingAs($user)
        ->from('/perfil/seguranca')
        ->put('/user/password', [
            'current_password' => 'senha-segura-123',
            'password' => 'NovaSenhaComercial2026',
            'password_confirmation' => 'NovaSenhaComercial2026',
            'is_platform_admin' => '1',
            'two_factor_confirmed_at' => '2026-11-17 10:00:00',
        ])
        ->assertSessionHasNoErrors();

    $atualizado = $user->fresh();
    expect(password_verify('NovaSenhaComercial2026', $atualizado->password))->toBeTrue()
        ->and($atualizado->is_platform_admin)->toBeFalse()
        ->and($atualizado->two_factor_confirmed_at)->toBeNull();
});

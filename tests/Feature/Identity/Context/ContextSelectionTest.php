<?php

declare(strict_types=1);

use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\OrganizationStatus;
use App\Enums\Identity\SystemRole;
use App\Http\Middleware\EstablishRequestContext;
use App\Models\Identity\Membership;
use App\Models\Identity\Organization;
use App\Models\Identity\User;
use Illuminate\Support\Str;
use Tests\Support\Builders\TenantScenario;

/*
| Seleção e troca de contexto (ContextController + SelectContext).
| O identificador enviado pelo navegador é entrada não confiável: somente
| associações ativas do próprio usuário, em organização com acesso, podem
| ser ativadas, e a ativação sempre gera um novo identificador de sessão.
*/

beforeEach(function (): void {
    $this->withoutVite();
    $this->sessionCookie = (string) config('session.cookie');
});

it('lista somente as associações ativas do próprio usuário em vários tenants, com rótulos corretos', function (): void {
    $user = User::factory()->create()->refresh();

    $serraAzul = TenantScenario::create(name: 'Atacadista Serra Azul');
    $valeVerde = TenantScenario::create(name: 'Insumos Vale Verde');
    $horizonte = TenantScenario::create(name: 'Distribuidora Horizonte');
    $ponteAlta = TenantScenario::create(name: 'Comercial Ponte Alta');

    $staff = $serraAzul->addStaff(SystemRole::SalesRep, $user);
    $mercado = $valeVerde->addBuyerCompany(['trade_name' => 'Mercado Bom Preço']);
    $buyer = $valeVerde->addBuyerUser($mercado, SystemRole::Approver, $user);

    $revoked = $horizonte->addStaff(SystemRole::Viewer, $user);
    $horizonte->system(fn () => Membership::query()->whereKey($revoked->id)->update([
        'status' => MembershipStatus::Revoked->value,
        'revoked_at' => now(),
        'revoke_reason' => 'Encerramento do contrato de representação',
    ]));

    // Associações de outras pessoas, inclusive no mesmo tenant do usuário.
    $colleague = $serraAzul->addStaff(SystemRole::Finance);
    $foreign = $ponteAlta->addStaff(SystemRole::Admin);

    $response = $this->actingAs($user)->get('/contextos');

    $response->assertOk()
        ->assertSeeText('Atacadista Serra Azul · equipe')
        ->assertSeeText(SystemRole::SalesRep->label())
        ->assertSeeText('Mercado Bom Preço · compras em Insumos Vale Verde')
        ->assertSeeText(SystemRole::Approver->label())
        ->assertSee($staff->id)
        ->assertSee($buyer->id)
        ->assertDontSeeText('Distribuidora Horizonte')
        ->assertDontSeeText('Comercial Ponte Alta')
        ->assertDontSeeText(SystemRole::Finance->label())
        ->assertDontSee($revoked->id)
        ->assertDontSee($colleague->id)
        ->assertDontSee($foreign->id)
        ->assertDontSeeText('Indisponível');

    expect(session()->has(EstablishRequestContext::SESSION_KEY))->toBeFalse();
});

it('retoma automaticamente o único contexto disponível e registra o último uso', function (): void {
    $tenant = TenantScenario::create(name: 'Distribuidora Rio Claro');
    $company = $tenant->addBuyerCompany(['trade_name' => 'Padaria Trigo Dourado']);
    $buyer = $tenant->addBuyerUser($company, SystemRole::Purchaser);
    $user = User::query()->findOrFail($buyer->user_id);

    $this->actingAs($user)->get('/contextos')->assertRedirect(route('portal.home'));

    expect(session(EstablishRequestContext::SESSION_KEY))->toBe(EstablishRequestContext::sessionPayload($buyer))
        ->and($user->fresh()?->last_membership_id)->toBe($buyer->id)
        ->and($tenant->system(fn () => Membership::query()->findOrFail($buyer->id)->last_used_at))->not->toBeNull();
});

it('ignora organizações sem acesso na retomada automática e as exibe como indisponíveis na lista', function (): void {
    $user = User::factory()->create()->refresh();
    $ativa = TenantScenario::create(name: 'Distribuidora Pedra Branca');
    $suspensa = TenantScenario::create(name: 'Atacado Lagoa Funda');

    $active = $ativa->addStaff(SystemRole::Viewer, $user);
    $blocked = $suspensa->addStaff(SystemRole::Viewer, $user);
    Organization::query()->whereKey($suspensa->organization->id)->update(['status' => OrganizationStatus::Suspended->value]);

    $this->actingAs($user)->get('/contextos')->assertRedirect(route('dashboard'));
    expect(session(EstablishRequestContext::SESSION_KEY))->toBe(EstablishRequestContext::sessionPayload($active));

    session()->forget(EstablishRequestContext::SESSION_KEY);
    app()->forgetScopedInstances();

    $this->actingAs($user)->get('/contextos?escolher=1')
        ->assertOk()
        ->assertSeeText('Distribuidora Pedra Branca · equipe')
        ->assertSeeText('Atacado Lagoa Funda · equipe')
        ->assertSeeText('Indisponível')
        ->assertSee($blocked->id);
});

it('exibe a lista de contextos com ?escolher=1 mesmo havendo um único contexto', function (): void {
    $tenant = TenantScenario::create(name: 'Distribuidora Rio Claro');

    $this->actingAs($tenant->owner->refresh())->get('/contextos?escolher=1')
        ->assertOk()
        ->assertSeeText('Distribuidora Rio Claro · equipe')
        ->assertSeeText(SystemRole::Owner->label())
        ->assertSee($tenant->ownerMembership->id);

    expect(session()->has(EstablishRequestContext::SESSION_KEY))->toBeFalse()
        ->and($tenant->owner->fresh()?->last_membership_id)->toBeNull();
});

it('recusa a ativação de contexto indisponível sem gravar a sessão', function (string $case): void {
    $user = User::factory()->create()->refresh();
    $own = TenantScenario::create(name: 'Distribuidora Pedra Branca');
    $own->addStaff(SystemRole::Viewer, $user);
    $target = TenantScenario::create(name: 'Atacado Lagoa Funda');

    $payload = match ($case) {
        'associação de outro usuário' => ['membership_id' => $target->addStaff(SystemRole::Admin)->id],
        'associação revogada' => (static function () use ($target, $user): array {
            $membership = $target->addStaff(SystemRole::SalesRep, $user);
            $target->system(fn () => Membership::query()->whereKey($membership->id)->update([
                'status' => MembershipStatus::Revoked->value,
                'revoked_at' => now(),
                'revoke_reason' => 'Desligamento do colaborador',
            ]));

            return ['membership_id' => $membership->id];
        })(),
        'organização suspensa' => (static function () use ($target, $user): array {
            $membership = $target->addStaff(SystemRole::SalesRep, $user);
            Organization::query()->whereKey($target->organization->id)->update(['status' => OrganizationStatus::Suspended->value]);

            return ['membership_id' => $membership->id];
        })(),
        'organização cancelada' => (static function () use ($target, $user): array {
            $membership = $target->addStaff(SystemRole::SalesRep, $user);
            Organization::query()->whereKey($target->organization->id)->update(['status' => OrganizationStatus::Cancelled->value]);

            return ['membership_id' => $membership->id];
        })(),
        'identificador inexistente' => ['membership_id' => (string) Str::uuid7()],
        'identificador não UUID' => ['membership_id' => "1' or '1'='1"],
        'identificador ausente' => [],
        'identificador enviado como lista' => ['membership_id' => [$target->addStaff(SystemRole::SalesRep, $user)->id]],
    };

    $this->actingAs($user)->post('/contextos', $payload)
        ->assertRedirect(route('contexts.index', ['escolher' => 1]))
        ->assertSessionHas('error', 'Contexto indisponível.');

    expect(session()->has(EstablishRequestContext::SESSION_KEY))->toBeFalse()
        ->and($user->fresh()?->last_membership_id)->toBeNull();
})->with([
    'associação de outro usuário',
    'associação revogada',
    'organização suspensa',
    'organização cancelada',
    'identificador inexistente',
    'identificador não UUID',
    'identificador ausente',
    'identificador enviado como lista',
]);

it('regenera o identificador da sessão ao ativar um contexto, e só nesse momento', function (): void {
    $tenant = TenantScenario::create();
    $owner = $tenant->owner->refresh();
    $original = Str::random(40);

    // Requisição sem troca de contexto preserva o identificador.
    $listing = $this->actingAs($owner)
        ->withCookie($this->sessionCookie, $original)
        ->get('/contextos?escolher=1');

    $listing->assertOk();
    expect($listing->getCookie($this->sessionCookie)?->getValue())->toBe($original);

    app()->forgetScopedInstances();

    $activation = $this->actingAs($owner)
        ->withCookie($this->sessionCookie, $original)
        ->post('/contextos', ['membership_id' => $tenant->ownerMembership->id]);

    $activation->assertRedirect(route('dashboard'));
    $renewed = $activation->getCookie($this->sessionCookie)?->getValue();

    expect($renewed)->toBeString()->not->toBe($original)
        ->and(session()->getId())->toBe($renewed)
        ->and(session(EstablishRequestContext::SESSION_KEY))->toBe(EstablishRequestContext::sessionPayload($tenant->ownerMembership))
        ->and($owner->fresh()?->last_membership_id)->toBe($tenant->ownerMembership->id);
});

it('abre a escolha de contexto a partir de um contexto já ativo', function (): void {
    $user = User::factory()->create()->refresh();
    $pedraBranca = TenantScenario::create(name: 'Distribuidora Pedra Branca');
    $lagoaFunda = TenantScenario::create(name: 'Atacado Lagoa Funda');
    $staff = $pedraBranca->addStaff(SystemRole::Viewer, $user);
    $lagoaFunda->addStaff(SystemRole::SalesRep, $user);

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($staff))
        ->get('/contextos?escolher=1')
        ->assertOk()
        ->assertSeeText('Distribuidora Pedra Branca · equipe')
        ->assertSeeText('Atacado Lagoa Funda · equipe');
});

it('troca de um contexto ativo para outro tenant regenerando a sessão e substituindo o contexto', function (): void {
    $user = User::factory()->create()->refresh();
    $pedraBranca = TenantScenario::create(name: 'Distribuidora Pedra Branca');
    $lagoaFunda = TenantScenario::create(name: 'Atacado Lagoa Funda');
    $staff = $pedraBranca->addStaff(SystemRole::Viewer, $user);
    $company = $lagoaFunda->addBuyerCompany(['trade_name' => 'Restaurante Sabor da Terra']);
    $buyer = $lagoaFunda->addBuyerUser($company, SystemRole::Purchaser, $user);
    $original = Str::random(40);

    $response = $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($staff))
        ->withCookie($this->sessionCookie, $original)
        ->post('/contextos', ['membership_id' => $buyer->id]);

    $response->assertRedirect(route('portal.home'));

    expect($response->getCookie($this->sessionCookie)?->getValue())->toBeString()->not->toBe($original)
        ->and(session(EstablishRequestContext::SESSION_KEY))->toBe(EstablishRequestContext::sessionPayload($buyer))
        ->and($user->fresh()?->last_membership_id)->toBe($buyer->id);
});

it('mantém o contexto atual quando a troca solicitada é recusada', function (): void {
    $user = User::factory()->create()->refresh();
    $tenant = TenantScenario::create();
    $staff = $tenant->addStaff(SystemRole::Viewer, $user);
    $foreign = $tenant->addStaff(SystemRole::Admin);

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($staff))
        ->post('/contextos', ['membership_id' => $foreign->id])
        ->assertRedirect(route('contexts.index', ['escolher' => 1]))
        ->assertSessionHas('error', 'Contexto indisponível.');

    expect(session(EstablishRequestContext::SESSION_KEY))->toBe(EstablishRequestContext::sessionPayload($staff));
});

it('sai do contexto atual mantendo o usuário autenticado', function (): void {
    $tenant = TenantScenario::create();

    $this->actingAs($tenant->owner->refresh())
        ->withSession(TenantScenario::sessionFor($tenant->ownerMembership))
        ->post('/contextos/sair')
        ->assertRedirect(route('contexts.index', ['escolher' => 1]));

    expect(session()->has(EstablishRequestContext::SESSION_KEY))->toBeFalse();
    $this->assertAuthenticatedAs($tenant->owner);
});

it('exige autenticação para listar, ativar e sair de contextos', function (): void {
    $tenant = TenantScenario::create();

    $this->get('/contextos')->assertRedirect(route('login'));
    $this->post('/contextos', ['membership_id' => $tenant->ownerMembership->id])->assertRedirect(route('login'));
    $this->post('/contextos/sair')->assertRedirect(route('login'));

    expect(session()->has(EstablishRequestContext::SESSION_KEY))->toBeFalse();
});

<?php

declare(strict_types=1);

use App\Actions\Platform\ChangeOrganizationStatus;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\OrganizationStatus;
use App\Enums\Identity\SystemRole;
use App\Http\Middleware\EstablishRequestContext;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Illuminate\Support\Str;
use Tests\Support\Builders\TenantScenario;

/*
| EstablishRequestContext: o contexto guardado na sessão é revalidado no
| banco a cada requisição. Qualquer divergência entre a sessão e a
| associação real (tenant, empresa compradora, tipo, dono, situação)
| descarta o contexto e devolve o usuário à escolha de contexto.
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->user = User::factory()->create()->refresh();
    $this->tenant = TenantScenario::create(name: 'Distribuidora Pedra Branca');
    $this->staff = $this->tenant->addStaff(SystemRole::Viewer, $this->user);
});

it('estabelece o contexto de uma sessão íntegra e renderiza a área da equipe', function (): void {
    $this->actingAs($this->user)
        ->withSession(TenantScenario::sessionFor($this->staff))
        ->get('/painel')
        ->assertOk()
        ->assertSeeText('Distribuidora Pedra Branca')
        ->assertSeeText(SystemRole::Viewer->label());

    expect(session(EstablishRequestContext::SESSION_KEY))->toBe(EstablishRequestContext::sessionPayload($this->staff));
});

it('descarta a sessão adulterada que diverge da associação e redireciona para a escolha de contexto', function (string $case): void {
    $other = TenantScenario::create(name: 'Atacado Lagoa Funda');
    $other->addStaff(SystemRole::Viewer, $this->user);
    $ownCompany = $this->tenant->addBuyerCompany(['trade_name' => 'Mercado Bom Preço']);
    $otherCompany = $this->tenant->addBuyerCompany(['trade_name' => 'Restaurante Sabor da Terra']);
    $buyer = $this->tenant->addBuyerUser($ownCompany, SystemRole::Purchaser, $this->user);

    $payload = match ($case) {
        'tenant de outra organização do usuário' => [
            ...EstablishRequestContext::sessionPayload($this->staff),
            'tenant_id' => $other->organization->id,
        ],
        'empresa compradora de outro cliente' => [
            ...EstablishRequestContext::sessionPayload($buyer),
            'buyer_company_id' => $otherCompany->id,
        ],
        'comprador se apresentando como equipe' => [
            ...EstablishRequestContext::sessionPayload($buyer),
            'kind' => 'staff',
            'buyer_company_id' => null,
        ],
        'equipe se apresentando como comprador' => [
            ...EstablishRequestContext::sessionPayload($this->staff),
            'kind' => 'buyer',
            'buyer_company_id' => $ownCompany->id,
        ],
        'associação de outro usuário' => EstablishRequestContext::sessionPayload($this->tenant->ownerMembership),
        'associação inexistente' => [
            ...EstablishRequestContext::sessionPayload($this->staff),
            'membership_id' => (string) Str::uuid7(),
        ],
    };

    $this->actingAs($this->user)
        ->withSession([EstablishRequestContext::SESSION_KEY => $payload])
        ->get('/painel')
        ->assertRedirect(route('contexts.index'))
        ->assertSessionHas('status', 'O acesso a este contexto não está mais disponível.');

    expect(session()->has(EstablishRequestContext::SESSION_KEY))->toBeFalse();
    $this->assertAuthenticatedAs($this->user);
})->with([
    'tenant de outra organização do usuário',
    'empresa compradora de outro cliente',
    'comprador se apresentando como equipe',
    'equipe se apresentando como comprador',
    'associação de outro usuário',
    'associação inexistente',
]);

it('descarta a sessão com dados de contexto malformados', function (string $case): void {
    $payload = match ($case) {
        'identificador de associação inválido' => [
            ...EstablishRequestContext::sessionPayload($this->staff),
            'membership_id' => "1' or '1'='1",
        ],
        'tenant inválido' => [
            ...EstablishRequestContext::sessionPayload($this->staff),
            'tenant_id' => 'todos',
        ],
        'comprador sem empresa compradora' => [
            ...EstablishRequestContext::sessionPayload($this->staff),
            'kind' => 'buyer',
            'buyer_company_id' => null,
        ],
        'tipo de contexto interno' => [
            ...EstablishRequestContext::sessionPayload($this->staff),
            'kind' => 'system',
        ],
        'tipo de contexto desconhecido' => [
            ...EstablishRequestContext::sessionPayload($this->staff),
            'kind' => 'superusuario',
        ],
    };

    $this->actingAs($this->user)
        ->withSession([EstablishRequestContext::SESSION_KEY => $payload])
        ->get('/painel')
        ->assertRedirect(route('contexts.index'));

    expect(session()->has(EstablishRequestContext::SESSION_KEY))->toBeFalse();
})->with([
    'identificador de associação inválido',
    'tenant inválido',
    'comprador sem empresa compradora',
    'tipo de contexto interno',
    'tipo de contexto desconhecido',
]);

it('aplica a revogação da associação já na requisição seguinte', function (): void {
    $session = TenantScenario::sessionFor($this->staff);

    $this->actingAs($this->user)->withSession($session)->get('/painel')->assertOk();

    $this->tenant->system(fn () => Membership::query()->whereKey($this->staff->id)->update([
        'status' => MembershipStatus::Revoked->value,
        'revoked_at' => now(),
        'revoked_by_user_id' => $this->tenant->owner->id,
        'revoke_reason' => 'Desligamento do colaborador',
    ]));
    app()->forgetScopedInstances();

    $this->actingAs($this->user)->get('/painel')
        ->assertRedirect(route('contexts.index'))
        ->assertSessionHas('status', 'O acesso a este contexto não está mais disponível.');

    expect(session()->has(EstablishRequestContext::SESSION_KEY))->toBeFalse();
});

it('bloqueia os contextos de organização sem acesso à plataforma', function (OrganizationStatus $status): void {
    $admin = User::factory()->platformAdmin()->create();
    app(ChangeOrganizationStatus::class)->handle($admin, $this->tenant->organization, $status, 'Inadimplência superior a 60 dias');

    $this->actingAs($this->user)
        ->withSession(TenantScenario::sessionFor($this->staff))
        ->get('/painel')
        ->assertRedirect(route('contexts.index'))
        ->assertSessionHas('status', 'O acesso a este contexto não está mais disponível.');

    expect(session()->has(EstablishRequestContext::SESSION_KEY))->toBeFalse();
})->with([
    'suspensa' => [OrganizationStatus::Suspended],
    'cancelada' => [OrganizationStatus::Cancelled],
]);

it('mantém o acesso de organização com pagamento pendente', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    app(ChangeOrganizationStatus::class)->handle($admin, $this->tenant->organization, OrganizationStatus::PastDue, 'Fatura de novembro em aberto');

    $this->actingAs($this->user)
        ->withSession(TenantScenario::sessionFor($this->staff))
        ->get('/painel')
        ->assertOk();

    expect(session(EstablishRequestContext::SESSION_KEY))->toBe(EstablishRequestContext::sessionPayload($this->staff));
});

it('encerra a sessão do usuário desativado em qualquer rota autenticada', function (string $uri): void {
    $this->user->forceFill(['disabled_at' => now()])->save();

    $this->actingAs($this->user)
        ->withSession(TenantScenario::sessionFor($this->staff))
        ->get($uri)
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Sua conta está desativada.');

    $this->assertGuest();
    expect(session()->has(EstablishRequestContext::SESSION_KEY))->toBeFalse();
})->with([
    'painel da equipe' => ['/painel'],
    'escolha de contexto' => ['/contextos'],
]);

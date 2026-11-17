<?php

declare(strict_types=1);

use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\OrganizationStatus;
use App\Enums\Identity\SystemRole;
use App\Http\Middleware\EstablishRequestContext;
use App\Models\Identity\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Integration\Identity\Invitations\InvitationFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = TenantScenario::create();
});

it('mostra ao convidado não autenticado as opções de entrar ou criar conta e guarda o retorno ao convite', function (): void {
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'patricia.souza@example.com', SystemRole::Viewer);
    $url = route('invitations.show', Fixtures::routeParameters($this->tenant, $issued->plainToken));

    $this->get($url)
        ->assertOk()
        ->assertSee('Entre ou crie uma conta com o e-mail que recebeu o convite')
        ->assertSee(route('login'), false)
        ->assertSee(route('register'), false)
        ->assertDontSee('Aceitar convite')
        ->assertSessionHas('url.intended', $url);

    // Após o login, o convidado volta ao link do convite.
    $invitee = User::factory()->create(['email' => 'patricia.souza@example.com'])->fresh();

    $this->post('/login', ['email' => $invitee->email, 'password' => 'senha-segura-123'])
        ->assertRedirect($url);
    $this->assertAuthenticatedAs($invitee);
});

it('mostra ao usuário autenticado o formulário de aceite sem alterar o retorno pós-login', function (): void {
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'patricia.souza@example.com', SystemRole::Viewer);
    $invitee = User::factory()->create(['email' => 'patricia.souza@example.com'])->fresh();
    $parameters = Fixtures::routeParameters($this->tenant, $issued->plainToken);

    $this->actingAs($invitee)
        ->get(route('invitations.show', $parameters))
        ->assertOk()
        ->assertSee('patricia.souza@example.com')
        ->assertSee('Aceitar convite')
        ->assertSee(route('invitations.accept', $parameters), false)
        ->assertSessionMissing('url.intended');
});

it('exige autenticação para aceitar o convite', function (): void {
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'patricia.souza@example.com', SystemRole::Viewer);

    $this->post(route('invitations.accept', Fixtures::routeParameters($this->tenant, $issued->plainToken)))
        ->assertRedirect(route('login'));

    expect(Fixtures::invitation($this->tenant, $issued->invitation->id)->accepted_at)->toBeNull();
});

it('aceita o convite de equipe, ativa o novo contexto na sessão e leva ao painel', function (): void {
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'eduardo.lopes@example.com', SystemRole::SalesRep);
    $invitee = User::factory()->unverified()->create(['email' => 'eduardo.lopes@example.com'])->fresh();

    $response = $this->actingAs($invitee)
        ->post(route('invitations.accept', Fixtures::routeParameters($this->tenant, $issued->plainToken)));

    $memberships = Fixtures::membershipsOf($this->tenant, $invitee);

    expect($memberships)->toHaveCount(1)
        ->and($memberships[0]->kind)->toBe(MembershipKind::Staff)
        ->and($invitee->fresh()->hasVerifiedEmail())->toBeTrue();

    $response->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', 'Convite aceito. Bem-vindo!')
        ->assertSessionHas(EstablishRequestContext::SESSION_KEY, [
            'membership_id' => $memberships[0]->id,
            'tenant_id' => $this->tenant->organization->id,
            'kind' => 'staff',
            'buyer_company_id' => null,
        ]);

    $this->get(route('dashboard'))->assertOk();
});

it('aceita o convite de comprador e leva ao portal da empresa', function (): void {
    $company = $this->tenant->addBuyerCompany(['trade_name' => 'Mercado Aurora']);
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'compras@mercadoaurora.example.com', SystemRole::Purchaser, $company);
    $invitee = User::factory()->create(['email' => 'compras@mercadoaurora.example.com'])->fresh();

    $response = $this->actingAs($invitee)
        ->post(route('invitations.accept', Fixtures::routeParameters($this->tenant, $issued->plainToken)));

    $membership = Fixtures::membershipsOf($this->tenant, $invitee)[0];

    $response->assertRedirect(route('portal.home'))
        ->assertSessionHas(EstablishRequestContext::SESSION_KEY, [
            'membership_id' => $membership->id,
            'tenant_id' => $this->tenant->organization->id,
            'kind' => 'buyer',
            'buyer_company_id' => $company->id,
        ]);

    $this->get(route('portal.home'))->assertOk()->assertSee('Mercado Aurora');
});

it('volta ao convite com a mensagem de erro quando o aceite é recusado', function (string $scenario, string $message): void {
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'marcelo.vieira@example.com', SystemRole::Viewer);
    $invitee = User::factory()->create(['email' => 'marcelo.vieira@example.com'])->fresh();
    $intruder = User::factory()->create(['email' => 'outra.conta@example.com'])->fresh();

    [$user, $organization, $token] = match ($scenario) {
        'email' => [$intruder, $this->tenant->organization->id, $issued->plainToken],
        'token' => [$invitee, $this->tenant->organization->id, Str::random(64)],
        'organizacao' => [$invitee, 'distribuidora-exemplo', $issued->plainToken],
        'suspensa' => [$invitee, $this->tenant->organization->id, $issued->plainToken],
    };

    if ($scenario === 'suspensa') {
        $this->tenant->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
    }

    $parameters = Fixtures::routeParameters($organization, $token);

    $this->actingAs($user)
        ->post(route('invitations.accept', $parameters))
        ->assertRedirect(route('invitations.show', $parameters))
        ->assertSessionHas('error', $message)
        ->assertSessionMissing(EstablishRequestContext::SESSION_KEY);

    $this->get(route('invitations.show', $parameters))->assertOk()->assertSee($message);

    expect(Fixtures::membershipsOf($this->tenant, $user))->toBe([])
        ->and(Fixtures::invitation($this->tenant, $issued->invitation->id)->accepted_at)->toBeNull();
})->with([
    'conta de outro e-mail' => ['email', 'Este convite foi enviado para outro e-mail. Entre com a conta correspondente.'],
    'token inexistente' => ['token', 'Convite inexistente.'],
    'organização inválida na URL' => ['organizacao', 'Convite inválido.'],
    'organização suspensa' => ['suspensa', 'A organização não está disponível.'],
]);

it('recusa token válido de outro tenant quando a organização da URL é adulterada', function (): void {
    $other = TenantScenario::create();
    $issued = Fixtures::issue($other, $other->ownerMembership, 'adriana.lima@example.com', SystemRole::Viewer);
    $invitee = User::factory()->create(['email' => 'adriana.lima@example.com'])->fresh();
    $tampered = Fixtures::routeParameters($this->tenant, $issued->plainToken);

    $this->actingAs($invitee)
        ->post(route('invitations.accept', $tampered))
        ->assertRedirect(route('invitations.show', $tampered))
        ->assertSessionHas('error', 'Convite inexistente.')
        ->assertSessionMissing(EstablishRequestContext::SESSION_KEY);

    expect(Fixtures::membershipsOf($this->tenant, $invitee))->toBe([])
        ->and(Fixtures::membershipsOf($other, $invitee))->toBe([])
        ->and(Fixtures::invitation($other, $issued->invitation->id)->accepted_at)->toBeNull();
});

it('aceita o convite de outro tenant por usuário que já está com um contexto ativo', function (): void {
    $user = User::factory()->create(['email' => 'roberto.antunes@example.com'])->fresh();
    $current = $this->tenant->addStaff(SystemRole::SalesRep, $user);
    $other = TenantScenario::create();
    $issued = Fixtures::issue($other, $other->ownerMembership, $user->email, SystemRole::Viewer);

    $response = $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($current))
        ->post(route('invitations.accept', Fixtures::routeParameters($other, $issued->plainToken)));

    // O contexto ativo é substituído pelo do convite aceito.
    $response->assertRedirect(route('dashboard'));

    $memberships = Fixtures::membershipsOf($other, $user);

    expect($memberships)->toHaveCount(1);

    $response->assertSessionHas(EstablishRequestContext::SESSION_KEY, EstablishRequestContext::sessionPayload($memberships[0]));
});

it('limita as tentativas de acesso a links de convite por endereço IP', function (): void {
    $url = route('invitations.show', Fixtures::routeParameters($this->tenant, Str::random(64)));

    foreach (range(1, 10) as $attempt) {
        $this->get($url)->assertOk();
    }

    $this->get($url)->assertTooManyRequests();
});

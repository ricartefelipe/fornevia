<?php

declare(strict_types=1);

use App\Actions\Platform\ChangeOrganizationStatus;
use App\Enums\Identity\OrganizationStatus;
use App\Enums\Identity\SystemRole;
use App\Http\Middleware\EstablishRequestContext;
use App\Models\Identity\User;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->withoutVite();

    $this->tenant = TenantScenario::create('profissional', 'Distribuidora Monte Sião');
    $this->rep = $this->tenant->addStaff(SystemRole::SalesRep);
    $this->repUser = User::query()->findOrFail($this->rep->user_id);
    $this->company = $this->tenant->addBuyerCompany(['trade_name' => 'Padaria Estrela do Vale']);
    $this->buyer = $this->tenant->addBuyerUser($this->company);
    $this->buyerUser = User::query()->findOrFail($this->buyer->user_id);
    $this->admin = User::factory()->platformAdmin()->withTwoFactor()->create()->fresh();
});

it('encerra o acesso da equipe e dos compradores na requisição seguinte à suspensão', function (OrganizationStatus $status): void {
    $this->actingAs($this->repUser)
        ->withSession(TenantScenario::sessionFor($this->rep))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Distribuidora Monte Sião');

    $this->actingAs($this->buyerUser)
        ->withSession(TenantScenario::sessionFor($this->buyer))
        ->get(route('portal.home'))
        ->assertOk()
        ->assertSee('Padaria Estrela do Vale');

    app(ChangeOrganizationStatus::class)->handle($this->admin, $this->tenant->organization, $status, 'Determinação da diretoria');

    $this->actingAs($this->repUser)
        ->withSession(TenantScenario::sessionFor($this->rep))
        ->get(route('dashboard'))
        ->assertRedirect(route('contexts.index'))
        ->assertSessionMissing(EstablishRequestContext::SESSION_KEY)
        ->assertSessionHas('status', 'O acesso a este contexto não está mais disponível.');

    $this->actingAs($this->buyerUser)
        ->withSession(TenantScenario::sessionFor($this->buyer))
        ->get(route('portal.home'))
        ->assertRedirect(route('contexts.index'))
        ->assertSessionMissing(EstablishRequestContext::SESSION_KEY);
})->with([
    'suspensa' => [OrganizationStatus::Suspended],
    'cancelada' => [OrganizationStatus::Cancelled],
]);

it('não permite selecionar o contexto da organização suspensa e o exibe como indisponível', function (): void {
    app(ChangeOrganizationStatus::class)->handle($this->admin, $this->tenant->organization, OrganizationStatus::Suspended, 'Determinação da diretoria');

    $this->actingAs($this->repUser)
        ->post(route('contexts.store'), ['membership_id' => $this->rep->id])
        ->assertRedirect(route('contexts.index', ['escolher' => 1]))
        ->assertSessionHas('error', 'Contexto indisponível.')
        ->assertSessionMissing(EstablishRequestContext::SESSION_KEY);

    // Com um único contexto, a retomada automática não ocorre para organização suspensa.
    $this->actingAs($this->repUser)
        ->get(route('contexts.index'))
        ->assertOk()
        ->assertSee('Distribuidora Monte Sião')
        ->assertSee('Indisponível');
});

it('restabelece o acesso após a reativação pela plataforma', function (): void {
    $change = app(ChangeOrganizationStatus::class);
    $change->handle($this->admin, $this->tenant->organization, OrganizationStatus::Suspended, 'Determinação da diretoria');

    $this->actingAs($this->repUser)
        ->withSession(TenantScenario::sessionFor($this->rep))
        ->get(route('dashboard'))
        ->assertRedirect(route('contexts.index'));

    $change->handle($this->admin, $this->tenant->organization, OrganizationStatus::Active, 'Pendência resolvida');

    $this->actingAs($this->repUser)
        ->post(route('contexts.store'), ['membership_id' => $this->rep->id])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas(EstablishRequestContext::SESSION_KEY, EstablishRequestContext::sessionPayload($this->rep));

    $this->actingAs($this->repUser)
        ->withSession(TenantScenario::sessionFor($this->rep))
        ->get(route('dashboard'))
        ->assertOk();

    $this->actingAs($this->buyerUser)
        ->withSession(TenantScenario::sessionFor($this->buyer))
        ->get(route('portal.home'))
        ->assertOk();
});

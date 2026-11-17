<?php

declare(strict_types=1);

use App\Domain\Shared\Cnpj;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\OrganizationStatus;
use App\Enums\Platform\SubscriptionStatus;
use App\Http\Middleware\EstablishRequestContext;
use App\Livewire\Onboarding\CreateOrganizationForm;
use App\Models\Identity\Membership;
use App\Models\Identity\Organization;
use App\Models\Identity\User;
use App\Models\Platform\Subscription;
use Database\Factories\Support\BrazilianDocuments;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Builders\TenantScenario;

function onboardingFormularioPreenchido(User $user, string $document, string $plan = 'corporativo'): Testable
{
    return Livewire::actingAs($user)
        ->test(CreateOrganizationForm::class)
        ->set('legalName', '  Ponte Alta Distribuidora de Alimentos Ltda  ')
        ->set('tradeName', 'Ponte Alta Alimentos')
        ->set('document', $document)
        ->set('planCode', $plan)
        ->set('email', 'financeiro@pontealta.example')
        ->set('phone', '(31) 3555-0101');
}

beforeEach(function (): void {
    $this->withoutVite();
    (new PlanSeeder)->run();

    $this->user = User::factory()->withTwoFactor()->create()->fresh();
    $this->cnpj = BrazilianDocuments::cnpj();
});

it('pré-seleciona o primeiro plano disponível e lista apenas planos contratáveis', function (): void {
    DB::table('plans')->where('code', 'essencial')->update(['is_public' => false]);
    DB::table('plans')->where('code', 'corporativo')->update(['is_active' => false]);

    Livewire::actingAs($this->user)
        ->test(CreateOrganizationForm::class)
        ->assertSet('planCode', 'profissional')
        ->assertSee('Profissional')
        ->assertSee('R$ 2.490,00/mês')
        ->assertDontSee('Essencial')
        ->assertDontSee('Corporativo');
});

it('cadastra a distribuidora, ativa o contexto de proprietário na sessão e redireciona ao painel', function (): void {
    onboardingFormularioPreenchido($this->user, Cnpj::format($this->cnpj))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $organization = Organization::query()->sole();

    expect($organization->legal_name)->toBe('Ponte Alta Distribuidora de Alimentos Ltda')
        ->and($organization->trade_name)->toBe('Ponte Alta Alimentos')
        ->and($organization->document)->toBe($this->cnpj)
        ->and($organization->email)->toBe('financeiro@pontealta.example')
        ->and($organization->phone)->toBe('(31) 3555-0101')
        ->and($organization->status)->toBe(OrganizationStatus::Trial);

    $subscription = Subscription::query()->with('plan')->where('organization_id', $organization->id)->sole();
    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->plan->code)->toBe('corporativo');

    $membership = asTenant($organization, fn (): Membership => Membership::query()->sole());
    $payload = session(EstablishRequestContext::SESSION_KEY);

    expect($membership->user_id)->toBe($this->user->id)
        ->and($payload)->toBe([
            'membership_id' => $membership->id,
            'tenant_id' => $organization->id,
            'kind' => MembershipKind::Staff->value,
            'buyer_company_id' => null,
        ])
        ->and(session('status'))->toBe('Distribuidora cadastrada. O período de avaliação começou.');

    // O contexto gravado na sessão dá acesso ao painel da nova organização.
    $this->actingAs($this->user)
        ->withSession([EstablishRequestContext::SESSION_KEY => $payload])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Ponte Alta Alimentos')
        ->assertSee('Corporativo')
        ->assertSee('Em avaliação');
});

it('mostra erro no CNPJ inválido sem criar a organização', function (string $document): void {
    $component = onboardingFormularioPreenchido($this->user, $document)
        ->call('save')
        ->assertHasErrors(['document'])
        ->assertNoRedirect()
        ->assertSee('CNPJ inválido.');

    expect($component->errors()->first('document'))->toBe('CNPJ inválido.')
        ->and(Organization::query()->count())->toBe(0)
        ->and(session()->has(EstablishRequestContext::SESSION_KEY))->toBeFalse();
})->with([
    'dígitos verificadores incorretos' => ['62.418.937/0001-00'],
    'todos os dígitos iguais' => ['11.111.111/1111-11'],
    'quantidade de dígitos insuficiente' => ['6241893700013'],
    'letras no lugar de dígitos' => ['AB.CDE.FGH/0001-XY'],
]);

it('mostra erro no CNPJ já cadastrado por outra distribuidora', function (): void {
    $existing = TenantScenario::create('essencial', 'Atacado Serrano');

    $component = onboardingFormularioPreenchido($this->user, Cnpj::format($existing->organization->document))
        ->call('save')
        ->assertHasErrors(['document'])
        ->assertNoRedirect();

    expect($component->errors()->first('document'))->toBe('Já existe uma organização cadastrada com este CNPJ.')
        ->and(Organization::query()->count())->toBe(1)
        ->and(session()->has(EstablishRequestContext::SESSION_KEY))->toBeFalse();
});

it('mostra erro no plano quando o plano escolhido deixa de estar disponível', function (): void {
    $component = onboardingFormularioPreenchido($this->user, $this->cnpj, 'corporativo');

    DB::table('plans')->where('code', 'corporativo')->update(['is_active' => false]);

    $component->call('save')
        ->assertHasErrors(['planCode'])
        ->assertHasNoErrors(['document'])
        ->assertNoRedirect();

    expect($component->errors()->first('planCode'))->toBe('Plano indisponível para contratação.')
        ->and(Organization::query()->count())->toBe(0);
});

it('valida campos obrigatórios e formato do e-mail antes de criar a organização', function (): void {
    Livewire::actingAs($this->user)
        ->test(CreateOrganizationForm::class)
        ->set('email', 'financeiro-sem-arroba')
        ->set('phone', '+55 (31) 3555-0101 ramal 22')
        ->call('save')
        ->assertHasErrors([
            'legalName' => 'required',
            'tradeName' => 'required',
            'document' => 'required',
            'email' => 'email',
            'phone' => 'max',
        ]);

    expect(Organization::query()->count())->toBe(0);
});

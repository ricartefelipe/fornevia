<?php

declare(strict_types=1);

use App\Domain\Shared\Cnpj;
use App\Enums\Identity\OrganizationStatus;
use App\Http\Middleware\EstablishRequestContext;
use App\Models\Identity\Membership;
use App\Models\Identity\Organization;
use App\Models\Identity\User;
use Database\Factories\Support\BrazilianDocuments;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\Support\Builders\TenantScenario;
use Tests\TestCase;

/*
 * Fluxos pelo endpoint real de atualização do Livewire: a requisição passa
 * pelo grupo web (EstablishRequestContext, transação com contexto) e pelos
 * middlewares persistentes da rota que renderizou a página.
 */

function fluxoSnapshot(TestResponse $page): string
{
    if (preg_match('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $match) !== 1) {
        throw new RuntimeException('Snapshot do componente não encontrado na página.');
    }

    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
}

/**
 * Envia uma ação ao endpoint de atualização do Livewire. Por padrão usa os
 * mesmos cabeçalhos do cliente JavaScript (Content-Type JSON e X-Livewire,
 * sem Accept: application/json).
 *
 * @param  array<string, mixed>  $updates
 * @param  list<mixed>  $params
 * @param  array<string, string>  $headers
 */
function fluxoAcao(TestCase $test, string $snapshot, array $updates, string $method, array $params = [], array $headers = []): TestResponse
{
    return $test->json('POST', app(HandleRequests::class)->getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => [['method' => $method, 'params' => $params, 'metadata' => []]],
        ]],
    ], ['X-Livewire' => '1', 'Accept' => '*/*', ...$headers]);
}

/**
 * @return array<string, string>
 */
function fluxoCamposOnboarding(string $cnpj): array
{
    return [
        'legalName' => 'Cerrado Norte Distribuidora de Bebidas Ltda',
        'tradeName' => 'Cerrado Norte Bebidas',
        'document' => Cnpj::format($cnpj),
        'planCode' => 'profissional',
        'email' => 'contato@cerradonorte.example',
        'phone' => '(62) 3555-0199',
    ];
}

/**
 * Proprietário com 2FA confirmado e o contexto de equipe da organização na sessão.
 *
 * @return array{0: TenantScenario, 1: User}
 */
function fluxoProprietarioEmContexto(TestCase $test): array
{
    $current = TenantScenario::create('essencial', 'Armazém Ipê Amarelo');
    $owner = $current->owner->fresh();
    $owner->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();

    $test->actingAs($owner)->withSession(TenantScenario::sessionFor($current->ownerMembership));

    return [$current, $owner];
}

beforeEach(function (): void {
    $this->withoutVite();
    (new PlanSeeder)->run();
});

it('conclui o onboarding pelo endpoint do Livewire a partir do contexto de usuário', function (): void {
    $user = User::factory()->withTwoFactor()->create()->fresh();
    $cnpj = BrazilianDocuments::cnpj();

    $page = $this->actingAs($user)->get(route('onboarding'))->assertOk();

    fluxoAcao($this, fluxoSnapshot($page), fluxoCamposOnboarding($cnpj), 'save')
        ->assertOk()
        ->assertJsonPath('components.0.effects.redirect', route('dashboard'));

    $organization = Organization::query()->where('document', $cnpj)->sole();
    $membership = asTenant($organization, fn (): Membership => Membership::query()->sole());

    expect(session(EstablishRequestContext::SESSION_KEY))->toEqual(EstablishRequestContext::sessionPayload($membership));

    $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Cerrado Norte Bebidas');
});

it('abre a troca de contexto com um contexto ativo e oferece o cadastro de outra organização', function (): void {
    [, $owner] = fluxoProprietarioEmContexto($this);

    // Link "Trocar contexto" do cabeçalho do layout autenticado.
    $this->actingAs($owner)
        ->get(route('contexts.index', ['escolher' => 1]))
        ->assertOk()
        ->assertSee('Armazém Ipê Amarelo')
        ->assertSee(route('onboarding'), escape: false);
});

it('permite cadastrar outra distribuidora quando a sessão mantém o contexto de equipe de outra organização', function (): void {
    [$current, $owner] = fluxoProprietarioEmContexto($this);
    $cnpj = BrazilianDocuments::cnpj();

    $page = $this->actingAs($owner)->get(route('onboarding'))->assertOk();

    fluxoAcao($this, fluxoSnapshot($page), fluxoCamposOnboarding($cnpj), 'save')
        ->assertOk()
        ->assertJsonPath('components.0.effects.redirect', route('dashboard'));

    $created = Organization::query()->where('document', $cnpj)->sole();
    $membership = asTenant($created, fn (): Membership => Membership::query()->sole());

    expect($created->id)->not->toBe($current->organization->id)
        ->and($membership->user_id)->toBe($owner->id)
        ->and(session(EstablishRequestContext::SESSION_KEY))->toEqual(EstablishRequestContext::sessionPayload($membership));
});

it('reaplica a exigência de administrador da plataforma nas ações do componente de organizações', function (): void {
    $admin = User::factory()->platformAdmin()->withTwoFactor()->create()->fresh();
    $commonUser = User::factory()->withTwoFactor()->create()->fresh();
    $tenant = TenantScenario::create();

    $snapshot = fluxoSnapshot($this->actingAs($admin)->get(route('platform.organizations'))->assertOk());

    fluxoAcao($this->actingAs($commonUser), $snapshot, ['reason' => 'Tentativa sem permissão'], 'suspend', [$tenant->organization->id])
        ->assertForbidden();

    expect(Organization::query()->findOrFail($tenant->organization->id)->status)->toBe(OrganizationStatus::Trial);

    fluxoAcao($this->actingAs($admin), $snapshot, ['reason' => 'Inadimplência confirmada'], 'suspend', [$tenant->organization->id])
        ->assertOk();

    expect(Organization::query()->findOrFail($tenant->organization->id)->status)->toBe(OrganizationStatus::Suspended);
});

it('reaplica a exigência de 2FA nas ações do componente de organizações para administrador sem segundo fator', function (array $headers, int $status): void {
    $admin = User::factory()->platformAdmin()->withTwoFactor()->create()->fresh();
    $adminWithoutTwoFactor = User::factory()->platformAdmin()->create()->fresh();
    $tenant = TenantScenario::create();

    $snapshot = fluxoSnapshot($this->actingAs($admin)->get(route('platform.organizations'))->assertOk());

    // A Action aceitaria este administrador; quem deve recusar é o middleware persistente de 2FA.
    $response = fluxoAcao($this->actingAs($adminWithoutTwoFactor), $snapshot, ['reason' => 'Tentativa sem segundo fator'], 'suspend', [$tenant->organization->id], $headers);

    expect(Organization::query()->findOrFail($tenant->organization->id)->status)->toBe(OrganizationStatus::Trial)
        ->and(DB::table('platform_audit_logs')->where('action', 'organization.status_changed')->count())->toBe(0);

    $response->assertStatus($status);
})->with([
    'cabeçalhos do cliente Livewire' => [[], 302],
    'requisição que aceita JSON' => [['Accept' => 'application/json'], 403],
]);

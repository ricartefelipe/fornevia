<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Models\Pricing\NegotiatedPrice;
use App\Models\Pricing\PriceList;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\Integration\Pricing\PricingFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

/*
| Ações das telas de preços pelo endpoint real de atualização do Livewire:
| transação da requisição com contexto (EstablishRequestContext), middlewares
| persistentes da rota (permission:pricing.view) e Actions.
*/

beforeEach(function (): void {
    $this->withoutVite();
    Fx::freezeClock('2026-11-17T15:00:00Z');
    $this->tenant = TenantScenario::create();
    $this->company = Fx::company($this->tenant, 'Atacarejo Boa Vista');
    $this->sku = Fx::sku($this->tenant, 'FEI-1KG', 'Feijão carioca tipo 1 1 kg');

    $this->member = function (SystemRole $role): array {
        $user = User::factory()->withTwoFactor()->create()->fresh();

        return [$user, $this->tenant->addStaff($role, $user)];
    };

    $this->snapshot = function (User $user, Membership $membership, string $url): string {
        $page = $this->actingAs($user)->withSession(TenantScenario::sessionFor($membership))->get($url)->assertOk();

        return Tests\Support\LivewireSnapshot::page($page);
    };

    $this->call = fn (User $user, Membership $membership, string $snapshot, array $updates, string $method, array $params = []): TestResponse => $this
        ->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->json('POST', app(HandleRequests::class)->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => $updates,
                'calls' => [['method' => $method, 'params' => $params, 'metadata' => []]],
            ]],
        ], ['X-Livewire' => '1', 'Accept' => '*/*']);

    $this->negotiated = fn (): array => $this->tenant->system(static fn (): array => NegotiatedPrice::query()
        ->orderBy('valid_from')
        ->get()
        ->map(static fn (NegotiatedPrice $p): array => [$p->unit_price_cents->cents, $p->valid_from->toDateString(), $p->status->value])
        ->all());
});

it('converte a recusa de sobreposição em erro de formulário e mantém a requisição íntegra', function (): void {
    [$user, $manager] = ($this->member)(SystemRole::SalesManager);
    Fx::negotiated($this->tenant, $this->company, $this->sku, '8,90', '2026-12-01', '2026-12-31');

    $snapshot = ($this->snapshot)($user, $manager, route('pricing.negotiated'));

    // A seleção do SKU é feita pelo servidor (propriedade protegida).
    $selected = ($this->call)($user, $manager, $snapshot, [], 'selectSku', [$this->sku->id])->assertOk();
    $snapshot = (string) $selected->json('components.0.snapshot');

    $refused = ($this->call)($user, $manager, $snapshot, [
        'buyerCompanyId' => $this->company->id,
        'unitPrice' => '8,50',
        'validFrom' => '2026-12-20',
        'validUntil' => '',
    ], 'create')->assertOk();

    $state = json_decode((string) $refused->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);

    expect($state['memo']['errors']['validFrom'][0] ?? null)->toContain('Já existe condição negociada ativa para esta empresa e este SKU de 01/12/2026 a 31/12/2026');

    // Na mesma sessão, um período sem sobreposição é registrado normalmente.
    ($this->call)($user, $manager, (string) $refused->json('components.0.snapshot'), [
        'validFrom' => '2027-01-01',
    ], 'create')->assertOk();

    expect(($this->negotiated)())->toBe([[890, '2026-12-01', 'active'], [850, '2027-01-01', 'active']]);
});

it('responde 404 para identificador de outro tenant enviado na ação', function (): void {
    [$user, $manager] = ($this->member)(SystemRole::SalesManager);
    $other = TenantScenario::create();
    $foreignCompany = Fx::company($other, 'Atacarejo Boa Vista');
    $foreignSku = Fx::sku($other, 'FEI-1KG', 'Feijão carioca tipo 1 1 kg');
    $foreignPrice = Fx::negotiated($other, $foreignCompany, $foreignSku, '7,90', '2026-12-01');

    $snapshot = ($this->snapshot)($user, $manager, route('pricing.negotiated'));

    ($this->call)($user, $manager, $snapshot, ['revokeReason' => 'Tentativa de revogação indevida'], 'revoke', [$foreignPrice->id])->assertNotFound();
    ($this->call)($user, $manager, $snapshot, [], 'selectSku', [$foreignSku->id])->assertNotFound();

    expect($other->system(static fn (): string => NegotiatedPrice::query()->findOrFail($foreignPrice->id)->status->value))->toBe('active');
});

it('reaplica pricing.view nas ações e deixa a autorização de escrita para as Actions', function (): void {
    [$managerUser, $manager] = ($this->member)(SystemRole::SalesManager);
    [$viewerUser, $viewer] = ($this->member)(SystemRole::Viewer);
    [$warehouseUser, $warehouse] = ($this->member)(SystemRole::Warehouse);
    $list = Fx::priceList($this->tenant, 'PADRAO', activate: false);
    $url = route('pricing.lists.edit', ['priceList' => $list->id]);

    // Perfil sem pricing.view reaproveitando o snapshot de outra sessão: barrado pelo middleware persistente.
    $managerSnapshot = ($this->snapshot)($managerUser, $manager, $url);
    ($this->call)($warehouseUser, $warehouse, $managerSnapshot, [], 'activate')->assertForbidden();

    // Perfil de consulta: a tela abre, mas a Action recusa a ativação.
    $viewerSnapshot = ($this->snapshot)($viewerUser, $viewer, $url);
    ($this->call)($viewerUser, $viewer, $viewerSnapshot, [], 'activate')->assertForbidden();

    expect($this->tenant->system(static fn (): string => PriceList::query()->findOrFail($list->id)->status->value))->toBe('draft');

    ($this->call)($managerUser, $manager, $managerSnapshot, [], 'activate')->assertOk();

    expect($this->tenant->system(static fn (): string => PriceList::query()->findOrFail($list->id)->status->value))->toBe('active');
});

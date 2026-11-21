<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\Integration\Ordering\Staff\StaffOrderFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Cancelamento pelo endpoint real de atualização do Livewire: transação da
| requisição com contexto de equipe, elevação para o contexto de sistema do
| mesmo usuário dentro da Action (estoque, crédito e outbox), middlewares
| persistentes da rota (permission:orders.view) e autorização da Action.
*/

beforeEach(function (): void {
    $this->withoutVite();
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 2_000, creditLimitCents: 100_000, onCredit: true);
    $this->tenant = $this->scenario->tenant;
    $this->placed = Fx::place($this->scenario, '4');
    $this->url = route('orders.show', ['order' => $this->placed['order']->id]);
    $this->itemId = $this->tenant->system(fn () => DB::table('order_items')->where('order_id', $this->placed['order']->id)->value('id'));

    $this->snapshot = function (User $user, Membership $membership): string {
        $page = $this->actingAs($user)->withSession(TenantScenario::sessionFor($membership))->get($this->url)->assertOk();

        if (preg_match('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $match) !== 1) {
            throw new RuntimeException('Snapshot do componente não encontrado na página.');
        }

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    };

    $this->call = fn (User $user, Membership $membership, string $snapshot, array $updates, string $method): TestResponse => $this
        ->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->json('POST', app(HandleRequests::class)->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => $updates,
                'calls' => [['method' => $method, 'params' => [], 'metadata' => []]],
            ]],
        ], ['X-Livewire' => '1', 'Accept' => '*/*']);
});

it('cancela parte do pedido pela requisição Livewire, com ledgers, outbox e auditoria', function (): void {
    [$user, $manager] = Fx::staffUser($this->tenant, SystemRole::SalesManager);
    $snapshot = ($this->snapshot)($user, $manager);

    $response = ($this->call)($user, $manager, $snapshot, [
        "quantities.{$this->itemId}" => '1',
        'cancelReason' => 'Cliente reduziu a quantidade',
    ], 'cancelSelected')->assertOk();

    $state = json_decode((string) $response->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);

    expect($state['data']['notice'] ?? null)->toBe("Cancelamento registrado no pedido {$this->placed['order']->number}.")
        ->and(Fx::state($this->tenant, $this->placed['order']))->toMatchArray([
            'status' => 'partially_cancelled',
            'credit' => 6_000,
            'stock_reserved' => '3.000',
            'account_committed' => 6_000,
            'events' => ['order.confirmed', 'order.items_cancelled'],
        ])
        ->and($this->tenant->system(fn (): array => [
            DB::table('outbox_messages')->where('aggregate_id', $this->placed['order']->id)->orderBy('created_at')->pluck('type')->all(),
            DB::table('audit_logs')->where('subject_id', $this->placed['order']->id)->where('action', 'order.items_cancelled')->value('actor_membership_id'),
        ]))->toBe([['order.confirmed', 'order.items_cancelled'], $manager->id]);
});

it('converte a recusa de quantidade em erro de campo sem efeito parcial', function (): void {
    [$user, $manager] = Fx::staffUser($this->tenant, SystemRole::SalesManager);
    $before = Fx::state($this->tenant, $this->placed['order']);

    $response = ($this->call)($user, $manager, ($this->snapshot)($user, $manager), [
        "quantities.{$this->itemId}" => '9',
        'cancelReason' => 'Cliente reduziu a quantidade',
    ], 'cancelSelected')->assertOk();

    $state = json_decode((string) $response->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);

    expect($state['memo']['errors']["quantities.{$this->itemId}"][0] ?? null)->toBe('O saldo cancelável deste item é 4 UN.')
        ->and(Fx::state($this->tenant, $this->placed['order']))->toBe($before);
});

it('reaplica orders.view nas ações e deixa orders.manage para a Action', function (): void {
    [$managerUser, $manager] = Fx::staffUser($this->tenant, SystemRole::SalesManager);
    [$viewerUser, $viewer] = Fx::staffUser($this->tenant, SystemRole::Viewer);
    $buyerUser = User::factory()->withTwoFactor()->create()->fresh();
    $buyer = $this->tenant->addBuyerUser($this->placed['company'], SystemRole::BuyerAdmin, $buyerUser);
    $before = Fx::state($this->tenant, $this->placed['order']);
    $updates = ['cancelReason' => 'Tentativa de cancelamento indevida'];

    // Snapshot de outra sessão reaproveitado em contexto de comprador: barrado pelo middleware persistente.
    $managerSnapshot = ($this->snapshot)($managerUser, $manager);
    ($this->call)($buyerUser, $buyer, $managerSnapshot, $updates, 'cancelAll')->assertForbidden();

    // Perfil de consulta: a tela abre, mas a Action recusa o cancelamento.
    ($this->call)($viewerUser, $viewer, ($this->snapshot)($viewerUser, $viewer), $updates, 'cancelAll')->assertForbidden();

    expect(Fx::state($this->tenant, $this->placed['order']))->toBe($before);
});

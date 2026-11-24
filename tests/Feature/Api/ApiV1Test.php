<?php

declare(strict_types=1);

use App\Actions\Integrations\ApiClientAuthenticator;
use App\Actions\Integrations\ManageApiClients;
use App\Enums\Fulfillment\ShipmentStatus;
use App\Enums\Identity\SystemRole;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Integration\Fulfillment\FulfillmentFlow as S;
use Tests\Support\Builders\OrderingScenario;

/*
| API v1 (RF-110): token do cliente com abilities e tenant, contexto de
| integração, permissões, filtros permitidos, paginação limitada, erros
| estruturados e eventos de transportadora idempotentes.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '4', sameCompany: true);
    $this->order = Fx::place($this->scenario, $this->buyer);
    $this->owner = $this->scenario->tenant->ownerMembership;
    $this->client = fn (array $permissions, int $days = 30): array => $this->scenario->tenant->actingAs($this->owner, static fn (): array => app(ManageApiClients::class)->create('ERP', $permissions, $days));
    [$this->apiClient, $this->token] = ($this->client)(['orders.view', 'catalog.view', 'fulfillment.manage', 'finance.view', 'inventory.view', 'buyers.view']);
    $this->api = fn (string $method, string $uri, array $data = [], ?string $token = null) => $this->withHeaders(['Authorization' => 'Bearer '.($token ?? $this->token)])->json($method, '/api/v1'.$uri, $data);
});

it('lista e mostra pedidos do tenant do token, com paginação e filtros permitidos', function (): void {
    ($this->api)('GET', '/orders?per_page=10')
        ->assertOk()
        ->assertJsonPath('data.0.number', $this->order->number)
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonMissingPath('data.0.items');

    ($this->api)('GET', "/orders/{$this->order->id}")
        ->assertOk()
        ->assertJsonPath('data.items.0.quantity', '4.000')
        ->assertJsonPath('data.total_cents', 40_000)
        ->assertJsonMissingPath('data.items.0.price_source');

    ($this->api)('GET', '/orders?commercial_status=cancelled')->assertOk()->assertJsonCount(0, 'data');
    ($this->api)('GET', '/orders?per_page=101')->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['per_page']], 'request_id']);
    ($this->api)('GET', '/orders?cor=azul')->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['cor']]]);
    ($this->api)('GET', '/orders?commercial_status=inexistente')->assertUnprocessable();
});

it('isola o tenant: o pedido de outro distribuidor não existe para o token', function (): void {
    $other = OrderingScenario::create(stock: '5');
    $foreign = Fx::place($other, $other->buyerWithCart(quantity: '1'));

    ($this->api)('GET', "/orders/{$foreign->id}")->assertNotFound()->assertJsonPath('error.code', 'not_found');
    ($this->api)('GET', '/orders')->assertOk()->assertJsonCount(1, 'data');
    ($this->api)('GET', '/orders/nao-e-uuid')->assertNotFound();
});

it('recusa token ausente, inválido, vencido ou de cliente revogado', function (): void {
    $this->getJson('/api/v1/orders')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    ($this->api)('GET', '/orders', [], 'lixo')->assertUnauthorized();
    [$id] = explode('|', $this->token);
    ($this->api)('GET', '/orders', [], $id.'|fvk_errado')->assertUnauthorized();

    app()->instance(Clock::class, new FrozenClock(now()->addDays(31)->toImmutable()));
    ($this->api)('GET', '/orders')->assertUnauthorized();
    app()->instance(Clock::class, new FrozenClock(now()->toImmutable()));

    $this->scenario->tenant->actingAs($this->owner, fn () => app(ManageApiClients::class)->revoke($this->apiClient->id));
    ($this->api)('GET', '/orders')->assertUnauthorized();
});

it('aplica as permissões do cliente a cada recurso', function (): void {
    [, $catalogOnly] = ($this->client)(['catalog.view']);

    ($this->api)('GET', '/orders', [], $catalogOnly)->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    ($this->api)('GET', '/skus', [], $catalogOnly)->assertOk()->assertJsonPath('data.0.code', $this->scenario->sku->code);
    ($this->api)('GET', '/stock')->assertOk()->assertJsonPath('data.0.reserved', '4.000');
    ($this->api)('GET', '/buyers')->assertOk()->assertJsonPath('data.0.id', $this->buyer['company']->id);
    ($this->api)('GET', '/invoices')->assertOk()->assertJsonCount(0, 'data');

    expect(fn () => ($this->client)(['portal.cart']))->toThrow(App\Domain\Integrations\Exceptions\InvalidIntegration::class)
        ->and(fn () => ($this->client)(['orders.view'], 400))->toThrow(App\Domain\Integrations\Exceptions\InvalidIntegration::class);
});

it('registra eventos da transportadora uma vez por identificador externo', function (): void {
    $shipment = S::shipAll($this->scenario, $this->scenario->tenant->addStaff(SystemRole::Warehouse), $this->order, '4');
    $event = ['external_id' => 'TRK-1', 'kind' => 'delivered', 'occurred_at' => now()->toIso8601String()];

    ($this->api)('POST', "/shipments/{$shipment->id}/events", $event)->assertCreated()->assertJsonPath('data.applied', true);
    ($this->api)('POST', "/shipments/{$shipment->id}/events", $event)->assertCreated()->assertJsonPath('data.external_id', 'TRK-1');
    ($this->api)('POST', "/shipments/{$shipment->id}/events", ['kind' => 'voou'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['external_id', 'kind', 'occurred_at']]]);

    expect(S::fresh($this->scenario, $shipment)->status)->toBe(ShipmentStatus::Delivered)
        ->and(S::fresh($this->scenario, $shipment)->events)->toHaveCount(1);
});

it('limita as requisições por cliente', function (): void {
    foreach (range(1, ApiClientAuthenticator::PER_MINUTE) as $ignored) {
        RateLimiter::hit('api:'.$this->apiClient->id, 60);
    }

    ($this->api)('GET', '/orders')->assertStatus(429)->assertJsonPath('error.code', 'too_many_requests');
});

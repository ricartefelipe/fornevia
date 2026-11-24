<?php

declare(strict_types=1);

use App\Actions\Integrations\ManageWebhookEndpoints;
use App\Domain\Integrations\Exceptions\InvalidIntegration;
use App\Models\Integrations\WebhookDelivery;
use App\Models\Integrations\WebhookEndpoint;
use App\Services\Integrations\WebhookSender;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use App\Support\Outbox\OutboxProcessor;
use App\Support\Outbox\OutboxRelay;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;

/*
| Webhooks enviados (RF-111, ADR-029): assinatura HMAC com carimbo de
| tempo, id estável, só campos públicos, proteção contra SSRF, novas
| tentativas com espera, desativação por falhas e envio de teste.
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync', 'integrations.webhooks.allow_private_networks' => false, 'integrations.webhooks.allow_http' => false]);
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $this->owner = $this->scenario->tenant->ownerMembership;
    $this->manage = fn (Closure $call) => $this->scenario->tenant->actingAs($this->owner, fn () => $call(app(ManageWebhookEndpoints::class)));
    $this->url = 'https://93.184.216.34/webhooks/fornevia';
    $this->deliveries = fn (): Illuminate\Support\Collection => $this->scenario->system(static fn () => WebhookDelivery::query()->orderBy('created_at')->get());
});

it('recusa endereço interno, sem TLS, com credenciais ou eventos fora da lista', function (string $url): void {
    expect(fn () => ($this->manage)(fn ($m) => $m->create($url, ['order.confirmed'])))->toThrow(InvalidIntegration::class);
})->with([
    'loopback' => ['https://127.0.0.1/hook'],
    'rede privada' => ['https://10.0.0.5/hook'],
    'link-local (metadados de nuvem)' => ['https://169.254.169.254/latest/meta-data'],
    'IPv6 loopback' => ['https://[::1]/hook'],
    'CGNAT' => ['https://100.100.100.200/hook'],
    'faixa de testes de desempenho' => ['https://198.18.0.10/hook'],
    'IPv4 mapeado em IPv6' => ['https://[::ffff:10.0.0.5]/hook'],
    'NAT64 para rede privada' => ['https://[64:ff9b::a00:5]/hook'],
    '6to4 para loopback' => ['https://[2002:7f00:1::1]/hook'],
    'sem TLS' => ['http://93.184.216.34/hook'],
    'com credenciais' => ['https://user:senha@93.184.216.34/hook'],
    'outro esquema' => ['ftp://93.184.216.34/hook'],
]);

it('aceita endereço interno só quando a configuração permite', function (): void {
    expect(fn () => ($this->manage)(fn ($m) => $m->create($this->url, ['pedido.inexistente'])))->toThrow(InvalidIntegration::class, 'evento');

    config(['integrations.webhooks.allow_private_networks' => true, 'integrations.webhooks.allow_http' => true]);
    [$endpoint] = ($this->manage)(fn ($m) => $m->create('http://10.0.0.5/hook', ['order.confirmed']));

    expect($endpoint->url)->toBe('http://10.0.0.5/hook');
});

it('entrega o evento assinado, com id estável e só os campos públicos', function (): void {
    Http::fake(['93.184.216.34/*' => Http::response('', 204)]);
    [, $secret] = ($this->manage)(fn ($m) => $m->create($this->url, ['order.confirmed', 'shipment.shipped']));
    $order = Fx::place($this->scenario, $this->scenario->buyerWithCart(quantity: '2'));
    app(OutboxRelay::class)->runOnce('relay-teste', 100);

    $delivery = ($this->deliveries)()->sole();
    expect($delivery->status)->toBe('delivered')->and($delivery->attempts)->toBe(1)->and($delivery->response_status)->toBe(204);

    Http::assertSent(function (Request $request) use ($secret, $delivery, $order): bool {
        $timestamp = $request->header('X-Fornevia-Timestamp')[0];
        $body = $request->body();
        $payload = json_decode($body, true);

        return $request->header('X-Fornevia-Event')[0] === 'order.confirmed'
            && $request->header('X-Fornevia-Delivery')[0] === $delivery->id
            && $request->header('X-Fornevia-Signature')[0] === 't='.$timestamp.',v1='.WebhookSender::signature($secret, $timestamp, $body)
            // Mesmos campos, em qualquer ordem: só os públicos do evento.
            && $payload['data'] == ['order_id' => $order->id, 'number' => $order->number, 'buyer_company_id' => $order->buyer_company_id, 'total_cents' => 20_000]
            && $payload['id'] === $delivery->outbox_message_id;
    });

    // Nova entrega da mesma mensagem não duplica.
    app(OutboxProcessor::class)->process($delivery->outbox_message_id, $this->scenario->tenant->organization->id);
    expect(($this->deliveries)())->toHaveCount(1);
});

it('tenta de novo com espera, para depois do máximo e desativa o endpoint por falhas seguidas', function (): void {
    config(['integrations.webhooks.max_attempts' => 2, 'integrations.webhooks.disable_after_failures' => 3, 'integrations.webhooks.backoff_seconds' => [120]]);
    Http::fake(['93.184.216.34/*' => Http::response('erro', 500)]);
    [$endpoint] = ($this->manage)(fn ($m) => $m->create($this->url, ['order.confirmed']));
    Fx::place($this->scenario, $this->scenario->buyerWithCart(quantity: '1'));
    app(OutboxRelay::class)->runOnce('relay-teste', 100);

    $delivery = ($this->deliveries)()->sole();
    expect($delivery->status)->toBe('pending')->and($delivery->attempts)->toBe(1)->and($delivery->last_error)->toContain('500');

    $this->artisan('webhooks:retry')->expectsOutputToContain('despachadas: 0')->assertSuccessful();
    app()->instance(Clock::class, new FrozenClock(now()->addMinutes(3)->toImmutable()));
    $this->artisan('webhooks:retry')->expectsOutputToContain('despachadas: 1')->assertSuccessful();

    $delivery = ($this->deliveries)()->sole();
    expect($delivery->status)->toBe('failed')->and($delivery->attempts)->toBe(2)->and($delivery->next_attempt_at)->toBeNull();

    // Terceira falha seguida desativa o endpoint.
    ($this->manage)(fn ($m) => $m->ping($endpoint->id));
    $stored = $this->scenario->system(fn () => WebhookEndpoint::query()->findOrFail($endpoint->id));
    expect($stored->is_active)->toBeFalse()->and($stored->disabled_reason)->toContain('3 falhas');

    // Reativado pela equipe, as falhas são zeradas.
    ($this->manage)(fn ($m) => $m->update($endpoint->id, $this->url, ['order.confirmed'], true));
    expect($this->scenario->system(fn () => WebhookEndpoint::query()->findOrFail($endpoint->id)->consecutive_failures))->toBe(0);
});

it('não entrega para endpoint inativo nem de outro distribuidor e troca o segredo', function (): void {
    Http::fake(['*' => Http::response('', 200)]);
    [$endpoint, $secret] = ($this->manage)(fn ($m) => $m->create($this->url, ['order.confirmed']));
    ($this->manage)(fn ($m) => $m->update($endpoint->id, $this->url, ['order.confirmed'], false));
    $other = OrderingScenario::create(stock: '5');
    Fx::place($other, $other->buyerWithCart(quantity: '1'));
    Fx::place($this->scenario, $this->scenario->buyerWithCart(quantity: '1'));
    app(OutboxRelay::class)->runOnce('relay-teste', 100);

    expect(($this->deliveries)())->toHaveCount(0);
    Http::assertNothingSent();

    $rotated = ($this->manage)(fn ($m) => $m->rotateSecret($endpoint->id));
    expect($rotated)->not->toBe($secret)->toStartWith('whsec_')
        ->and($this->scenario->system(fn () => (string) DB::table('webhook_endpoints')->value('secret_encrypted')))->not->toContain($rotated);

    ($this->manage)(fn ($m) => $m->delete($endpoint->id));
    expect($this->scenario->system(fn () => WebhookEndpoint::query()->count()))->toBe(0);
});

it('aceita IPv6 público, inclusive NAT64 para IPv4 público', function (string $ip): void {
    expect(App\Support\Integrations\WebhookUrlGuard::isPublic($ip))->toBeTrue();
})->with(['2606:4700:4700::1111', '64:ff9b::5db8:d822', '93.184.216.34']);

it('envia a entrega cuja primeira tentativa se perdeu depois do commit', function (): void {
    Http::fake(['93.184.216.34/*' => Http::response('', 204)]);
    [$endpoint] = ($this->manage)(fn ($m) => $m->create($this->url, ['order.confirmed']));
    // Entrega gravada sem a primeira tentativa (job perdido na fila).
    $id = $this->scenario->system(function () use ($endpoint): string {
        $delivery = new WebhookDelivery;
        $delivery->forceFill([
            'webhook_endpoint_id' => $endpoint->id, 'outbox_message_id' => (string) Illuminate\Support\Str::uuid7(), 'event_type' => 'order.confirmed',
            'payload' => ['id' => 'x', 'type' => 'order.confirmed', 'data' => []], 'status' => 'pending',
        ])->save();

        return $delivery->id;
    });

    $this->artisan('webhooks:retry')->expectsOutputToContain('despachadas: 0')->assertSuccessful();
    app()->instance(Clock::class, new FrozenClock(now()->addMinutes(3)->toImmutable()));
    $this->artisan('webhooks:retry')->expectsOutputToContain('despachadas: 1')->assertSuccessful();

    expect($this->scenario->system(fn () => WebhookDelivery::query()->findOrFail($id)->status))->toBe('delivered');
});

it('conta segredo ilegível como falha da tentativa, com nova espera', function (): void {
    Http::fake(['*' => Http::response('', 204)]);
    [$endpoint] = ($this->manage)(fn ($m) => $m->create($this->url, ['order.confirmed']));
    $this->scenario->system(fn () => WebhookEndpoint::query()->whereKey($endpoint->id)->update(['secret_encrypted' => 'cifrado-com-outra-chave']));

    $delivery = ($this->manage)(fn ($m) => $m->ping($endpoint->id));
    $stored = $this->scenario->system(fn () => WebhookDelivery::query()->findOrFail($delivery->id));

    expect($stored->status)->toBe('pending')->and($stored->attempts)->toBe(1)
        ->and($stored->last_error)->toContain('Segredo do endpoint ilegível')
        ->and($this->scenario->system(fn () => WebhookEndpoint::query()->findOrFail($endpoint->id)->consecutive_failures))->toBe(1);
    Http::assertNothingSent();
});

it('desativa endpoint cujo endereço deixou de ser aceito, mas não o reativa', function (): void {
    [$endpoint] = ($this->manage)(fn ($m) => $m->create($this->url, ['order.confirmed']));
    // O endereço passou a apontar para a rede interna depois do cadastro.
    $this->scenario->system(fn () => WebhookEndpoint::query()->whereKey($endpoint->id)->update(['url' => 'https://10.0.0.5/hook']));

    ($this->manage)(fn ($m) => $m->update($endpoint->id, 'https://10.0.0.5/hook', ['order.confirmed'], false));
    expect($this->scenario->system(fn () => WebhookEndpoint::query()->findOrFail($endpoint->id)->is_active))->toBeFalse()
        ->and(fn () => ($this->manage)(fn ($m) => $m->update($endpoint->id, 'https://10.0.0.5/hook', ['order.confirmed'], true)))
        ->toThrow(InvalidIntegration::class, 'rede interna');
});

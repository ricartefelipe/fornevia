<?php

declare(strict_types=1);

use App\Jobs\ProcessOutboxMessage;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxConsumers;
use App\Support\Outbox\OutboxRelay;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Builders\TenantScenario;
use Tests\Support\Outbox\RecordingConsumer;

/*
| Relay da outbox (ADR-008, ADR-026): reivindicação com lease e SKIP LOCKED,
| publicação na fila do consumidor, backoff, "dead", reenvio, retenção e
| situação, pelo papel fornevia_relay.
*/

beforeEach(function (): void {
    RecordingConsumer::reset();
    config(['outbox.consumers' => [RecordingConsumer::class]]);
    $this->tenant = TenantScenario::create();
    $this->record = fn (string $type = 'teste.evento', ?TenantScenario $tenant = null): string => asTenant(
        ($tenant ?? $this->tenant)->organization,
        static fn (): string => app(Outbox::class)->record($type, 'teste', (string) Str::uuid7(), ['valor' => 1]),
    );
    $this->relayDb = fn () => DB::connection(OutboxRelay::CONNECTION)->table('outbox_messages');
    $this->at = fn (string $interval) => app()->instance(Clock::class, new FrozenClock(now()->add($interval)->toImmutable()));
});

it('publica na fila do consumidor, de todos os tenants, e marca como publicada', function (): void {
    Queue::fake();
    RecordingConsumer::$queue = 'critical';
    $id = ($this->record)();
    $other = ($this->record)(tenant: TenantScenario::create());

    expect(app(OutboxRelay::class)->runOnce('relay-a'))->toBe(['claimed' => 2, 'published' => 2, 'failed' => 0, 'dead' => 0]);

    Queue::assertPushedOn('critical', ProcessOutboxMessage::class, fn (ProcessOutboxMessage $job): bool => $job->messageId === $id && $job->tenantId === $this->tenant->organization->id);
    Queue::assertPushed(ProcessOutboxMessage::class, fn (ProcessOutboxMessage $job): bool => $job->messageId === $other);

    $row = ($this->relayDb)()->where('id', $id)->first();
    expect($row->status)->toBe('published')
        ->and($row->attempts)->toBe(1)
        ->and($row->lease_owner)->toBeNull()
        ->and(app(OutboxRelay::class)->runOnce('relay-a')['claimed'])->toBe(0);
});

it('marca como publicada sem despachar a mensagem sem consumidor', function (): void {
    Queue::fake();
    $id = ($this->record)('tipo.sem_consumidor');

    expect(app(OutboxRelay::class)->runOnce('relay-a')['published'])->toBe(1)
        ->and(($this->relayDb)()->where('id', $id)->value('status'))->toBe('published');
    Queue::assertNothingPushed();
});

it('registra a falha de publicação, espera o backoff e para depois do máximo de tentativas', function (): void {
    config(['outbox.max_attempts' => 2, 'outbox.backoff_seconds' => [30, 600]]);
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('fila indisponível'));
    $relay = new OutboxRelay(app('db'), app(OutboxConsumers::class), $bus, app(Clock::class));
    $id = ($this->record)();

    expect($relay->runOnce('relay-a'))->toBe(['claimed' => 1, 'published' => 0, 'failed' => 1, 'dead' => 0])
        ->and($relay->runOnce('relay-a')['claimed'])->toBe(0);

    $row = ($this->relayDb)()->where('id', $id)->first();
    expect($row->status)->toBe('pending')->and($row->last_error)->toContain('fila indisponível');

    ($this->at)('PT31S');
    $relay = new OutboxRelay(app('db'), app(OutboxConsumers::class), $bus, app(Clock::class));

    expect($relay->runOnce('relay-a'))->toBe(['claimed' => 1, 'published' => 0, 'failed' => 0, 'dead' => 1])
        ->and(($this->relayDb)()->where('id', $id)->value('status'))->toBe('dead')
        ->and(DB::connection(OutboxRelay::CONNECTION)->table('outbox_failures')->where('outbox_message_id', $id)->pluck('stage')->all())->toBe(['publish', 'publish']);

    // Corrigida a causa, o reenvio devolve a mensagem à publicação.
    Queue::fake();
    $this->artisan('outbox:retry', ['message' => $id])->expectsOutputToContain('devolvidas à publicação: 1')->assertSuccessful();
    expect(app(OutboxRelay::class)->runOnce('relay-b')['published'])->toBe(1);
});

it('não registra falha de mensagem cujo lease passou a outro relay', function (): void {
    $id = ($this->record)();
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->andReturnUsing(function () use ($id): never {
        // Despacho lento: o lease venceu e outro relay reivindicou a mensagem.
        ($this->relayDb)()->where('id', $id)->update(['lease_owner' => 'relay-b']);

        throw new RuntimeException('tempo esgotado');
    });
    $relay = new OutboxRelay(app('db'), app(OutboxConsumers::class), $bus, app(Clock::class));

    expect($relay->runOnce('relay-a'))->toBe(['claimed' => 1, 'published' => 0, 'failed' => 0, 'dead' => 0])
        ->and(DB::connection(OutboxRelay::CONNECTION)->table('outbox_failures')->count())->toBe(0)
        ->and(($this->relayDb)()->where('id', $id)->value('lease_owner'))->toBe('relay-b');
});

it('não reivindica mensagem com lease vigente nem bloqueada por outro relay', function (): void {
    Queue::fake();
    $leased = ($this->record)();
    ($this->relayDb)()->where('id', $leased)->update(['lease_owner' => 'relay-caido', 'lease_expires_at' => now()->addMinute(), 'attempts' => 1]);
    $locked = ($this->record)();

    config(['database.connections.relay_concorrente' => config('database.connections.'.OutboxRelay::CONNECTION)]);
    $other = DB::connection('relay_concorrente');
    $other->beginTransaction();
    $other->select('select id from outbox_messages where id = ? for update', [$locked]);

    try {
        // Sem esperar o lock: a linha bloqueada fica para a próxima rodada.
        expect(app(OutboxRelay::class)->runOnce('relay-a')['claimed'])->toBe(0);
    } finally {
        $other->rollBack();
        DB::disconnect('relay_concorrente');
    }

    expect(app(OutboxRelay::class)->runOnce('relay-a')['claimed'])->toBe(1);

    // Lease vencido (relay que caiu no meio): a mensagem volta a ser publicada.
    ($this->at)('PT2M');
    expect(app(OutboxRelay::class)->runOnce('relay-a')['published'])->toBe(1)
        ->and(($this->relayDb)()->where('id', $leased)->value('attempts'))->toBe(2);
});

it('apaga as publicadas além da retenção e mantém as recentes e as sem novas tentativas', function (): void {
    Queue::fake();
    $old = ($this->record)();
    $recent = ($this->record)();
    $dead = ($this->record)();
    app(OutboxRelay::class)->runOnce('relay-a');
    ($this->relayDb)()->where('id', $old)->update(['published_at' => now()->subDays(31)]);
    ($this->relayDb)()->where('id', $dead)->update(['status' => 'dead', 'published_at' => null]);

    expect(app(OutboxRelay::class)->prune())->toBe(1)
        ->and(($this->relayDb)()->orderBy('id')->pluck('id')->all())->toEqualCanonicalizing([$recent, $dead]);
});

it('apaga em lotes até não restar publicada vencida', function (): void {
    Queue::fake();
    foreach (range(1, 5) as $ignored) {
        ($this->record)();
    }
    app(OutboxRelay::class)->runOnce('relay-a');
    ($this->relayDb)()->update(['published_at' => now()->subDays(31)]);

    expect(app(OutboxRelay::class)->prune(limit: 2))->toBe(5)
        ->and(($this->relayDb)()->count())->toBe(0);

    foreach (range(1, 3) as $ignored) {
        ($this->record)();
    }
    app(OutboxRelay::class)->runOnce('relay-a');
    ($this->relayDb)()->update(['published_at' => now()->subDays(31)]);

    expect(app(OutboxRelay::class)->prune(limit: 1, maxBatches: 2))->toBe(2);
});

it('mostra a situação da outbox', function (): void {
    ($this->record)();
    $dead = ($this->record)();
    ($this->relayDb)()->where('id', $dead)->update(['status' => 'dead']);

    $stats = app(OutboxRelay::class)->stats();
    expect($stats['pending'])->toBe(1)->and($stats['dead'])->toBe(1)->and($stats['leased'])->toBe(0)->and($stats['oldest_pending_seconds'])->toBeInt();

    $this->artisan('outbox:status', ['--json' => true])->expectsOutputToContain('"pending":1')->assertSuccessful();
    $this->artisan('outbox:status')->expectsOutputToContain('Pendentes')->assertSuccessful();
    $this->artisan('outbox:retry')->assertExitCode(2);
    $this->artisan('outbox:retry', ['message' => 'x'])->assertExitCode(2);
});

it('roda o relay pela linha de comando e encerra após o limite de rodadas', function (): void {
    Queue::fake();
    config(['outbox.idle_sleep_ms' => 10]);
    ($this->record)();

    $this->artisan('outbox:relay', ['--once' => true])->expectsOutputToContain('publicadas: 1')->assertSuccessful();
    $this->artisan('outbox:relay', ['--max-rounds' => 2, '--batch' => 5])->expectsOutputToContain('Reivindicadas: 0')->assertSuccessful();
});

it('não expõe a outbox de outro tenant nem ao contexto de comprador', function (): void {
    $id = ($this->record)();
    $other = TenantScenario::create();

    $buyer = $this->tenant->addBuyerUser($this->tenant->addBuyerCompany());

    expect(asTenant($other->organization, static fn (): int => DB::table('outbox_messages')->where('id', $id)->count()))->toBe(0)
        ->and(asTenant($this->tenant->organization, static fn (): int => DB::table('outbox_messages')->where('id', $id)->count()))->toBe(1)
        ->and($this->tenant->actingAs($buyer, static fn (): int => DB::table('outbox_messages')->count()))->toBe(0);
});

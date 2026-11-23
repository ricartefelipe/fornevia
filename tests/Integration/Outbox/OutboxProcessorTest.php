<?php

declare(strict_types=1);

use App\Jobs\ProcessOutboxMessage;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxProcessor;
use App\Support\Outbox\OutboxRelay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\TenantScenario;
use Tests\Support\Outbox\RecordingConsumer;

/*
| Consumo idempotente (ADR-026): cada consumidor age uma vez por mensagem,
| no contexto de sistema do tenant; falha desfaz o consumo e o efeito.
*/

beforeEach(function (): void {
    RecordingConsumer::reset();
    config(['outbox.consumers' => [RecordingConsumer::class]]);
    $this->tenant = TenantScenario::create();
    $this->tenantId = $this->tenant->organization->id;
    $this->message = asTenant($this->tenant->organization, static fn (): string => app(Outbox::class)->record('teste.evento', 'teste', (string) Str::uuid7(), ['valor' => 1]));
    $this->consumptions = fn (): int => asTenant($this->tenant->organization, fn (): int => DB::table('outbox_consumptions')->where('outbox_message_id', $this->message)->count());
});

it('age uma única vez por mensagem, mesmo entregue de novo', function (): void {
    $processor = app(OutboxProcessor::class);

    expect($processor->process($this->message, $this->tenantId))->toBe(1)
        ->and($processor->process($this->message, $this->tenantId))->toBe(0)
        ->and(RecordingConsumer::$handled)->toBe([$this->message])
        ->and(($this->consumptions)())->toBe(1);
});

it('desfaz o consumo quando o consumidor falha e consome na nova tentativa', function (): void {
    RecordingConsumer::$failuresLeft = 1;
    $processor = app(OutboxProcessor::class);

    expect(fn () => $processor->process($this->message, $this->tenantId))->toThrow(RuntimeException::class, 'falha simulada')
        ->and(($this->consumptions)())->toBe(0)
        ->and($processor->process($this->message, $this->tenantId))->toBe(1)
        ->and(RecordingConsumer::$handled)->toBe([$this->message]);
});

it('ignora mensagem já removida e mensagem de outro tenant', function (): void {
    $other = TenantScenario::create();

    expect(app(OutboxProcessor::class)->process($this->message, $other->organization->id))->toBe(0)
        ->and(app(OutboxProcessor::class)->process((string) Str::uuid7(), $this->tenantId))->toBe(0)
        ->and(RecordingConsumer::$handled)->toBe([]);
});

it('registra a falha do consumo quando as tentativas do job se esgotam', function (): void {
    (new ProcessOutboxMessage($this->message, $this->tenantId))->failed(new RuntimeException('consumidor indisponível'));

    $failure = DB::connection(OutboxRelay::CONNECTION)->table('outbox_failures')->where('outbox_message_id', $this->message)->first();
    expect($failure->stage)->toBe('consume')->and($failure->error)->toContain('consumidor indisponível');

    // Mensagem já removida: nada a registrar.
    (new ProcessOutboxMessage((string) Str::uuid7(), $this->tenantId))->failed(null);
    expect(DB::connection(OutboxRelay::CONNECTION)->table('outbox_failures')->count())->toBe(1);
});

it('processa pelo job publicado pelo relay', function (): void {
    config(['queue.default' => 'sync']);

    expect(app(OutboxRelay::class)->runOnce('relay-a')['published'])->toBe(1)
        ->and(RecordingConsumer::$handled)->toBe([$this->message]);
});

it('mantém o consumo somente para inclusão', function (): void {
    app(OutboxProcessor::class)->process($this->message, $this->tenantId);

    expect(fn () => asTenant($this->tenant->organization, fn () => DB::table('outbox_consumptions')->where('outbox_message_id', $this->message)->delete()))
        ->toThrow(Illuminate\Database\QueryException::class, 'permission denied');
});

<?php

declare(strict_types=1);

namespace App\Support\Outbox;

use App\Support\Clock\Clock;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Consumo de uma mensagem publicada pelo relay (ADR-026): cada consumidor
 * do tipo roda no contexto de sistema do tenant, em transação própria, e
 * registra o consumo antes de agir. Consumidor que já consumiu a mensagem
 * é pulado; falha desfaz o consumo e o efeito daquele consumidor.
 */
final readonly class OutboxProcessor
{
    public function __construct(
        private TenantDatabase $db,
        private OutboxConsumers $consumers,
        private Clock $clock,
    ) {}

    /**
     * @return int consumidores que agiram nesta execução
     */
    public function process(string $messageId, string $tenantId): int
    {
        $message = $this->db->run(ExecutionContext::system($tenantId), static function () use ($messageId): ?OutboxMessage {
            $row = DB::table('outbox_messages')->where('id', $messageId)->first();

            return $row === null ? null : OutboxMessage::fromRow($row);
        });

        // Mensagem já removida pela retenção: nada a fazer.
        if ($message === null) {
            return 0;
        }

        $correlation = $message->context['correlation_id'] ?? null;

        if (is_string($correlation) && $correlation !== '') {
            Context::add('correlation_id', $correlation);
        }

        $acted = 0;

        foreach ($this->consumers->for($message->type) as $consumer) {
            $acted += $this->db->run(ExecutionContext::system($tenantId), fn (): int => $this->consume($consumer, $message));
        }

        return $acted;
    }

    /**
     * Tentativas do job esgotadas: registra a falha e deixa a mensagem sem
     * novas tentativas ("dead"), visível em outbox:status, preservada pela
     * retenção e reenviável por outbox:retry (os consumidores que já agiram
     * são pulados na nova entrega).
     */
    public function recordFailure(string $messageId, string $tenantId, int $attempt, ?Throwable $error): void
    {
        $message = mb_substr($error === null ? 'Falha sem exceção.' : $error::class.': '.$error->getMessage(), 0, 2000);

        $this->db->run(ExecutionContext::system($tenantId), function () use ($messageId, $tenantId, $attempt, $message): void {
            if (DB::table('outbox_messages')->where('id', $messageId)->update(['status' => 'dead', 'last_error' => $message]) === 0) {
                return;
            }

            DB::table('outbox_failures')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'outbox_message_id' => $messageId,
                'attempt' => max(1, $attempt),
                'stage' => 'consume',
                'error' => $message,
                'created_at' => $this->clock->now(),
            ]);
        });
    }

    private function consume(OutboxConsumer $consumer, OutboxMessage $message): int
    {
        $inserted = DB::selectOne(
            'insert into outbox_consumptions (id, tenant_id, outbox_message_id, consumer, created_at) values (?, ?, ?, ?, ?)
             on conflict (tenant_id, outbox_message_id, consumer) do nothing
             returning id',
            [(string) Str::uuid7(), $message->tenantId, $message->id, $consumer->name(), $this->clock->now()],
        );

        if ($inserted === null) {
            return 0;
        }

        $consumer->handle($message);

        return 1;
    }
}

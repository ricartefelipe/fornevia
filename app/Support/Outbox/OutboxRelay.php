<?php

declare(strict_types=1);

namespace App\Support\Outbox;

use App\Jobs\ProcessOutboxMessage;
use App\Support\Clock\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Throwable;

/**
 * Relay da outbox (ADR-008, ADR-026), na conexão do papel fornevia_relay,
 * que lê e atualiza a outbox de todos os tenants e nada além dela.
 *
 * Cada rodada reivindica um lote com FOR UPDATE SKIP LOCKED numa transação
 * curta (lease e tentativa) e, fora dela, despacha um job por mensagem e
 * marca a publicação, conferindo o dono do lease. Relay que cai no meio
 * deixa o lease vencer e a mensagem volta ao próximo lote: entrega pelo
 * menos uma vez, com a idempotência no consumidor.
 */
final readonly class OutboxRelay
{
    public const string CONNECTION = 'pgsql_relay';

    public function __construct(
        private DatabaseManager $database,
        private OutboxConsumers $consumers,
        private Dispatcher $bus,
        private Clock $clock,
    ) {}

    public static function owner(): string
    {
        return mb_substr((gethostname() ?: 'relay').':'.getmypid().':'.Str::lower(Str::random(6)), 0, 80);
    }

    /**
     * @return array{claimed: int, published: int, failed: int, dead: int}
     */
    public function runOnce(string $owner, ?int $limit = null): array
    {
        $result = ['claimed' => 0, 'published' => 0, 'failed' => 0, 'dead' => 0];

        foreach ($this->claim($owner, $limit ?? (int) config('outbox.batch', 100)) as $message) {
            $result['claimed']++;

            try {
                $queue = $this->consumers->queueFor($message->type);

                if ($queue !== null) {
                    $this->bus->dispatch((new ProcessOutboxMessage($message->id, $message->tenant_id))->onQueue($queue));
                }

                $this->markPublished($message->id, $owner);
                $result['published']++;
            } catch (Throwable $e) {
                $dead = $this->markFailed($message->id, $message->tenant_id, $owner, $message->attempts, $e);
                $result[$dead ? 'dead' : 'failed']++;
            }
        }

        return $result;
    }

    /**
     * Apaga mensagens publicadas além do prazo de retenção (falhas e
     * consumos vão junto). Mensagens "dead" ficam.
     */
    public function prune(int $limit = 1000): int
    {
        $before = $this->clock->now()->subDays((int) config('outbox.retention_days', 30));

        return $this->connection()->delete(
            "delete from outbox_messages where id in (
                select id from outbox_messages where status = 'published' and published_at < ? order by published_at limit ?)",
            [$before, $limit],
        );
    }

    /**
     * Devolve à fila uma mensagem "dead" (ou todas, sem id), com as
     * tentativas zeradas.
     */
    public function retry(?string $messageId = null): int
    {
        $now = $this->clock->now();

        return $this->connection()->update(
            "update outbox_messages set status = 'pending', attempts = 0, available_at = ?, lease_owner = null, lease_expires_at = null
              where status = 'dead'".($messageId !== null ? ' and id = ?' : ''),
            $messageId !== null ? [$now, $messageId] : [$now],
        );
    }

    /**
     * @return array{pending: int, oldest_pending_seconds: int|null, leased: int, dead: int}
     */
    public function stats(): array
    {
        $now = $this->clock->now();
        $row = $this->connection()->selectOne(
            "select count(*) filter (where status = 'pending') as pending,
                    min(created_at) filter (where status = 'pending') as oldest,
                    count(*) filter (where status = 'pending' and lease_expires_at > ?) as leased,
                    count(*) filter (where status = 'dead') as dead
               from outbox_messages",
            [$now],
        );

        return [
            'pending' => (int) $row->pending,
            'oldest_pending_seconds' => $row->oldest !== null ? max(0, (int) CarbonImmutable::parse((string) $row->oldest)->diffInSeconds($now)) : null,
            'leased' => (int) $row->leased,
            'dead' => (int) $row->dead,
        ];
    }

    /**
     * @return list<object{id: string, tenant_id: string, type: string, attempts: int}>
     */
    private function claim(string $owner, int $limit): array
    {
        $now = $this->clock->now();
        $connection = $this->connection();

        /** @var list<object{id: string, tenant_id: string, type: string, attempts: int}> $rows */
        $rows = $connection->transaction(static fn (): array => $connection->select(
            "update outbox_messages m
                set lease_owner = ?, lease_expires_at = ?, attempts = m.attempts + 1
               from (select id from outbox_messages
                      where status = 'pending' and available_at <= ? and (lease_expires_at is null or lease_expires_at <= ?)
                      order by available_at, id
                      limit ?
                      for update skip locked) claimed
              where m.id = claimed.id
          returning m.id, m.tenant_id, m.type, m.attempts",
            [$owner, $now->addSeconds((int) config('outbox.lease_seconds', 60)), $now, $now, max(1, $limit)],
        ));

        return $rows;
    }

    private function markPublished(string $id, string $owner): void
    {
        $this->connection()->update(
            "update outbox_messages set status = 'published', published_at = ?, lease_owner = null, lease_expires_at = null, last_error = null
              where id = ? and lease_owner = ? and status = 'pending'",
            [$this->clock->now(), $id, $owner],
        );
    }

    /** Registra a falha e agenda a próxima tentativa; true quando a mensagem ficou "dead". */
    private function markFailed(string $id, string $tenantId, string $owner, int $attempts, Throwable $error): bool
    {
        $now = $this->clock->now();
        $dead = $attempts >= (int) config('outbox.max_attempts', 10);
        /** @var list<int> $backoff */
        $backoff = array_values((array) config('outbox.backoff_seconds', [60]));
        $delay = $backoff[min($attempts, count($backoff)) - 1] ?? 60;
        $message = mb_substr($error::class.': '.$error->getMessage(), 0, 2000);
        $connection = $this->connection();

        $connection->transaction(static function () use ($connection, $id, $tenantId, $owner, $attempts, $now, $dead, $delay, $message): void {
            $connection->insert(
                'insert into outbox_failures (id, tenant_id, outbox_message_id, attempt, stage, error, created_at) values (?, ?, ?, ?, ?, ?, ?)',
                [(string) Str::uuid7(), $tenantId, $id, $attempts, 'publish', $message, $now],
            );
            $connection->update(
                'update outbox_messages set status = ?, available_at = ?, lease_owner = null, lease_expires_at = null, last_error = ?
                  where id = ? and lease_owner = ?',
                [$dead ? 'dead' : 'pending', $now->addSeconds($delay), $message, $id, $owner],
            );
        });

        return $dead;
    }

    private function connection(): ConnectionInterface
    {
        return $this->database->connection(self::CONNECTION);
    }
}

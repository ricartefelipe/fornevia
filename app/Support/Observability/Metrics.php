<?php

declare(strict_types=1);

namespace App\Support\Observability;

use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Log;
use PDOException;
use Throwable;

/**
 * Contadores e histogramas (RQ-121) guardados no Redis, somados entre todos
 * os processos (web, workers, agendador). Gravar métrica nunca derruba a
 * operação: a conexão tem tempo limite curto e, depois de uma falha, as
 * gravações do processo ficam suspensas por alguns segundos, com aviso no log.
 *
 * Séries: requisições por tipo e classe de status, duração por tipo
 * (histograma), conflitos de banco por motivo e jobs com falha por fila.
 */
final class Metrics
{
    public const string KEY = 'fornevia:metrics';

    /** Conexão própria, com tempo limite curto (config/database.php). */
    public const string CONNECTION = 'metrics';

    /** Depois de uma falha, as gravações deste processo são suspensas por este tempo. */
    public const int SUSPEND_SECONDS = 30;

    private static ?float $suspendedUntil = null;

    public function __construct(private readonly RedisManager $redis) {}

    public function observeRequest(string $kind, int $status, float $seconds): void
    {
        $class = intdiv(max(100, min(599, $status)), 100).'xx';
        $increments = ["http_requests_total|kind={$kind},status={$class}" => 1, "http_request_duration_seconds_count|kind={$kind}" => 1];

        foreach ((array) config('observability.latency_buckets') as $bucket) {
            if ($seconds <= (float) $bucket) {
                $increments["http_request_duration_seconds_bucket|kind={$kind},le=".self::number((float) $bucket)] = 1;
            }
        }

        $increments["http_request_duration_seconds_bucket|kind={$kind},le=+Inf"] = 1;
        $this->write($increments, ["http_request_duration_seconds_sum|kind={$kind}" => $seconds]);
    }

    /** Conflito de concorrência no banco: deadlock, serialização ou lock indisponível. */
    public function conflict(string $reason): void
    {
        $this->write(["db_conflicts_total|reason={$reason}" => 1]);
    }

    /**
     * Conta a exceção reportada quando ela, ou alguma da cadeia, é conflito
     * de concorrência no banco. Cobre a QueryException direta e a
     * DeadlockException que o Laravel lança em transações aninhadas.
     */
    public function recordConflictFrom(Throwable $e): void
    {
        $reason = self::conflictReason($e);

        if ($reason !== null) {
            $this->conflict($reason);
        }
    }

    public static function conflictReason(Throwable $e): ?string
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            // QueryException e DeadlockException estendem PDOException.
            $state = $current instanceof PDOException
                ? (string) ($current->errorInfo[0] ?? $current->getCode())
                : '';
            $reason = match ($state) {
                '40P01' => 'deadlock',
                '40001' => 'serialization',
                '55P03' => 'lock_not_available',
                default => null,
            };

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    public function jobFailed(string $queue): void
    {
        $this->write(['jobs_failed_total|queue='.self::label($queue) => 1]);
    }

    /**
     * @return array<string, float> série => valor
     */
    public function all(): array
    {
        /** @var array<string, string> $raw */
        $raw = (array) $this->redis->connection(self::CONNECTION)->command('hgetall', [self::KEY]);

        return array_map('floatval', $raw);
    }

    public function reset(): void
    {
        $this->redis->connection(self::CONNECTION)->command('del', [self::KEY]);
        self::$suspendedUntil = null;
    }

    public static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    /** Valor de rótulo sem aspas, vírgulas ou quebras (o nome da fila vem da configuração). */
    public static function label(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_.:-]/', '_', $value);
    }

    /**
     * @param  array<string, int>  $increments
     * @param  array<string, float>  $floats
     */
    private function write(array $increments, array $floats = []): void
    {
        if (self::$suspendedUntil !== null && microtime(true) < self::$suspendedUntil) {
            return;
        }

        try {
            $connection = $this->redis->connection(self::CONNECTION);
            $connection->pipeline(static function ($pipe) use ($increments, $floats): void {
                foreach ($increments as $field => $by) {
                    $pipe->hincrby(self::KEY, $field, $by);
                }

                foreach ($floats as $field => $by) {
                    $pipe->hincrbyfloat(self::KEY, $field, $by);
                }
            });
            self::$suspendedUntil = null;
        } catch (Throwable $e) {
            // Redis indisponível não pode somar latência a cada requisição.
            self::$suspendedUntil = microtime(true) + self::SUSPEND_SECONDS;
            Log::warning('Métrica não registrada; gravação suspensa por '.self::SUSPEND_SECONDS.' s.', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }
    }
}

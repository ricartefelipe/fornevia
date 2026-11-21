<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Orquestra cenários concorrentes com processos PHP separados e
 * sincronização determinística:
 *
 * - gate(): o teste segura um advisory lock; workers esperam nele;
 * - waitUntilWaitingAt(): confirma (pg_locks) que N workers chegaram ao portão;
 * - waitUntilBlockedOnLock(): confirma (pg_stat_activity) que um worker está
 *   bloqueado esperando lock de linha de outra transação.
 *
 * Os dados de cenário precisam estar commitados antes de iniciar workers
 * (a suíte não usa transação envolvendo o teste).
 */
final class ConcurrencyHarness
{
    private const string CONTROL = 'concurrency_control';

    private ConnectionInterface $control;

    /** @var list<Worker> */
    private array $workers = [];

    /** @var list<Gate> */
    private array $gates = [];

    public function __construct()
    {
        config(['database.connections.'.self::CONTROL => config('database.connections.pgsql')]);
        DB::purge(self::CONTROL);
        $this->control = DB::connection(self::CONTROL);
    }

    public function gate(string $name): Gate
    {
        return $this->gates[] = new Gate($this->control, $name);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function start(string $scenario, array $args, string $name): Worker
    {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/Concurrency/run-worker.php'), $scenario, json_encode($args, JSON_THROW_ON_ERROR)],
            base_path(),
            $this->environment($name),
            null,
            90,
        );
        $process->start();

        return $this->workers[] = new Worker($name, $process);
    }

    public function waitUntilWaitingAt(string $gate, int $count, float $timeoutSeconds = 20.0): void
    {
        $this->until(
            fn (): bool => $this->waitingAt($gate) >= $count,
            "{$count} worker(s) no portão {$gate}",
            $timeoutSeconds,
        );
    }

    public function waitUntilBlockedOnLock(string $workerName, float $timeoutSeconds = 20.0): void
    {
        $this->until(
            fn (): bool => (int) $this->control->selectOne(
                "select count(*) as total from pg_stat_activity where application_name = ? and wait_event_type = 'Lock'",
                [self::applicationName($workerName)],
            )->total > 0,
            "worker {$workerName} bloqueado por lock",
            $timeoutSeconds,
        );
    }

    /**
     * Espera o worker ficar bloqueado aguardando o lock de uma linha da
     * tabela informada (o processo em espera detém o lock "tuple" da linha
     * e aguarda a transação que a bloqueou). Distingue, por exemplo, a
     * espera pelo pedido da espera por um nível de aprovação.
     */
    public function waitUntilBlockedOnRow(string $workerName, string $table, float $timeoutSeconds = 20.0): void
    {
        $this->until(
            fn (): bool => (int) $this->control->selectOne(
                "select count(*) as total
                   from pg_stat_activity a
                   join pg_locks l on l.pid = a.pid and l.locktype = 'tuple' and l.relation = to_regclass(?)
                  where a.application_name = ? and a.wait_event_type = 'Lock'",
                [$table, self::applicationName($workerName)],
            )->total > 0,
            "worker {$workerName} bloqueado por linha de {$table}",
            $timeoutSeconds,
        );
    }

    public function shutdown(): void
    {
        foreach ($this->gates as $gate) {
            $gate->release();
        }

        foreach ($this->workers as $worker) {
            $worker->stop();
        }

        DB::disconnect(self::CONTROL);
    }

    public static function applicationName(string $workerName): string
    {
        return 'fornevia-worker-'.$workerName;
    }

    private function waitingAt(string $gate): int
    {
        return (int) $this->control->selectOne(
            "select count(*) as total from pg_locks where locktype = 'advisory' and classid = ? and objid = ? and not granted",
            [Gate::NAMESPACE, Gate::key($gate)],
        )->total;
    }

    private function until(callable $condition, string $description, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }

            foreach ($this->workers as $worker) {
                if (! $worker->isRunning()) {
                    // Um worker que terminou antes da hora deixa o motivo no resultado.
                    $result = $worker->result();
                    if (! $result['ok']) {
                        throw new RuntimeException("Worker {$worker->name} falhou antes de {$description}: ".json_encode($result));
                    }
                }
            }

            usleep(20_000);
        }

        throw new RuntimeException("Tempo esgotado aguardando {$description}.");
    }

    /**
     * @return array<string, string>
     */
    private function environment(string $workerName): array
    {
        $keys = ['APP_ENV', 'APP_KEY', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
            'DB_OWNER_USERNAME', 'DB_OWNER_PASSWORD', 'DB_RELAY_USERNAME', 'DB_RELAY_PASSWORD', 'REDIS_HOST', 'REDIS_PORT',
            'REDIS_PREFIX', 'CACHE_STORE', 'QUEUE_CONNECTION', 'MAIL_MAILER', 'LOG_CHANNEL', 'SESSION_DRIVER',
            'PASSWORD_BREACH_CHECK', 'BCRYPT_ROUNDS', 'PAYMENTS_GATEWAY'];
        $env = [];

        foreach ($keys as $key) {
            $value = $_ENV[$key] ?? getenv($key);

            if (is_string($value)) {
                $env[$key] = $value;
            }
        }

        $env['DB_APPLICATION_NAME'] = self::applicationName($workerName);

        return $env;
    }
}

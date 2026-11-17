<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Illuminate\Database\ConnectionInterface;

/**
 * Lado do worker: atravessa um portão (lock compartilhado adquirido e
 * liberado), em conexão própria, sem interferir na transação de negócio.
 */
final readonly class WorkerGate
{
    public function __construct(private ConnectionInterface $connection) {}

    public function pass(?string $name): void
    {
        if ($name === null || $name === '') {
            return;
        }

        $key = Gate::key($name);
        $this->connection->select('select pg_advisory_lock_shared(?, ?)', [Gate::NAMESPACE, $key]);
        $this->connection->select('select pg_advisory_unlock_shared(?, ?)', [Gate::NAMESPACE, $key]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Illuminate\Database\ConnectionInterface;

/**
 * Portão de sincronização mantido pelo processo de teste: um advisory lock
 * exclusivo. Workers que chamam WorkerGate::pass() com o mesmo nome ficam
 * bloqueados até release().
 */
final class Gate
{
    public const int NAMESPACE = 424242;

    private bool $held = true;

    public function __construct(private readonly ConnectionInterface $control, public readonly string $name)
    {
        $control->select('select pg_advisory_lock(?, ?)', [self::NAMESPACE, self::key($name)]);
    }

    public function release(): void
    {
        if ($this->held) {
            $this->control->select('select pg_advisory_unlock(?, ?)', [self::NAMESPACE, self::key($this->name)]);
            $this->held = false;
        }
    }

    public static function key(string $name): int
    {
        return crc32($name) & 0x7FFFFFFF;
    }
}

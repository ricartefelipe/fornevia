<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Processo PHP independente executando um cenário (conexão própria com o
 * banco, sem estado compartilhado com o processo de teste).
 */
final class Worker
{
    /** @var array{ok: bool, data?: array<string, mixed>, error?: string, message?: string}|null */
    private ?array $result = null;

    public function __construct(public readonly string $name, private readonly Process $process) {}

    /**
     * @return array{ok: bool, data?: array<string, mixed>, error?: string, message?: string}
     */
    public function result(): array
    {
        if ($this->result !== null) {
            return $this->result;
        }

        $this->process->wait();
        $lines = array_values(array_filter(explode(PHP_EOL, trim($this->process->getOutput()))));
        $last = $lines === [] ? '' : $lines[array_key_last($lines)];

        try {
            /** @var array{ok: bool, data?: array<string, mixed>, error?: string, message?: string} $decoded */
            $decoded = json_decode($last, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException(sprintf(
                "Worker %s não retornou resultado (exit %s).\nSaída: %s\nErros: %s",
                $this->name,
                (string) $this->process->getExitCode(),
                $this->process->getOutput(),
                $this->process->getErrorOutput(),
            ));
        }

        return $this->result = $decoded;
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function stop(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop(1);
        }
    }
}

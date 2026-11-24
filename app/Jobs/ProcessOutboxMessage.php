<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Outbox\OutboxProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processa uma mensagem da outbox publicada pelo relay (ADR-026). Tentativas
 * limitadas com espera crescente; esgotadas, a falha fica registrada em
 * outbox_failures.
 */
final class ProcessOutboxMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(
        public readonly string $messageId,
        public readonly string $tenantId,
    ) {}

    public function handle(OutboxProcessor $processor): void
    {
        $processor->process($this->messageId, $this->tenantId);
    }

    public function failed(?Throwable $exception): void
    {
        app(OutboxProcessor::class)->recordFailure($this->messageId, $this->tenantId, $this->attempts(), $exception);
    }
}

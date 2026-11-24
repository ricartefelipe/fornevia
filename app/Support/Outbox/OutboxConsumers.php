<?php

declare(strict_types=1);

namespace App\Support\Outbox;

use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Consumidores registrados em config/outbox.php e a fila de cada tipo de
 * mensagem: `critical` prevalece sobre as demais quando mais de um
 * consumidor trata o mesmo tipo.
 */
final class OutboxConsumers
{
    /** @var list<OutboxConsumer>|null */
    private ?array $resolved = null;

    public function __construct(private readonly Container $container) {}

    /**
     * @return list<OutboxConsumer>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $consumers = [];

        foreach ((array) config('outbox.consumers', []) as $class) {
            $consumer = $this->container->make((string) $class);

            if (! $consumer instanceof OutboxConsumer) {
                throw new LogicException("Consumidor da outbox inválido: {$class}.");
            }

            $consumers[] = $consumer;
        }

        return $this->resolved = $consumers;
    }

    /**
     * @return list<OutboxConsumer>
     */
    public function for(string $type): array
    {
        return array_values(array_filter($this->all(), static fn (OutboxConsumer $c): bool => in_array($type, $c->types(), true)));
    }

    /** Fila do tipo; null quando nenhum consumidor o trata. */
    public function queueFor(string $type): ?string
    {
        $queues = array_map(static fn (OutboxConsumer $c): string => $c->queue(), $this->for($type));

        if ($queues === []) {
            return null;
        }

        return in_array('critical', $queues, true) ? 'critical' : $queues[0];
    }
}

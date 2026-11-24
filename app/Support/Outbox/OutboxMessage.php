<?php

declare(strict_types=1);

namespace App\Support\Outbox;

use Carbon\CarbonImmutable;
use stdClass;

/**
 * Mensagem da outbox lida para consumo.
 */
final readonly class OutboxMessage
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context  correlation_id, actor_user_id, actor_api_client_id
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $type,
        public int $payloadVersion,
        public array $payload,
        public array $context,
        public string $aggregateType,
        public string $aggregateId,
        public CarbonImmutable $createdAt,
    ) {}

    public static function fromRow(stdClass $row): self
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $context */
        $context = json_decode((string) $row->context, true, flags: JSON_THROW_ON_ERROR);

        return new self(
            (string) $row->id,
            (string) $row->tenant_id,
            (string) $row->type,
            (int) $row->payload_version,
            $payload,
            $context,
            (string) $row->aggregate_type,
            (string) $row->aggregate_id,
            CarbonImmutable::parse((string) $row->created_at),
        );
    }

    /** Quem causou o evento, quando foi uma pessoa. */
    public function actorUserId(): ?string
    {
        $actor = $this->context['actor_user_id'] ?? null;

        return is_string($actor) && $actor !== '' ? $actor : null;
    }

    public function string(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Outbox;

use App\Support\Tenancy\CurrentContext;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transactional Outbox (ADR-008): registra o evento na MESMA transação da
 * alteração de negócio. A publicação é feita depois pelo relay, que tolera
 * falhas entre o commit e a publicação (entrega pelo menos uma vez).
 */
final readonly class Outbox
{
    public function __construct(private CurrentContext $context) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $type, string $aggregateType, string $aggregateId, array $payload, int $payloadVersion = 1): string
    {
        $execution = $this->context->require();
        $id = (string) Str::uuid7();

        DB::table('outbox_messages')->insert([
            'id' => $id,
            'tenant_id' => $execution->requireTenantId(),
            'type' => $type,
            'payload_version' => $payloadVersion,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'context' => json_encode(array_filter([
                'correlation_id' => Context::get('correlation_id'),
                'actor_user_id' => $execution->userId,
                'actor_api_client_id' => $execution->apiClientId,
            ]), JSON_THROW_ON_ERROR),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'status' => 'pending',
            'available_at' => now(),
            'created_at' => now(),
        ]);

        return $id;
    }
}

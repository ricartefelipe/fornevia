<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Jobs\DeliverWebhook;
use App\Models\Integrations\WebhookEndpoint;
use App\Support\Integrations\WebhookEvents;
use App\Support\Outbox\OutboxConsumer;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Consumidor da outbox que agenda os webhooks (RF-111): uma entrega por
 * endpoint ativo que assina o evento, com id estável (a mesma entrega nas
 * novas tentativas), na fila de integrações.
 */
final readonly class WebhookFanout implements OutboxConsumer
{
    public function name(): string
    {
        return 'webhooks';
    }

    public function queue(): string
    {
        return 'integrations';
    }

    public function types(): array
    {
        return WebhookEvents::types();
    }

    public function handle(OutboxMessage $message): void
    {
        $endpoints = WebhookEndpoint::query()->where('is_active', true)->get()->filter(static fn (WebhookEndpoint $e): bool => $e->subscribes($message->type));
        $data = WebhookEvents::publicData($message->type, $message->payload);

        foreach ($endpoints as $endpoint) {
            $inserted = DB::selectOne(
                "insert into webhook_deliveries (id, tenant_id, webhook_endpoint_id, outbox_message_id, event_type, payload, status, attempts, created_at, updated_at)
                 values (?, ?, ?, ?, ?, ?::jsonb, 'pending', 0, now(), now())
                 on conflict (tenant_id, webhook_endpoint_id, outbox_message_id) do nothing
                 returning id",
                [(string) Str::uuid7(), $message->tenantId, $endpoint->id, $message->id, $message->type, json_encode([
                    'id' => $message->id,
                    'type' => $message->type,
                    'created_at' => $message->createdAt->utc()->toIso8601String(),
                    'data' => $data,
                ], JSON_THROW_ON_ERROR)],
            );

            if ($inserted !== null) {
                DeliverWebhook::dispatch((string) $inserted->id, $message->tenantId)->onQueue('integrations')->afterCommit();
            }
        }
    }
}

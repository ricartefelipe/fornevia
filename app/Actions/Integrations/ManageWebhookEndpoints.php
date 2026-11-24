<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Domain\Integrations\Exceptions\InvalidIntegration;
use App\Enums\Identity\Permission;
use App\Jobs\DeliverWebhook;
use App\Models\Integrations\WebhookDelivery;
use App\Models\Integrations\WebhookEndpoint;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Integrations\WebhookEvents;
use App\Support\Integrations\WebhookUrlGuard;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Endpoints de webhook do distribuidor (RF-111), por quem tem
 * integrations.manage: URL conferida contra SSRF, eventos da lista, segredo
 * gerado aqui e mostrado uma única vez (guardado cifrado), troca do
 * segredo, ativação (zera as falhas), exclusão e envio de teste.
 */
final readonly class ManageWebhookEndpoints
{
    public function __construct(private Access $access, private AuditRecorder $audit, private Clock $clock) {}

    /**
     * @param  list<string>  $events
     * @return array{0: WebhookEndpoint, 1: string} endpoint e segredo
     */
    public function create(string $url, array $events): array
    {
        $this->access->authorize(Permission::IntegrationsManage);
        $url = trim($url);
        WebhookUrlGuard::resolve($url);
        $events = self::events($events);
        $secret = 'whsec_'.Str::random(40);

        $endpoint = new WebhookEndpoint;
        $endpoint->forceFill(['url' => $url, 'events' => $events, 'secret_encrypted' => Crypt::encryptString($secret), 'created_by_user_id' => $this->access->userId()])->save();
        $this->audit->record('webhook_endpoint.created', $endpoint, ['url' => $url, 'events' => $events]);

        return [$endpoint, $secret];
    }

    /**
     * @param  list<string>  $events
     */
    public function update(mixed $endpointId, string $url, array $events, bool $active): WebhookEndpoint
    {
        $this->access->authorize(Permission::IntegrationsManage);
        $url = trim($url);
        WebhookUrlGuard::resolve($url);
        $events = self::events($events);

        return DB::transaction(function () use ($endpointId, $url, $events, $active): WebhookEndpoint {
            $endpoint = $this->find($endpointId);
            $reactivated = $active && ! $endpoint->is_active;
            $endpoint->forceFill([
                'url' => $url, 'events' => $events, 'is_active' => $active,
                'consecutive_failures' => $reactivated ? 0 : $endpoint->consecutive_failures,
                'disabled_at' => $active ? null : ($endpoint->disabled_at ?? $this->clock->now()),
                'disabled_reason' => $active ? null : ($endpoint->disabled_reason ?? 'Desativado pela equipe.'),
            ])->save();
            $this->audit->record('webhook_endpoint.updated', $endpoint, ['url' => $url, 'events' => $events, 'active' => $active]);

            return $endpoint;
        });
    }

    public function rotateSecret(mixed $endpointId): string
    {
        $this->access->authorize(Permission::IntegrationsManage);
        $secret = 'whsec_'.Str::random(40);
        $endpoint = $this->find($endpointId);
        $endpoint->forceFill(['secret_encrypted' => Crypt::encryptString($secret)])->save();
        $this->audit->record('webhook_endpoint.secret_rotated', $endpoint);

        return $secret;
    }

    public function delete(mixed $endpointId): void
    {
        $this->access->authorize(Permission::IntegrationsManage);
        $endpoint = $this->find($endpointId);
        $this->audit->record('webhook_endpoint.deleted', $endpoint, ['url' => $endpoint->url]);
        $endpoint->delete();
    }

    /** Envia um evento "ping" ao endpoint, pelo mesmo caminho das entregas. */
    public function ping(mixed $endpointId): WebhookDelivery
    {
        $this->access->authorize(Permission::IntegrationsManage);
        $endpoint = $this->find($endpointId);
        $id = (string) Str::uuid7();
        $delivery = new WebhookDelivery;
        $delivery->forceFill([
            'webhook_endpoint_id' => $endpoint->id,
            'outbox_message_id' => $id,
            'event_type' => 'ping',
            'payload' => ['id' => $id, 'type' => 'ping', 'created_at' => $this->clock->now()->utc()->toIso8601String(), 'data' => []],
            'status' => 'pending',
        ])->save();
        DeliverWebhook::dispatch($delivery->id, $delivery->tenant_id)->onQueue('integrations')->afterCommit();

        return $delivery;
    }

    private function find(mixed $endpointId): WebhookEndpoint
    {
        return WebhookEndpoint::query()->findOrFail(UntrustedId::uuid($endpointId, WebhookEndpoint::class));
    }

    /**
     * @param  list<string>  $events
     * @return list<string>
     */
    private static function events(array $events): array
    {
        $valid = array_values(array_unique(array_intersect($events, WebhookEvents::types())));

        if ($valid === [] || count($valid) !== count(array_unique($events))) {
            throw new InvalidIntegration('Escolha ao menos um evento da lista.', ['field' => 'events']);
        }

        sort($valid);

        return $valid;
    }
}

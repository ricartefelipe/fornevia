<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Integrations\WebhookDelivery;
use App\Models\Integrations\WebhookEndpoint;
use App\Services\Integrations\WebhookSender;
use App\Support\Clock\Clock;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Uma tentativa de entrega de webhook (RF-111). Falha agenda a próxima com
 * espera crescente até o máximo de tentativas; falhas seguidas desativam o
 * endpoint; sucesso zera a contagem. O envio acontece fora de transação.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $deliveryId, public readonly string $tenantId) {}

    public function handle(TenantDatabase $db, WebhookSender $sender, Clock $clock): void
    {
        $context = ExecutionContext::system($this->tenantId);
        [$delivery, $endpoint] = $db->run($context, function () use ($clock): array {
            // Reserva a tentativa: o reagendamento não a despacha de novo enquanto ela roda.
            $claimed = WebhookDelivery::query()->whereKey($this->deliveryId)->where('status', 'pending')
                ->where(static fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $clock->now()))
                ->update(['next_attempt_at' => $clock->now()->addMinutes(10)]);
            $delivery = $claimed === 1 ? WebhookDelivery::query()->find($this->deliveryId) : null;

            return [$delivery, $delivery !== null ? WebhookEndpoint::query()->find($delivery->webhook_endpoint_id) : null];
        });

        if ($delivery === null || $endpoint === null || $delivery->status !== 'pending') {
            return;
        }

        if (! $endpoint->is_active) {
            $db->run($context, fn () => WebhookDelivery::query()->whereKey($delivery->id)->update(['status' => 'failed', 'last_error' => 'Endpoint desativado.', 'next_attempt_at' => null]));

            return;
        }

        $result = $sender->send($endpoint, $delivery);
        $now = $clock->now();

        $db->run($context, function () use ($delivery, $endpoint, $result, $now): void {
            $delivery = WebhookDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
            $endpoint = WebhookEndpoint::query()->lockForUpdate()->findOrFail($endpoint->id);
            $attempts = $delivery->attempts + 1;

            if ($result['ok']) {
                $delivery->forceFill(['status' => 'delivered', 'attempts' => $attempts, 'response_status' => $result['status'], 'last_error' => null, 'delivered_at' => $now, 'next_attempt_at' => null])->save();
                $endpoint->forceFill(['consecutive_failures' => 0])->save();

                return;
            }

            /** @var list<int> $backoff */
            $backoff = array_values((array) config('integrations.webhooks.backoff_seconds', [60]));
            $exhausted = $attempts >= (int) config('integrations.webhooks.max_attempts', 8);
            $delivery->forceFill([
                'status' => $exhausted ? 'failed' : 'pending',
                'attempts' => $attempts,
                'response_status' => $result['status'],
                'last_error' => $result['error'] !== null ? mb_substr($result['error'], 0, 500) : null,
                'next_attempt_at' => $exhausted ? null : $now->addSeconds($backoff[min($attempts, count($backoff)) - 1] ?? 3600),
            ])->save();

            $failures = $endpoint->consecutive_failures + 1;
            $disable = $failures >= (int) config('integrations.webhooks.disable_after_failures', 20);
            $endpoint->forceFill([
                'consecutive_failures' => $failures,
                'is_active' => $disable ? false : $endpoint->is_active,
                'disabled_at' => $disable ? $now : $endpoint->disabled_at,
                'disabled_reason' => $disable ? "Desativado após {$failures} falhas seguidas." : $endpoint->disabled_reason,
            ])->save();
        });
    }
}

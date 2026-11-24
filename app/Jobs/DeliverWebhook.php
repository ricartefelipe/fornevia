<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Integrations\WebhookDelivery;
use App\Models\Integrations\WebhookEndpoint;
use App\Services\Integrations\WebhookSender;
use App\Support\Clock\Clock;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Carbon\CarbonImmutable;
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
        [$delivery, $endpoint] = $db->run($context, fn (): array => $this->claim($clock));

        if ($delivery === null || $endpoint === null) {
            return;
        }

        if (! $endpoint->is_active) {
            $db->run($context, fn () => WebhookDelivery::query()->whereKey($delivery->id)->update(['status' => 'failed', 'last_error' => 'Endpoint desativado.', 'next_attempt_at' => null]));

            return;
        }

        $result = $sender->send($endpoint, $delivery);
        $now = $clock->now();

        $db->run($context, static function () use ($delivery, $endpoint, $result, $now): void {
            $delivery = WebhookDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
            $endpoint = WebhookEndpoint::query()->lockForUpdate()->findOrFail($endpoint->id);

            if ($result['ok']) {
                self::delivered($delivery, $endpoint, $result['status'], $now);
            } else {
                self::failed($delivery, $endpoint, $result['status'], $result['error'], $now);
            }
        });
    }

    /**
     * Reserva a tentativa: o reagendamento não a despacha de novo enquanto ela roda.
     *
     * @return array{0: WebhookDelivery|null, 1: WebhookEndpoint|null}
     */
    private function claim(Clock $clock): array
    {
        $claimed = WebhookDelivery::query()->whereKey($this->deliveryId)->where('status', 'pending')
            ->where(static fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $clock->now()))
            ->update(['next_attempt_at' => $clock->now()->addMinutes(10)]);
        $delivery = $claimed === 1 ? WebhookDelivery::query()->find($this->deliveryId) : null;

        return [$delivery, $delivery !== null ? WebhookEndpoint::query()->find($delivery->webhook_endpoint_id) : null];
    }

    private static function delivered(WebhookDelivery $delivery, WebhookEndpoint $endpoint, ?int $status, CarbonImmutable $now): void
    {
        $delivery->forceFill(['status' => 'delivered', 'attempts' => $delivery->attempts + 1, 'response_status' => $status, 'last_error' => null, 'delivered_at' => $now, 'next_attempt_at' => null])->save();
        $endpoint->forceFill(['consecutive_failures' => 0])->save();
    }

    /** Nova tentativa com espera crescente até o máximo; falhas seguidas desativam o endpoint. */
    private static function failed(WebhookDelivery $delivery, WebhookEndpoint $endpoint, ?int $status, ?string $error, CarbonImmutable $now): void
    {
        $attempts = $delivery->attempts + 1;
        /** @var list<int> $backoff */
        $backoff = array_values((array) config('integrations.webhooks.backoff_seconds', [60]));
        $exhausted = $attempts >= (int) config('integrations.webhooks.max_attempts', 8);
        $delivery->forceFill([
            'status' => $exhausted ? 'failed' : 'pending',
            'attempts' => $attempts,
            'response_status' => $status,
            'last_error' => $error !== null ? mb_substr($error, 0, 500) : null,
            'next_attempt_at' => $exhausted ? null : $now->addSeconds($backoff[min($attempts, count($backoff)) - 1] ?? 3600),
        ])->save();

        $failures = $endpoint->consecutive_failures + 1;

        if ($failures >= (int) config('integrations.webhooks.disable_after_failures', 20)) {
            $endpoint->forceFill(['is_active' => false, 'disabled_at' => $now, 'disabled_reason' => "Desativado após {$failures} falhas seguidas."]);
        }

        $endpoint->forceFill(['consecutive_failures' => $failures])->save();
    }
}

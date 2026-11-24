<?php

declare(strict_types=1);

namespace App\Actions\Finance\System;

use App\Enums\Finance\PaymentGatewayKind;
use App\Jobs\ProcessGatewayEvent;
use App\Models\Finance\Payment;
use App\Services\Finance\Gateways\AsaasGateway;
use App\Services\Finance\Gateways\ChargeResult;
use App\Support\Clock\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Eventos recebidos dos gateways (RF-112): gravados uma vez por provedor e
 * identificador (o repetido é ignorado), processados depois do commit pela
 * fila e marcados como processados, ignorados ou com falha. Rodam no
 * contexto de sistema do tenant do evento.
 */
final readonly class GatewayEvents
{
    /** Tipos do Asaas que mudam a situação de um pagamento. */
    /** Tipos do Asaas que podem mudar a situação de um pagamento (vencida não encerra). */
    private const array ASAAS_TYPES = ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED', 'PAYMENT_DELETED'];

    public function __construct(
        private SettleGatewayPayment $settle,
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return bool se o evento é novo
     */
    public function record(string $tenantId, PaymentGatewayKind $gateway, string $eventId, string $eventType, ?string $chargeId, array $payload): bool
    {
        $id = (string) Str::uuid7();
        $inserted = DB::selectOne(
            'insert into gateway_webhook_events (id, tenant_id, gateway, event_id, event_type, charge_id, payload, status, received_at)
             values (?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?)
             on conflict (tenant_id, gateway, event_id) do nothing
             returning id',
            [$id, $tenantId, $gateway->value, mb_substr($eventId, 0, 120), mb_substr($eventType, 0, 60), $chargeId !== null ? mb_substr($chargeId, 0, 80) : null, json_encode($payload, JSON_THROW_ON_ERROR), 'pending', $this->clock->now()],
        );

        if ($inserted === null) {
            return false;
        }

        ProcessGatewayEvent::dispatch($id, $tenantId)->onQueue('critical')->afterCommit();

        return true;
    }

    public function process(string $eventRecordId): string
    {
        $event = DB::table('gateway_webhook_events')->where('id', $eventRecordId)->first();

        if ($event === null || $event->status !== 'pending') {
            return 'skipped';
        }

        // Falha propaga para o job tentar de novo; esgotadas as tentativas, o job
        // marca o evento como falho em transação própria (failed()).
        $outcome = $this->apply(PaymentGatewayKind::from((string) $event->gateway), (string) $event->event_type, $event->charge_id !== null ? (string) $event->charge_id : null, (array) json_decode((string) $event->payload, true, flags: JSON_THROW_ON_ERROR));

        DB::table('gateway_webhook_events')->where('id', $eventRecordId)->update(['status' => $outcome, 'processed_at' => $this->clock->now()]);

        return $outcome;
    }

    public function markFailed(string $eventRecordId, ?Throwable $error): void
    {
        DB::table('gateway_webhook_events')->where('id', $eventRecordId)->where('status', 'pending')->update([
            'status' => 'failed',
            'error' => mb_substr($error === null ? 'Falha sem exceção.' : $error::class.': '.$error->getMessage(), 0, 500),
            'processed_at' => $this->clock->now(),
        ]);
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function apply(PaymentGatewayKind $gateway, string $type, ?string $chargeId, array $payload): string
    {
        $payment = $chargeId !== null
            ? Payment::query()->where('gateway', $gateway->value)->where('gateway_charge_id', $chargeId)->first()
            : null;

        if ($payment === null) {
            return 'ignored';
        }

        $result = match ($gateway) {
            PaymentGatewayKind::Simulator => new ChargeResult($chargeId ?? '', $type === 'PAYMENT_RECEIVED' ? ChargeResult::CONFIRMED : ChargeResult::FAILED, null, isset($payload['value_cents']) ? \App\Domain\Shared\Money::ofCents((int) $payload['value_cents']) : null, isset($payload['fee_cents']) ? \App\Domain\Shared\Money::ofCents((int) $payload['fee_cents']) : null),
            PaymentGatewayKind::Asaas => in_array($type, self::ASAAS_TYPES, true) && is_array($payload['payment'] ?? null) ? AsaasGateway::result($payload['payment']) : null,
        };

        if ($result === null) {
            return 'ignored';
        }

        return $this->settle->handle($payment->id, $result) ? 'processed' : 'ignored';
    }
}

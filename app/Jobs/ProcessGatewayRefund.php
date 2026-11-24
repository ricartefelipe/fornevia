<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Finance\Exceptions\GatewayOutcomeUnknown;
use App\Domain\Finance\Exceptions\GatewayUnavailable;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentRefund;
use App\Services\Finance\Gateways\PaymentGateways;
use App\Services\Finance\RefundSettlement;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pede o reembolso ao gateway que criou o pagamento, fora de transação, e
 * aplica o resultado em transação própria: efetivado, recusado (a reserva é
 * desfeita) ou sem resposta (em verificação, com a reserva mantida).
 */
final class ProcessGatewayRefund implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly string $refundId, public readonly string $tenantId) {}

    public function handle(TenantDatabase $db, PaymentGateways $gateways, RefundSettlement $settlement): void
    {
        $context = ExecutionContext::system($this->tenantId);
        [$refund, $payment, $gateway] = $db->run($context, function () use ($gateways): array {
            $refund = PaymentRefund::query()->findOrFail($this->refundId);
            $payment = Payment::query()->findOrFail($refund->payment_id);

            return [$refund, $payment, $payment->gateway !== null ? $gateways->for($payment->gateway) : null];
        });

        if ($gateway === null || $payment->gateway_charge_id === null) {
            $db->run($context, fn () => $settlement->finish($refund->id, false, null));

            return;
        }

        try {
            $gatewayRefundId = $gateway->refund($payment->gateway_charge_id, $refund->amount_cents);
        } catch (GatewayOutcomeUnknown $e) {
            $db->run($context, fn () => $settlement->verifying($refund->id, $e->getMessage()));

            return;
        } catch (GatewayUnavailable) {
            $db->run($context, fn () => $settlement->finish($refund->id, false, null));

            return;
        }

        $db->run($context, fn () => $settlement->finish($refund->id, true, $gatewayRefundId));
    }
}

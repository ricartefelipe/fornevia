<?php

declare(strict_types=1);

namespace App\Actions\Finance\System;

use App\Actions\Finance\CreateCharge;
use App\Enums\Finance\PaymentSource;
use App\Enums\Finance\PaymentStatus;
use App\Models\Finance\Payment;
use App\Services\Finance\Gateways\PaymentGateways;
use App\Support\Clock\Clock;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Conciliação agendada (RF-100, RF-101): por tenant, os pagamentos
 * pendentes no gateway há mais que o prazo são conferidos pela referência
 * externa. Cobrança que não chegou a ser criada é criada agora (procurando
 * antes, para não repetir); cobrança paga ou encerrada no gateway é
 * aplicada como um webhook. Falha em um pagamento não interrompe os demais.
 */
final readonly class ReconcilePayments
{
    public function __construct(
        private TenantDatabase $db,
        private PaymentGateways $gateways,
        private CreateCharge $charges,
        private SettleGatewayPayment $settle,
        private Clock $clock,
    ) {}

    /**
     * @return array{checked: int, settled: int, created: int, failed: int}
     */
    public function handle(): array
    {
        $totals = ['checked' => 0, 'settled' => 0, 'created' => 0, 'failed' => 0];
        $before = $this->clock->now()->subMinutes((int) config('payments.reconcile_after_minutes', 10));

        foreach (DB::table('organizations')->orderBy('id')->pluck('id') as $tenantId) {
            $context = ExecutionContext::system((string) $tenantId);
            $pending = $this->db->run($context, static fn (): array => Payment::query()
                ->where('source', PaymentSource::Gateway->value)
                ->where('status', PaymentStatus::Pending->value)
                ->where('created_at', '<=', $before)
                ->orderBy('created_at')
                ->limit(100)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all());

            foreach ($pending as $paymentId) {
                $totals['checked']++;

                try {
                    match ($this->db->run($context, fn (): string => $this->reconcile($paymentId))) {
                        'settled' => $totals['settled']++,
                        'created' => $totals['created']++,
                        default => null,
                    };
                } catch (Throwable $e) {
                    $totals['failed']++;
                    Log::warning('Falha ao conciliar pagamento.', ['tenant_id' => (string) $tenantId, 'payment_id' => $paymentId, 'exception' => $e]);
                }
            }
        }

        return $totals;
    }

    private function reconcile(string $paymentId): string
    {
        $payment = Payment::query()->findOrFail($paymentId);

        if ($payment->gateway_charge_id === null) {
            $this->charges->charge($payment);

            return 'created';
        }

        $result = $this->gateways->current()->find($payment->id);

        if ($result === null) {
            return 'unchanged';
        }

        return $this->settle->handle($payment->id, $result) ? 'settled' : 'unchanged';
    }
}

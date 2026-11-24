<?php

declare(strict_types=1);

namespace App\Actions\Finance\System;

use App\Actions\Finance\CreateCharge;
use App\Enums\Finance\InstallmentStatus;
use App\Enums\Finance\PaymentSource;
use App\Enums\Finance\PaymentStatus;
use App\Models\Finance\Payment;
use App\Models\Finance\ReceivableInstallment;
use App\Services\Finance\Gateways\PaymentGateways;
use App\Services\Finance\PendingCharges;
use App\Support\Clock\Clock;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Conciliação agendada (RF-100, RF-101): por tenant, pagamentos pendentes
 * no gateway há mais que o prazo, primeiro os sem cobrança criada e depois
 * os conferidos há mais tempo, em lotes até cobrir todos. Cobrança que não
 * chegou a ser criada é criada agora (procurando antes, para não repetir);
 * parcela que já não está em aberto tem a cobrança cancelada; cobrança paga
 * ou removida no gateway do pagamento é aplicada como um webhook. Falha em
 * um pagamento não interrompe os demais.
 */
final readonly class ReconcilePayments
{
    private const int BATCH = 100;

    public function __construct(
        private TenantDatabase $db,
        private PaymentGateways $gateways,
        private CreateCharge $charges,
        private SettleGatewayPayment $settle,
        private PendingCharges $pending,
        private Clock $clock,
    ) {}

    /**
     * @return array{checked: int, settled: int, created: int, failed: int}
     */
    public function handle(): array
    {
        $totals = ['checked' => 0, 'settled' => 0, 'created' => 0, 'failed' => 0];
        $now = $this->clock->now();
        $before = $now->subMinutes((int) config('payments.reconcile_after_minutes', 10));

        foreach (DB::table('organizations')->orderBy('id')->pluck('id') as $tenantId) {
            $context = ExecutionContext::system((string) $tenantId);
            $seen = [];

            do {
                $batch = $this->db->run($context, static fn (): array => Payment::query()
                    ->where('source', PaymentSource::Gateway->value)
                    ->where('status', PaymentStatus::Pending->value)
                    ->where('created_at', '<=', $before)
                    ->whereNotIn('id', $seen)
                    ->orderByRaw('gateway_charge_id is not null')
                    ->orderByRaw('last_checked_at asc nulls first')
                    ->orderBy('created_at')
                    ->limit(self::BATCH)
                    ->pluck('id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->all());

                foreach ($batch as $paymentId) {
                    $seen[] = $paymentId;
                    $totals['checked']++;

                    try {
                        match ($this->reconcile($context, $paymentId)) {
                            'settled' => $totals['settled']++,
                            'created' => $totals['created']++,
                            default => null,
                        };
                    } catch (Throwable $e) {
                        $totals['failed']++;
                        Log::warning('Falha ao conciliar pagamento.', ['tenant_id' => (string) $tenantId, 'payment_id' => $paymentId, 'exception' => $e]);
                    } finally {
                        $this->db->run($context, fn () => Payment::query()->whereKey($paymentId)->update(['last_checked_at' => $this->clock->now()]));
                    }
                }
            } while (count($batch) === self::BATCH);
        }

        return $totals;
    }

    private function reconcile(ExecutionContext $context, string $paymentId): string
    {
        $payment = $this->db->run($context, function () use ($paymentId): ?Payment {
            $payment = Payment::query()->find($paymentId);
            $installment = $payment !== null ? ReceivableInstallment::query()->find($payment->installment_id) : null;

            // Parcela quitada por outro meio: a cobrança pendente deixa de valer.
            if ($payment !== null && $installment !== null && $installment->status !== InstallmentStatus::Open) {
                DB::transaction(fn () => $this->pending->cancelFor($installment, null, 'A parcela já está quitada.'));

                return null;
            }

            return $payment;
        });

        if ($payment === null || $payment->gateway === null) {
            return 'unchanged';
        }

        if ($payment->gateway_charge_id === null) {
            $this->charges->charge($payment);

            return 'created';
        }

        $result = $this->db->run($context, fn () => $this->gateways->for($payment->gateway))->find($payment->id);

        if ($result === null) {
            return 'unchanged';
        }

        return $this->db->run($context, fn (): bool => $this->settle->handle($payment->id, $result)) ? 'settled' : 'unchanged';
    }
}

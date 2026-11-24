<?php

declare(strict_types=1);

namespace App\Actions\Finance\System;

use App\Domain\Finance\Exceptions\GatewayUnavailable;
use App\Enums\Finance\RefundOutcome;
use App\Enums\Finance\RefundStatus;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentRefund;
use App\Services\Audit\AuditRecorder;
use App\Services\Finance\Gateways\GatewayRefund;
use App\Services\Finance\Gateways\PaymentGateways;
use App\Services\Finance\RefundSettlement;
use App\Support\Clock\Clock;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Conferência dos reembolsos em verificação (ADR-028), conservadora para
 * nunca permitir reembolso em dobro:
 *
 * - consulta o gateway que criou o pagamento pela referência do reembolso;
 * - efetivado conclui (o valor volta a ser devido na parcela, com o id do
 *   estorno); recusado desfaz a reserva; em processamento aguarda;
 * - sem registro só desfaz quando o pedido levou a referência e a
 *   verificação começou há mais de 24 horas; pedidos antigos, sem
 *   referência, ficam para a conclusão manual;
 * - gateway sem resposta aguarda a próxima rodada; erro em um reembolso não
 *   interrompe os demais; conclusão manual feita no meio é respeitada, e um
 *   resultado do gateway que a contradiga fica registrado na auditoria.
 *
 * Todos os reembolsos em verificação são conferidos, os conferidos há mais
 * tempo primeiro.
 */
final readonly class VerifyRefunds
{
    /** Horas sem registro no gateway até considerar que o pedido não chegou. */
    public const int MISSING_AFTER_HOURS = 24;

    public function __construct(
        private TenantDatabase $db,
        private PaymentGateways $gateways,
        private RefundSettlement $settlement,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    /**
     * @return array{checked: int, done: int, failed: int, waiting: int, errors: int}
     */
    public function handle(): array
    {
        $result = ['checked' => 0, 'done' => 0, 'failed' => 0, 'waiting' => 0, 'errors' => 0];

        foreach (DB::table('organizations')->orderBy('id')->pluck('id') as $tenantId) {
            $context = ExecutionContext::system((string) $tenantId);

            try {
                $ids = $this->db->run($context, static fn (): array => PaymentRefund::query()->where('status', RefundStatus::Verifying->value)
                    ->orderByRaw('last_checked_at asc nulls first')->orderBy('created_at')->pluck('id')->all());
            } catch (Throwable $e) {
                report($e);
                $result['errors']++;

                continue;
            }

            foreach ($ids as $id) {
                $result['checked']++;

                try {
                    $result[$this->verify($context, (string) $id)]++;
                } catch (Throwable $e) {
                    report($e);
                    $result['errors']++;
                }
            }
        }

        return $result;
    }

    /**
     * @return 'done'|'failed'|'waiting'
     */
    private function verify(ExecutionContext $context, string $refundId): string
    {
        // Gateway resolvido no contexto do tenant (lê a chave do distribuidor);
        // a consulta HTTP acontece fora da transação.
        [$refund, $payment, $gateway] = $this->db->run($context, function () use ($refundId): array {
            $refund = PaymentRefund::query()->findOrFail($refundId);
            $refund->forceFill(['last_checked_at' => $this->clock->now()])->save();
            $payment = Payment::query()->findOrFail($refund->payment_id);

            return [$refund, $payment, $payment->gateway !== null ? $this->gateways->for($payment->gateway) : null];
        });

        if ($gateway === null || $payment->gateway_charge_id === null) {
            return 'waiting';
        }

        try {
            $found = $gateway->refundOutcome($payment->gateway_charge_id, $refund->id);
        } catch (GatewayUnavailable) {
            return 'waiting';
        }

        $missingLongEnough = $found->outcome === RefundOutcome::Missing
            && $refund->gateway_reference_sent
            && $refund->verification_started_at !== null
            && $refund->verification_started_at->lessThanOrEqualTo($this->clock->now()->subHours(self::MISSING_AFTER_HOURS));

        return match (true) {
            $found->outcome === RefundOutcome::Done => $this->settle($context, $refund->id, true, $found, 'Reembolso confirmado na conferência com o gateway.'),
            $found->outcome === RefundOutcome::Failed => $this->settle($context, $refund->id, false, $found, 'Reembolso recusado pelo gateway na conferência.'),
            $missingLongEnough => $this->settle($context, $refund->id, false, $found, 'Reembolso sem registro no gateway após '.self::MISSING_AFTER_HOURS.' horas: o pedido não chegou e pode ser feito de novo.'),
            default => 'waiting',
        };
    }

    /**
     * Conclui só se o reembolso ainda está em verificação (conferido sob o
     * lock do pagamento, em RefundSettlement::finish).
     *
     * @return 'done'|'failed'|'waiting'
     */
    private function settle(ExecutionContext $context, string $refundId, bool $done, GatewayRefund $found, string $note): string
    {
        return $this->db->run($context, function () use ($refundId, $done, $found, $note): string {
            $before = PaymentRefund::query()->findOrFail($refundId);

            if ($before->status !== RefundStatus::Verifying) {
                $concludedDone = $before->status === RefundStatus::Done;

                if ($concludedDone !== $done) {
                    // A equipe concluiu de outro jeito no meio da conferência.
                    $this->audit->record('payment.refund_conflict', $before, ['gateway_done' => $done, 'gateway_refund_id' => $found->id], 'Resultado do gateway diferente da conclusão manual; confira no painel do gateway.');
                    Log::warning('Reembolso concluído manualmente diverge do gateway.', ['refund_id' => $before->id, 'gateway_done' => $done]);
                }

                return 'waiting';
            }

            $settled = $this->settlement->finish($refundId, $done, $found->id);

            if ($settled->status !== ($done ? RefundStatus::Done : RefundStatus::Failed)) {
                return 'waiting';
            }

            $this->audit->record('payment.refund_verified', $settled, ['done' => $done, 'gateway_refund_id' => $found->id], $note);

            return $done ? 'done' : 'failed';
        });
    }
}

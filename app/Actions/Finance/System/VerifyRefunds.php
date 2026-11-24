<?php

declare(strict_types=1);

namespace App\Actions\Finance\System;

use App\Domain\Finance\Exceptions\GatewayUnavailable;
use App\Enums\Finance\RefundOutcome;
use App\Enums\Finance\RefundStatus;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentRefund;
use App\Services\Audit\AuditRecorder;
use App\Services\Finance\Gateways\PaymentGateways;
use App\Services\Finance\RefundSettlement;
use App\Support\Clock\Clock;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Conferência dos reembolsos em verificação (ADR-028): consulta o gateway
 * que criou o pagamento pela referência do reembolso. Efetivado conclui
 * (o valor volta a ser devido na parcela); recusado desfaz a reserva;
 * ainda em processamento continua em verificação. Sem registro no gateway
 * depois do prazo, o pedido de reembolso não chegou: a reserva é desfeita e
 * o reembolso pode ser pedido de novo. Gateway sem resposta fica para a
 * próxima rodada; a equipe ainda pode concluir manualmente.
 */
final readonly class VerifyRefunds
{
    /** Minutos sem registro no gateway até considerar que o pedido não chegou. */
    public const int MISSING_AFTER_MINUTES = 30;

    public function __construct(
        private TenantDatabase $db,
        private PaymentGateways $gateways,
        private RefundSettlement $settlement,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    /**
     * @return array{checked: int, done: int, failed: int, waiting: int}
     */
    public function handle(): array
    {
        $result = ['checked' => 0, 'done' => 0, 'failed' => 0, 'waiting' => 0];

        foreach (DB::table('organizations')->orderBy('id')->pluck('id') as $tenantId) {
            $context = ExecutionContext::system((string) $tenantId);
            $refunds = $this->db->run($context, static fn () => PaymentRefund::query()->where('status', RefundStatus::Verifying->value)->orderBy('created_at')->limit(200)->get());

            foreach ($refunds as $refund) {
                $result['checked']++;
                $result[$this->verify($context, $refund)]++;
            }
        }

        return $result;
    }

    /**
     * @return 'done'|'failed'|'waiting'
     */
    private function verify(ExecutionContext $context, PaymentRefund $refund): string
    {
        try {
            // Gateway resolvido no contexto do tenant (lê a chave do distribuidor);
            // a consulta HTTP acontece fora da transação.
            [$payment, $gateway] = $this->db->run($context, function () use ($refund): array {
                $payment = Payment::query()->findOrFail($refund->payment_id);

                return [$payment, $payment->gateway !== null ? $this->gateways->for($payment->gateway) : null];
            });

            if ($gateway === null || $payment->gateway_charge_id === null) {
                return 'waiting';
            }

            $outcome = $gateway->refundOutcome($payment->gateway_charge_id, $refund->id);
        } catch (GatewayUnavailable) {
            return 'waiting';
        } catch (Throwable $e) {
            report($e);

            return 'waiting';
        }

        $missing = $outcome === RefundOutcome::Missing
            && $refund->created_at->lessThanOrEqualTo($this->clock->now()->subMinutes(self::MISSING_AFTER_MINUTES));

        return match (true) {
            $outcome === RefundOutcome::Done => $this->settle($context, $refund, true, 'Reembolso confirmado na conferência com o gateway.'),
            $outcome === RefundOutcome::Failed => $this->settle($context, $refund, false, 'Reembolso recusado pelo gateway na conferência.'),
            $missing => $this->settle($context, $refund, false, 'Reembolso sem registro no gateway após '.self::MISSING_AFTER_MINUTES.' minutos: o pedido não chegou e pode ser feito de novo.'),
            default => 'waiting',
        };
    }

    /**
     * @return 'done'|'failed'
     */
    private function settle(ExecutionContext $context, PaymentRefund $refund, bool $done, string $note): string
    {
        $this->db->run($context, function () use ($refund, $done, $note): void {
            $settled = $this->settlement->finish($refund->id, $done, null);
            $this->audit->record('payment.refund_verified', $settled, ['done' => $done], $note);
        });

        return $done ? 'done' : 'failed';
    }
}

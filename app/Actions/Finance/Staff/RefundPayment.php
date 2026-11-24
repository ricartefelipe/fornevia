<?php

declare(strict_types=1);

namespace App\Actions\Finance\Staff;

use App\Domain\Finance\Exceptions\InvalidPayment;
use App\Domain\Finance\Exceptions\RefundNotAllowed;
use App\Domain\Finance\RefundLimit;
use App\Enums\Finance\PaymentSource;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Finance\RefundStatus;
use App\Enums\Identity\Permission;
use App\Jobs\ProcessGatewayRefund;
use App\Models\Finance\PaymentRefund;
use App\Services\Finance\FinanceLocks;
use App\Services\Finance\RefundSettlement;
use App\Support\Auth\Access;
use Illuminate\Support\Facades\DB;

/**
 * Reembolso de pagamento confirmado (RF-100, RN-101). O valor é reservado
 * como pendente sob o lock do pagamento: a soma dos pendentes e efetivados
 * nunca passa do pago (também por CHECK). Pagamento manual é reembolsado na
 * hora; pelo gateway, a chamada roda num job depois do commit, sem segurar
 * locks, e o resultado é aplicado em transação própria. Sem resposta do
 * gateway, o reembolso fica em verificação com a reserva mantida, até a
 * equipe conferir (nada é repetido).
 */
final readonly class RefundPayment
{
    public function __construct(
        private Access $access,
        private FinanceLocks $locks,
        private RefundSettlement $settlement,
    ) {}

    public function handle(mixed $paymentId, string $amount, string $reason): PaymentRefund
    {
        $this->access->authorize(Permission::FinanceManage);
        $value = RecordPayment::money($amount);
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new InvalidPayment('Informe o motivo do reembolso (de 5 a 500 caracteres).', ['field' => 'reason']);
        }

        return DB::transaction(function () use ($paymentId, $value, $reason): PaymentRefund {
            [, , , $payment] = $this->locks->payment($paymentId);

            if ($payment->status !== PaymentStatus::Confirmed) {
                throw new RefundNotAllowed('Só pagamentos confirmados são reembolsados.');
            }

            $available = RefundLimit::available($payment->amount_cents, $payment->refunded_cents, $payment->refund_pending_cents);

            if ($value->greaterThan($available)) {
                throw new RefundNotAllowed("O reembolso passa do valor elegível ({$available->format()}).", ['field' => 'amount']);
            }

            $payment->forceFill(['refund_pending_cents' => $payment->refund_pending_cents->plus($value)])->save();
            $refund = new PaymentRefund;
            $refund->forceFill([
                'buyer_company_id' => $payment->buyer_company_id,
                'payment_id' => $payment->id,
                'amount_cents' => $value,
                'status' => RefundStatus::Pending,
                'reason' => $reason,
                'created_by_user_id' => $this->access->userId(),
            ])->save();

            if ($payment->source === PaymentSource::Gateway && $payment->gateway_charge_id !== null) {
                ProcessGatewayRefund::dispatch($refund->id, $refund->tenant_id)->onQueue('critical')->afterCommit();

                return $refund;
            }

            return $this->settlement->finish($refund->id, true, null);
        });
    }

    /**
     * Conclusão pela equipe de um reembolso em verificação, depois de
     * conferir no painel do gateway.
     */
    public function resolve(mixed $refundId, bool $done): PaymentRefund
    {
        $this->access->authorize(Permission::FinanceManage);
        $refund = PaymentRefund::query()->findOrFail(\App\Support\Http\UntrustedId::uuid($refundId, PaymentRefund::class));

        if ($refund->status !== RefundStatus::Verifying) {
            throw new RefundNotAllowed('Só reembolsos em verificação são concluídos manualmente.');
        }

        return DB::transaction(fn (): PaymentRefund => $this->settlement->finish($refund->id, $done, null));
    }
}

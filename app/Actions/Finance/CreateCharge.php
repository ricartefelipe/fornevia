<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Domain\Finance\Exceptions\GatewayUnavailable;
use App\Domain\Finance\Exceptions\InvalidPayment;
use App\Domain\Finance\Exceptions\PaymentNotAllowed;
use App\Enums\Finance\InstallmentStatus;
use App\Enums\Finance\PaymentMethod;
use App\Enums\Finance\PaymentSource;
use App\Enums\Finance\PaymentStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Finance\Payment;
use App\Models\Finance\ReceivableInstallment;
use App\Services\Audit\AuditRecorder;
use App\Services\Finance\FinanceLocks;
use App\Services\Finance\Gateways\ChargeRequest;
use App\Services\Finance\Gateways\PaymentGateways;
use App\Services\Finance\Receivables;
use App\Support\Clock\Clock;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Cobrança da parcela pelo gateway (RF-101), pedida pela equipe ou pelo
 * comprador (o chamador autoriza): no máximo uma cobrança pendente por
 * parcela, sempre do saldo em aberto. O pagamento pendente é gravado antes
 * de chamar o gateway, com o próprio id como referência externa; se o
 * gateway não responder, o pagamento volta pendente sem link e com o
 * motivo, e a conciliação procura pela referência antes de criar outra. Cartão é pago na página do
 * gateway (RS-100).
 */
final readonly class CreateCharge
{
    public const array METHODS = [PaymentMethod::Pix, PaymentMethod::Boleto, PaymentMethod::Card];

    public function __construct(
        private FinanceLocks $locks,
        private PaymentGateways $gateways,
        private TenantDatabase $db,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(mixed $installmentId, PaymentMethod $method): Payment
    {
        if (! in_array($method, self::METHODS, true)) {
            throw new InvalidPayment('Escolha Pix, boleto ou cartão.', ['field' => 'method']);
        }

        // A configuração do gateway é interna do distribuidor (invisível ao comprador).
        $gateway = $this->db->asSystem(fn () => $this->gateways->current());

        // Gravação no contexto de sistema do mesmo usuário: o comprador só lê o financeiro.
        $payment = $this->db->asSystem(fn (): Payment => DB::transaction(function () use ($installmentId, $method, $gateway): Payment {
            [, , $installment] = $this->locks->installment($installmentId);

            if ($installment->status !== InstallmentStatus::Open) {
                throw new PaymentNotAllowed('A parcela já está quitada.');
            }

            $pending = Payment::query()->where('installment_id', $installment->id)->where('status', PaymentStatus::Pending->value)->first();

            if ($pending !== null) {
                return $pending;
            }

            $payment = new Payment;
            $payment->forceFill([
                'buyer_company_id' => $installment->buyer_company_id,
                'installment_id' => $installment->id,
                'amount_cents' => Receivables::open($installment),
                'method' => $method,
                'source' => PaymentSource::Gateway,
                'gateway' => $gateway->kind(),
                'status' => PaymentStatus::Pending,
            ])->save();

            return $payment;
        }));

        if ($payment->gateway_charge_id !== null) {
            return $payment;
        }

        try {
            return $this->charge($payment);
        } catch (GatewayUnavailable) {
            // Fica pendente, com o motivo; a conciliação procura e cria depois.
            return $this->db->asSystem(static fn (): Payment => Payment::query()->findOrFail($payment->id));
        }
    }

    /**
     * Cria (ou encontra) a cobrança de um pagamento pendente sem id de
     * cobrança. Também usada pela conciliação.
     */
    public function charge(Payment $payment): Payment
    {
        $installment = $this->db->asSystem(static fn (): ReceivableInstallment => ReceivableInstallment::query()->with('invoice')->findOrFail($payment->installment_id));
        $invoice = $installment->invoice;
        $buyer = $this->db->asSystem(static fn (): BuyerCompany => BuyerCompany::query()->findOrFail($invoice->buyer_company_id));

        try {
            $result = $this->db->asSystem(fn () => $this->gateways->current())->charge(new ChargeRequest(
                reference: $payment->id,
                amount: $payment->amount_cents,
                method: $payment->method,
                dueDate: $installment->due_date->greaterThan($this->clock->now()) ? $installment->due_date : $this->clock->now(),
                description: "Fatura {$invoice->number}, parcela {$installment->sequence}",
                payerName: $buyer->legal_name,
                payerDocument: $buyer->document,
                payerEmail: $buyer->email,
            ));
        } catch (GatewayUnavailable $e) {
            $this->db->asSystem(static fn () => Payment::query()->whereKey($payment->id)->update(['failure_reason' => mb_substr($e->getMessage(), 0, 500)]));

            throw $e;
        }

        return $this->db->asSystem(function () use ($payment, $result, $invoice): Payment {
            $stored = Payment::query()->findOrFail($payment->id);
            $stored->forceFill(['gateway_charge_id' => $result->chargeId, 'checkout_url' => $result->checkoutUrl, 'failure_reason' => null])->save();
            $this->audit->record('payment.charge_created', $stored, ['invoice_id' => $invoice->id, 'gateway' => $stored->gateway?->value, 'buyer_company_id' => $stored->buyer_company_id]);

            return $stored;
        });
    }
}

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
use App\Jobs\CreateGatewayCharge;
use App\Models\Buyers\BuyerCompany;
use App\Models\Finance\Payment;
use App\Models\Finance\ReceivableInstallment;
use App\Services\Audit\AuditRecorder;
use App\Services\Finance\FinanceLocks;
use App\Services\Finance\Gateways\ChargeRequest;
use App\Services\Finance\Gateways\PaymentGateways;
use App\Services\Finance\PendingCharges;
use App\Services\Finance\Receivables;
use App\Support\Clock\Clock;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Cobrança da parcela pelo gateway (RF-101), pedida pela equipe ou pelo
 * comprador (o chamador autoriza): uma cobrança pendente por parcela,
 * sempre do saldo em aberto e na forma escolhida; outra pendente com valor
 * ou forma diferentes é cancelada. O pagamento pendente é gravado na
 * transação da requisição; a chamada ao gateway roda num job depois do
 * commit, com o id do pagamento como referência externa (ADR-028), sem
 * segurar locks durante a chamada. Cartão é pago na página do gateway
 * (RS-100).
 */
final readonly class CreateCharge
{
    public const array METHODS = [PaymentMethod::Pix, PaymentMethod::Boleto, PaymentMethod::Card];

    public function __construct(
        private FinanceLocks $locks,
        private PaymentGateways $gateways,
        private PendingCharges $pending,
        private TenantDatabase $db,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(mixed $installmentId, PaymentMethod $method): Payment
    {
        if (! in_array($method, self::METHODS, true)) {
            throw new InvalidPayment('Escolha Pix, boleto ou cartão.', ['field' => 'method']);
        }

        // Configuração e financeiro são gravados no contexto de sistema do
        // mesmo usuário: o comprador só os lê (ADR-024).
        return $this->db->asSystem(fn (): Payment => DB::transaction(function () use ($installmentId, $method): Payment {
            $gateway = $this->gateways->active() ?? throw new PaymentNotAllowed('O distribuidor ainda não configurou o gateway de pagamento.');
            [, , $installment] = $this->locks->installment($installmentId);

            if ($installment->status !== InstallmentStatus::Open) {
                throw new PaymentNotAllowed('A parcela já está quitada.');
            }

            $open = Receivables::open($installment);
            $current = Payment::query()
                ->where('installment_id', $installment->id)
                ->where('status', PaymentStatus::Pending->value)
                ->where('source', PaymentSource::Gateway->value)
                ->where('gateway', $gateway->kind()->value)
                ->where('method', $method->value)
                ->where('amount_cents', $open->cents)
                ->first();

            if ($current !== null) {
                return $current;
            }

            $this->pending->cancelFor($installment, null, 'Substituída por nova cobrança.');

            $payment = new Payment;
            $payment->forceFill([
                'buyer_company_id' => $installment->buyer_company_id,
                'installment_id' => $installment->id,
                'amount_cents' => $open,
                'method' => $method,
                'source' => PaymentSource::Gateway,
                'gateway' => $gateway->kind(),
                'status' => PaymentStatus::Pending,
            ])->save();

            CreateGatewayCharge::dispatch($payment->id, $payment->tenant_id)->onQueue('critical')->afterCommit();

            return $payment;
        }));
    }

    /**
     * Cria (ou encontra pela referência) a cobrança de um pagamento
     * pendente sem id de cobrança, fora de transação: usada pelo job e pela
     * conciliação.
     *
     * @throws GatewayUnavailable
     */
    public function charge(Payment $payment): Payment
    {
        $context = ExecutionContext::system($payment->tenant_id);
        $payment = $this->db->run($context, static fn (): Payment => Payment::query()->findOrFail($payment->id));

        // Substituída ou já resolvida enquanto esperava: nada a cobrar.
        if ($payment->status !== PaymentStatus::Pending || $payment->gateway_charge_id !== null) {
            return $payment;
        }

        [$installment, $buyer, $gateway] = $this->db->run($context, fn (): array => [
            ReceivableInstallment::query()->with('invoice')->findOrFail($payment->installment_id),
            BuyerCompany::query()->findOrFail($payment->buyer_company_id),
            $this->gateways->for($payment->gateway ?? throw new PaymentNotAllowed('Pagamento sem gateway.')),
        ]);
        $invoice = $installment->invoice;

        try {
            $result = $gateway->charge(new ChargeRequest(
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
            $this->db->run($context, static fn () => Payment::query()->whereKey($payment->id)->update(['failure_reason' => mb_substr($e->getMessage(), 0, 500)]));

            throw $e;
        }

        return $this->db->run($context, function () use ($payment, $result, $invoice): Payment {
            $stored = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $stored->forceFill(['gateway_charge_id' => $result->chargeId, 'checkout_url' => $result->checkoutUrl, 'failure_reason' => null])->save();
            $this->audit->record('payment.charge_created', $stored, ['invoice_id' => $invoice->id, 'gateway' => $stored->gateway?->value, 'buyer_company_id' => $stored->buyer_company_id]);

            return $stored;
        });
    }
}

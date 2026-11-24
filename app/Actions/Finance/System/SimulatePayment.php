<?php

declare(strict_types=1);

namespace App\Actions\Finance\System;

use App\Actions\Finance\Portal\BuyerInvoices;
use App\Domain\Finance\Exceptions\PaymentNotAllowed;
use App\Enums\Finance\PaymentGatewayKind;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Identity\Permission;
use App\Models\Finance\Payment;
use App\Models\Finance\ReceivableInstallment;
use App\Services\Finance\Gateways\PaymentGateways;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Str;

/**
 * Página do simulador local, só fora de produção (payments.simulator_enabled):
 * o comprador que vê a fatura, ou a equipe do financeiro, simula o
 * pagamento ou a remoção da cobrança, gerando o mesmo evento que um webhook
 * (com taxa simulada de 1%), processado pela mesma fila.
 */
final readonly class SimulatePayment
{
    public function __construct(
        private TenantDatabase $db,
        private GatewayEvents $events,
        private Access $access,
        private BuyerInvoices $invoices,
    ) {}

    public function visible(mixed $paymentId): Payment
    {
        if (! PaymentGateways::simulatorEnabled()) {
            throw new PaymentNotAllowed('O simulador de pagamento não está disponível neste ambiente.');
        }

        $payment = Payment::query()->findOrFail(UntrustedId::uuid($paymentId, Payment::class));

        if ($payment->gateway !== PaymentGatewayKind::Simulator) {
            throw new PaymentNotAllowed('Esta cobrança não é do simulador.');
        }

        if ($this->access->isBuyer()) {
            $this->invoices->find($this->invoices->actor(), ReceivableInstallment::query()->findOrFail($payment->installment_id)->invoice_id);
        } else {
            $this->access->authorize(Permission::FinanceView);
        }

        return $payment;
    }

    public function handle(mixed $paymentId, bool $paid): void
    {
        $payment = $this->visible($paymentId);

        if ($payment->status !== PaymentStatus::Pending || $payment->gateway_charge_id === null) {
            throw new PaymentNotAllowed('A cobrança não está aguardando pagamento.');
        }

        $this->db->asSystem(fn (): bool => $this->events->record($payment->tenant_id, PaymentGatewayKind::Simulator, 'sim-'.Str::uuid7(), $paid ? 'PAYMENT_RECEIVED' : 'PAYMENT_DELETED', $payment->gateway_charge_id, [
            'value_cents' => $payment->amount_cents->cents,
            'fee_cents' => intdiv($payment->amount_cents->cents, 100),
        ]));
    }
}

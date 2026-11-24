<?php

declare(strict_types=1);

namespace App\Livewire\Finance;

use App\Actions\Finance\System\SimulatePayment;
use App\Domain\Shared\BusinessRuleViolation;
use App\Support\Auth\Access;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Página do simulador local de pagamento: quem vê a cobrança (comprador ou
 * equipe) simula o pagamento ou a recusa.
 */
#[Layout('layouts.app')]
#[Title('Simulador de pagamento')]
final class PaymentSimulator extends Component
{
    #[Locked]
    public string $paymentId = '';

    public string $message = '';

    public string $failure = '';

    public function mount(string $payment, SimulatePayment $simulate): void
    {
        $this->paymentId = $simulate->visible($payment)->id;
    }

    public function simulate(bool $paid, SimulatePayment $simulate): void
    {
        $this->failure = '';

        try {
            $simulate->handle($this->paymentId, $paid);
        } catch (BusinessRuleViolation $e) {
            $this->failure = $e->getMessage();

            return;
        }

        $this->message = $paid ? 'Pagamento simulado. A confirmação chega como um aviso do gateway.' : 'Recusa simulada.';
    }

    public function render(SimulatePayment $simulate, Access $access): View
    {
        $payment = $simulate->visible($this->paymentId);
        $payment->load('installment.invoice');

        return view('livewire.finance.payment-simulator', [
            'payment' => $payment,
            'back' => $access->isBuyer() ? route('portal.invoices.show', $payment->installment->invoice_id) : route('finance.invoices.show', $payment->installment->invoice_id),
        ]);
    }
}

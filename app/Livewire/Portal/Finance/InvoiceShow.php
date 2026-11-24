<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Finance;

use App\Actions\Finance\Portal\BuyerInvoices;
use App\Actions\Finance\Portal\PayInstallment;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Finance\PaymentMethod;
use App\Enums\Identity\Permission;
use App\Support\Auth\Access;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Fatura no portal: itens, parcelas e pagamento de cada parcela em aberto
 * na página do gateway (Pix, boleto ou cartão).
 */
#[Layout('layouts.app')]
#[Title('Fatura')]
final class InvoiceShow extends Component
{
    #[Locked]
    public string $invoiceId = '';

    public string $method = 'pix';

    public string $failure = '';

    public string $message = '';

    public function mount(string $invoice, BuyerInvoices $invoices): void
    {
        $this->invoiceId = $invoices->find($invoices->actor(), $invoice)->id;
    }

    /** Gera a cobrança; o link aparece na parcela assim que o gateway responde. */
    public function pay(string $installmentId, PayInstallment $pay): void
    {
        $this->failure = '';
        $this->message = '';

        try {
            $payment = $pay->handle($installmentId, PaymentMethod::tryFrom($this->method) ?? PaymentMethod::Pix);
        } catch (BusinessRuleViolation $e) {
            $this->failure = $e->getMessage();

            return;
        }

        $this->message = $payment->checkout_url !== null ? 'Cobrança pronta: abra o pagamento na parcela.' : 'Gerando a cobrança no gateway. O link aparece na parcela em instantes.';
    }

    public function render(BuyerInvoices $invoices, Access $access): View
    {
        $invoice = $invoices->find($invoices->actor(), $this->invoiceId);
        $invoice->load(['items.orderItem', 'installments.payments', 'order']);

        return view('livewire.portal.finance.invoice-show', [
            'invoice' => $invoice,
            'canPay' => $access->can(Permission::PortalOrdersPlace),
            'methods' => collect([PaymentMethod::Pix, PaymentMethod::Boleto, PaymentMethod::Card])->mapWithKeys(static fn (PaymentMethod $m): array => [$m->value => $m->label()])->all(),
        ])->title("Fatura {$invoice->number}");
    }
}

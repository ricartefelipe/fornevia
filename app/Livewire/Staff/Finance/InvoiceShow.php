<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Finance;

use App\Actions\Finance\Staff\AdjustInstallment;
use App\Actions\Finance\Staff\ChargeInstallment;
use App\Actions\Finance\Staff\RecordPayment;
use App\Actions\Finance\Staff\RefundPayment;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Finance\PaymentMethod;
use App\Enums\Identity\Permission;
use App\Models\Finance\Invoice;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Fatura: itens, parcelas e, por parcela, pagamento manual, cobrança no
 * gateway, crédito e ajuste; por pagamento, reembolso. Lançamentos
 * compensatórios no histórico.
 */
#[Layout('layouts.app')]
#[Title('Fatura')]
final class InvoiceShow extends Component
{
    #[Locked]
    public string $invoiceId = '';

    /** Chave do próximo pagamento registrado nesta tela. */
    #[Locked]
    public string $paymentKey = '';

    public string $installmentId = '';

    public string $operation = 'payment';

    public string $amount = '';

    public string $method = 'transfer';

    public string $reason = '';

    public string $paymentId = '';

    public string $message = '';

    public string $failure = '';

    public function mount(string $invoice): void
    {
        $this->invoiceId = Invoice::query()->findOrFail(UntrustedId::uuid($invoice, Invoice::class))->id;
        $this->paymentKey = (string) Str::uuid7();
    }

    public function select(string $installmentId, string $operation): void
    {
        $this->resetErrorBag();
        $this->installmentId = $installmentId;
        $this->operation = in_array($operation, ['payment', 'charge', 'credit', 'adjust'], true) ? $operation : 'payment';
        $this->reset('amount', 'reason');
    }

    public function submit(RecordPayment $record, ChargeInstallment $charge, AdjustInstallment $adjust): void
    {
        $method = PaymentMethod::tryFrom($this->method) ?? PaymentMethod::Transfer;

        $done = $this->attempt(fn () => match ($this->operation) {
            'charge' => $charge->handle($this->installmentId, $method),
            'credit' => $adjust->credit($this->installmentId, $this->amount, $this->reason),
            'adjust' => $adjust->adjust($this->installmentId, $this->amount, $this->reason),
            default => $record->handle($this->installmentId, $this->amount, $method, $this->paymentKey),
        }, match ($this->operation) {
            'charge' => 'Cobrança solicitada ao gateway. O link aparece no pagamento em instantes.',
            'credit' => 'Crédito concedido.',
            'adjust' => 'Ajuste registrado.',
            default => 'Pagamento registrado.',
        });

        if ($done) {
            $this->paymentKey = (string) Str::uuid7();
            $this->reset('installmentId', 'amount', 'reason');
        }
    }

    public function refund(RefundPayment $refund): void
    {
        if ($this->attempt(fn () => $refund->handle($this->paymentId, $this->amount, $this->reason), 'Reembolso efetuado.')) {
            $this->reset('paymentId', 'amount', 'reason');
        }
    }

    public function startRefund(string $paymentId): void
    {
        $this->resetErrorBag();
        $this->reset('installmentId', 'amount', 'reason');
        $this->paymentId = $paymentId;
    }

    public function render(Access $access): View
    {
        $invoice = Invoice::query()->with(['items.orderItem', 'installments.payments.refunds', 'order.buyerCompany'])->findOrFail($this->invoiceId);

        return view('livewire.staff.finance.invoice-show', [
            'invoice' => $invoice,
            'entries' => DB::table('finance_entries')->where('invoice_id', $invoice->id)->orderBy('created_at')->orderBy('id')->get(),
            'canManage' => $access->can(Permission::FinanceManage),
            'methods' => collect([PaymentMethod::Transfer, PaymentMethod::Pix, PaymentMethod::Cash, PaymentMethod::Boleto])->mapWithKeys(static fn (PaymentMethod $m): array => [$m->value => $m->label()])->all(),
            'chargeMethods' => collect([PaymentMethod::Pix, PaymentMethod::Boleto, PaymentMethod::Card])->mapWithKeys(static fn (PaymentMethod $m): array => [$m->value => $m->label()])->all(),
            'entryLabels' => collect(\App\Enums\Finance\FinanceEntryType::cases())->mapWithKeys(static fn ($t): array => [$t->value => $t->label()])->all(),
            'timezone' => (string) config('app.business_timezone'),
        ])->title("Fatura {$invoice->number}");
    }

    /**
     * @param  Closure(): mixed  $action
     */
    private function attempt(Closure $action, string $success): bool
    {
        $this->resetErrorBag();
        $this->message = '';
        $this->failure = '';

        try {
            $action();
        } catch (BusinessRuleViolation $e) {
            $field = $e->details()['field'] ?? null;
            in_array($field, ['amount', 'reason'], true) ? $this->addError($field, $e->getMessage()) : $this->failure = $e->getMessage();

            return false;
        }

        $this->message = $success;

        return true;
    }
}

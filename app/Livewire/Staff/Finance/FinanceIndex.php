<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Finance;

use App\Actions\Finance\Staff\IssueInvoice;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Finance\Payment;
use App\Models\Finance\ReceivableInstallment;
use App\Models\Ordering\Order;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Contas a receber: pedidos com expedido a faturar, parcelas em aberto e
 * vencidas, pagamentos recebidos e eventos do gateway para conciliação.
 */
#[Layout('layouts.app')]
#[Title('Financeiro')]
final class FinanceIndex extends Component
{
    use WithPagination;

    public const array TABS = [
        'faturar' => 'A faturar',
        'aberto' => 'Em aberto',
        'vencidas' => 'Vencidas',
        'recebidos' => 'Recebidos',
        'eventos' => 'Eventos do gateway',
    ];

    #[Url(as: 'fila', except: 'faturar')]
    public string $tab = 'faturar';

    public string $message = '';

    public string $failure = '';

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function issue(string $orderId, IssueInvoice $issue): mixed
    {
        $this->message = '';
        $this->failure = '';

        try {
            $invoice = $issue->handle($orderId, (string) Str::uuid7());
        } catch (BusinessRuleViolation $e) {
            $this->failure = $e->getMessage();

            return null;
        }

        return $this->redirectRoute('finance.invoices.show', $invoice->id);
    }

    public function render(Access $access, Clock $clock): View
    {
        $tab = array_key_exists($this->tab, self::TABS) ? $this->tab : 'faturar';
        $today = $clock->now()->setTimezone((string) config('app.business_timezone'))->toDateString();

        $rows = match ($tab) {
            'faturar' => Order::query()->with('buyerCompany')
                ->whereIn('commercial_status', [CommercialStatus::Confirmed->value, CommercialStatus::PartiallyCancelled->value, CommercialStatus::Cancelled->value])
                ->whereExists(static fn ($q) => $q->from('order_items')->whereColumn('order_items.order_id', 'orders.id')->whereColumn('order_items.quantity_shipped', '>', 'order_items.quantity_invoiced'))
                ->orderBy('confirmed_at')->orderBy('id')->paginate(25),
            'aberto', 'vencidas' => ReceivableInstallment::query()->with('invoice.order.buyerCompany')
                ->where('status', 'open')
                ->when($tab === 'vencidas', static fn ($q) => $q->where('due_date', '<', $today))
                ->orderBy('due_date')->orderBy('id')->paginate(25),
            'recebidos' => Payment::query()->with('installment.invoice.order.buyerCompany')->where('status', PaymentStatus::Confirmed->value)
                ->orderByDesc('confirmed_at')->orderByDesc('id')->paginate(25),
            default => DB::table('gateway_webhook_events')->orderByDesc('received_at')->paginate(25),
        };

        return view('livewire.staff.finance.finance-index', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'rows' => $rows,
            'today' => $today,
            'canManage' => $access->can(Permission::FinanceManage),
            'timezone' => (string) config('app.business_timezone'),
        ]);
    }
}

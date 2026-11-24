<?php

declare(strict_types=1);

namespace Tests\Integration\Finance;

use App\Actions\Finance\Staff\IssueInvoice;
use App\Actions\Finance\Staff\RecordPayment;
use App\Enums\Finance\PaymentMethod;
use App\Models\Finance\Invoice;
use App\Models\Finance\Payment;
use App\Models\Finance\ReceivableInstallment;
use App\Models\Identity\Membership;
use App\Models\Ordering\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\OrderingScenario;

/**
 * Fluxo financeiro pelas Actions, para os testes de integração.
 */
final class FinanceFlow
{
    public static function invoice(OrderingScenario $scenario, Membership $staff, Order $order): Invoice
    {
        return $scenario->tenant->actingAs($staff, static fn (): Invoice => app(IssueInvoice::class)->handle($order->id, (string) Str::uuid7()));
    }

    public static function pay(OrderingScenario $scenario, Membership $staff, ReceivableInstallment $installment, string $amount): Payment
    {
        return $scenario->tenant->actingAs($staff, static fn (): Payment => app(RecordPayment::class)->handle($installment->id, $amount, PaymentMethod::Transfer, (string) Str::uuid7()));
    }

    public static function fresh(OrderingScenario $scenario, Invoice $invoice): Invoice
    {
        return $scenario->system(static fn (): Invoice => Invoice::query()->with(['installments.payments.refunds', 'items'])->findOrFail($invoice->id));
    }

    public static function firstInstallment(OrderingScenario $scenario, Invoice $invoice): ReceivableInstallment
    {
        return $scenario->system(static fn (): ReceivableInstallment => ReceivableInstallment::query()->where('invoice_id', $invoice->id)->orderBy('sequence')->firstOrFail());
    }

    /**
     * @return array{committed: int, receivable: int}
     */
    public static function credit(OrderingScenario $scenario, string $buyerCompanyId): array
    {
        $row = $scenario->system(static fn () => DB::table('credit_accounts')->where('buyer_company_id', $buyerCompanyId)->first(['committed_cents', 'receivable_cents']));

        return ['committed' => (int) $row->committed_cents, 'receivable' => (int) $row->receivable_cents];
    }
}

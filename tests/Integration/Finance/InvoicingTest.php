<?php

declare(strict_types=1);

use App\Actions\Finance\Staff\AdjustInstallment;
use App\Actions\Finance\Staff\RefundPayment;
use App\Domain\Finance\Exceptions\InvalidPayment;
use App\Domain\Finance\Exceptions\InvoiceNotAllowed;
use App\Domain\Finance\Exceptions\PaymentNotAllowed;
use App\Domain\Finance\Exceptions\RefundNotAllowed;
use App\Enums\Finance\InstallmentStatus;
use App\Enums\Finance\InvoiceStatus;
use App\Enums\Identity\SystemRole;
use App\Enums\Ordering\FinancialStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Integration\Finance\FinanceFlow as F;
use Tests\Integration\Fulfillment\FulfillmentFlow as S;
use Tests\Support\Builders\OrderingScenario;

/*
| Faturamento, pagamentos, reembolsos, créditos e ajustes (RF-100, RN-081,
| RN-083, RN-100, RN-101, ADR-028).
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '20', unitPriceCents: 10_000, creditLimitCents: 1_000_000, onCredit: true);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '6', sameCompany: true);
    $this->order = Fx::place($this->scenario, $this->buyer);
    $this->warehouse = $this->scenario->tenant->addStaff(SystemRole::Warehouse);
    $this->finance = $this->scenario->tenant->addStaff(SystemRole::Finance);
    $this->company = $this->buyer['company']->id;
});

it('fatura o expedido, divide em parcelas e transfere o comprometimento para recebível', function (): void {
    expect(F::credit($this->scenario, $this->company))->toBe(['committed' => 60_000, 'receivable' => 0])
        ->and(fn () => F::invoice($this->scenario, $this->finance, $this->order))->toThrow(InvoiceNotAllowed::class, 'expedida a faturar');

    S::shipAll($this->scenario, $this->warehouse, $this->order, '4');
    $invoice = F::invoice($this->scenario, $this->finance, $this->order);
    $stored = F::fresh($this->scenario, $invoice);

    expect($invoice->number)->toBe('FAT-0000001')
        ->and($stored->total_cents->cents)->toBe(40_000)
        ->and($stored->installments)->toHaveCount(1)
        ->and($stored->installments->first()->due_date->toDateString())->toBe(now((string) config('app.business_timezone'))->addDays(28)->toDateString())
        ->and(F::credit($this->scenario, $this->company))->toBe(['committed' => 20_000, 'receivable' => 40_000])
        ->and(Fx::fresh($this->scenario, $this->order)->financial_status)->toBe(FinancialStatus::PartiallyInvoiced)
        ->and(fn () => F::invoice($this->scenario, $this->finance, $this->order))->toThrow(InvoiceNotAllowed::class);

    $rest = S::create($this->scenario, $this->warehouse, $this->order, [S::item($this->scenario, $this->order)->id => '2']);
    S::check($this->scenario, $this->warehouse, $rest);
    S::ship($this->scenario, $this->warehouse, $rest);
    $second = F::invoice($this->scenario, $this->finance, $this->order);

    expect($second->number)->toBe('FAT-0000002')
        ->and(F::credit($this->scenario, $this->company))->toBe(['committed' => 0, 'receivable' => 60_000])
        ->and(Fx::fresh($this->scenario, $this->order)->financial_status)->toBe(FinancialStatus::Invoiced)
        ->and($this->scenario->system(fn (): int => DB::table('finance_entries')->where('type', 'invoice_issued')->count()))->toBe(2);
});

it('quita a parcela por pagamentos parciais e reduz o recebível só quando confirmado', function (): void {
    S::shipAll($this->scenario, $this->warehouse, $this->order, '6');
    $invoice = F::invoice($this->scenario, $this->finance, $this->order);
    $installment = F::firstInstallment($this->scenario, $invoice);

    expect(fn () => F::pay($this->scenario, $this->finance, $installment, '600,01'))->toThrow(InvalidPayment::class, 'saldo em aberto')
        ->and(fn () => F::pay($this->scenario, $this->finance, $installment, '0'))->toThrow(InvalidPayment::class, 'positivo');

    F::pay($this->scenario, $this->finance, $installment, '250,00');
    expect(F::firstInstallment($this->scenario, $invoice)->status)->toBe(InstallmentStatus::Open)
        ->and(F::credit($this->scenario, $this->company)['receivable'])->toBe(35_000)
        ->and(Fx::fresh($this->scenario, $this->order)->financial_status)->toBe(FinancialStatus::PartiallyPaid);

    F::pay($this->scenario, $this->finance, $installment, '350,00');
    $stored = F::fresh($this->scenario, $invoice);
    expect($stored->status)->toBe(InvoiceStatus::Paid)
        ->and($stored->paid_cents->cents)->toBe(60_000)
        ->and(F::credit($this->scenario, $this->company)['receivable'])->toBe(0)
        ->and(Fx::fresh($this->scenario, $this->order)->financial_status)->toBe(FinancialStatus::Paid)
        ->and(fn () => F::pay($this->scenario, $this->finance, $installment, '1,00'))->toThrow(PaymentNotAllowed::class, 'quitada');
});

it('reembolsa até o pago e reabre o devido da parcela', function (): void {
    S::shipAll($this->scenario, $this->warehouse, $this->order, '6');
    $invoice = F::invoice($this->scenario, $this->finance, $this->order);
    $payment = F::pay($this->scenario, $this->finance, F::firstInstallment($this->scenario, $invoice), '300,00');
    $refund = fn (string $amount) => $this->scenario->tenant->actingAs($this->finance, fn () => app(RefundPayment::class)->handle($payment->id, $amount, 'Devolução de duas unidades'));

    $refund('100,00');
    expect(fn () => $refund('200,01'))->toThrow(RefundNotAllowed::class, 'R$ 200,00')
        ->and(fn () => $this->scenario->tenant->actingAs($this->finance, fn () => app(RefundPayment::class)->handle($payment->id, '1,00', 'x')))->toThrow(InvalidPayment::class);

    $refund('200,00');
    $stored = F::fresh($this->scenario, $invoice);
    $storedPayment = $stored->installments->first()->payments->first();
    expect($storedPayment->refunded_cents->cents)->toBe(30_000)
        ->and($storedPayment->refund_pending_cents->cents)->toBe(0)
        ->and($stored->paid_cents->cents)->toBe(0)
        ->and(F::credit($this->scenario, $this->company)['receivable'])->toBe(60_000);

    // A soma dos reembolsos nunca passa do pago, nem por gravação direta (RN-101).
    expect(fn () => $this->scenario->system(fn () => DB::table('payments')->where('id', $payment->id)->update(['refund_pending_cents' => 1])))
        ->toThrow(Illuminate\Database\QueryException::class, 'payments_values_check');
});

it('concede crédito e ajusta a parcela por lançamento compensatório', function (): void {
    S::shipAll($this->scenario, $this->warehouse, $this->order, '6');
    $invoice = F::invoice($this->scenario, $this->finance, $this->order);
    $installment = F::firstInstallment($this->scenario, $invoice);
    $adjust = app(AdjustInstallment::class);

    $this->scenario->tenant->actingAs($this->finance, fn () => $adjust->credit($installment->id, '100,00', 'Devolução DEV-0000001'));
    $this->scenario->tenant->actingAs($this->finance, fn () => $adjust->adjust($installment->id, '15,00', 'Multa por atraso'));
    $this->scenario->tenant->actingAs($this->finance, fn () => $adjust->adjust($installment->id, '-5,00', 'Desconto de pontualidade'));

    $stored = F::fresh($this->scenario, $invoice);
    expect($stored->credited_cents->cents)->toBe(10_000)
        ->and($stored->adjusted_cents->cents)->toBe(1_000)
        ->and(App\Services\Finance\Receivables::open($stored->installments->first())->cents)->toBe(51_000)
        ->and(F::credit($this->scenario, $this->company)['receivable'])->toBe(51_000)
        ->and(fn () => $this->scenario->tenant->actingAs($this->finance, fn () => $adjust->credit($installment->id, '600,00', 'Crédito acima do devido')))->toThrow(InvalidPayment::class)
        ->and(fn () => $this->scenario->tenant->actingAs($this->finance, fn () => $adjust->adjust($installment->id, '-600,00', 'Ajuste acima do devido')))->toThrow(InvalidPayment::class)
        ->and(fn () => $this->scenario->tenant->actingAs($this->finance, fn () => $adjust->adjust($installment->id, '1,00', 'x')))->toThrow(InvalidPayment::class, 'motivo');

    // Lançamentos são somente inclusão (RN-100).
    expect(fn () => $this->scenario->system(fn () => DB::table('finance_entries')->delete()))->toThrow(Illuminate\Database\QueryException::class, 'permission denied');
});

it('exige permissão do financeiro e esconde faturas de outra empresa', function (): void {
    S::shipAll($this->scenario, $this->warehouse, $this->order, '6');
    $invoice = F::invoice($this->scenario, $this->finance, $this->order);
    $outsider = $this->scenario->buyerWithCart(quantity: '1');

    expect(fn () => F::invoice($this->scenario, $this->warehouse, $this->order))->toThrow(AuthorizationException::class)
        ->and($this->scenario->tenant->actingAs($outsider['membership'], fn (): int => DB::table('invoices')->count()))->toBe(0)
        ->and($this->scenario->tenant->actingAs($this->buyer['membership'], fn (): int => DB::table('invoices')->where('id', $invoice->id)->count()))->toBe(1)
        ->and($this->scenario->tenant->actingAs($this->buyer['membership'], fn (): int => DB::table('invoices')->update(['status' => 'paid'])))->toBe(0);
});

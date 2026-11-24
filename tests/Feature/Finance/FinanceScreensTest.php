<?php

declare(strict_types=1);

use App\Enums\Finance\InstallmentStatus;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Identity\SystemRole;
use App\Livewire\Finance\PaymentSimulator;
use App\Livewire\Portal\Finance\InvoiceShow as PortalInvoiceShow;
use App\Livewire\Staff\Finance\FinanceIndex;
use App\Livewire\Staff\Finance\GatewaySettings;
use App\Livewire\Staff\Finance\InvoiceShow;
use App\Models\Finance\Invoice;
use App\Models\Finance\Payment;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Integration\Finance\FinanceFlow as F;
use Tests\Integration\Fulfillment\FulfillmentFlow as S;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Telas do financeiro: fila, fatura com pagamento, cobrança, crédito,
| ajuste e reembolso, gateway, indicadores e, no portal, faturas pagas pelo
| simulador.
*/

beforeEach(function (): void {
    $this->withoutVite();
    config(['queue.default' => 'sync']);
    $this->scenario = OrderingScenario::create(stock: '20', unitPriceCents: 10_000, creditLimitCents: 1_000_000, onCredit: true);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->order = Fx::place($this->scenario, $this->buyer);
    // Papel com permissão privilegiada: a área da equipe exige 2FA.
    $this->finance = $this->scenario->tenant->addStaff(SystemRole::Finance, User::factory()->withTwoFactor()->create());
    S::shipAll($this->scenario, $this->scenario->tenant->addStaff(SystemRole::Warehouse), $this->order, '3');

    $this->page = function (string $url, ?Membership $membership = null): TestResponse {
        $membership ??= $this->finance;
        app()->forgetScopedInstances();

        return $this->actingAs(User::query()->findOrFail($membership->user_id))->withSession(TenantScenario::sessionFor($membership))->get($url);
    };
    $this->as = function (Closure $callback, ?Membership $membership = null): mixed {
        $membership ??= $this->finance;
        $user = User::query()->findOrFail($membership->user_id);

        return $this->scenario->tenant->actingAs($membership, static function () use ($user, $callback): mixed {
            Livewire::actingAs($user);

            return $callback();
        });
    };
});

it('fatura pela fila e opera a fatura pela tela', function (): void {
    ($this->page)(route('finance.index'))->assertOk()->assertSeeText($this->order->number)->assertSeeText('Faturar o expedido');
    ($this->as)(fn () => Livewire::test(FinanceIndex::class)->call('issue', $this->order->id)->assertRedirect());

    $invoice = $this->scenario->system(fn (): Invoice => Invoice::query()->with('installments')->firstOrFail());
    $installment = $invoice->installments->first();

    ($this->as)(fn () => Livewire::test(InvoiceShow::class, ['invoice' => $invoice->id])
        ->call('select', $installment->id, 'payment')
        ->set('amount', '999,00')
        ->call('submit')
        ->assertHasErrors('amount')
        ->set('amount', '100,00')
        ->call('submit')
        ->assertSee('Pagamento registrado.')
        ->call('select', $installment->id, 'credit')
        ->set('amount', '50,00')
        ->set('reason', 'Devolução combinada')
        ->call('submit')
        ->assertSee('Crédito concedido.')
        ->call('select', $installment->id, 'adjust')
        ->set('amount', '10,00')
        ->set('reason', 'Multa por atraso')
        ->call('submit')
        ->assertSee('Ajuste registrado.')
        ->call('select', $installment->id, 'charge')
        ->set('method', 'pix')
        ->call('submit')
        ->assertSee('Cobrança solicitada ao gateway'));

    // Criada no gateway depois do commit: o link aparece na tela.
    ($this->as)(fn () => Livewire::test(InvoiceShow::class, ['invoice' => $invoice->id])->assertSee('link de pagamento'));

    $payment = $this->scenario->system(fn (): Payment => Payment::query()->where('status', PaymentStatus::Confirmed->value)->firstOrFail());
    ($this->as)(fn () => Livewire::test(InvoiceShow::class, ['invoice' => $invoice->id])
        ->call('startRefund', $payment->id)
        ->set('amount', '200,00')
        ->set('reason', 'Reembolso parcial')
        ->call('refund')
        ->assertSee('passa do valor elegível')
        ->set('amount', '40,00')
        ->call('refund')
        ->assertSee('Reembolso efetuado.')
        ->assertSee('Crédito concedido'));

    expect(App\Services\Finance\Receivables::open(F::firstInstallment($this->scenario, $invoice))->cents)->toBe(30_000 - 10_000 - 5_000 + 1_000 + 4_000);
    ($this->page)(route('finance.index', ['fila' => 'aberto']))->assertOk()->assertSeeText($invoice->number);
    ($this->page)(route('finance.index', ['fila' => 'recebidos']))->assertOk()->assertSeeText($invoice->number);
    ($this->page)(route('finance.index', ['fila' => 'eventos']))->assertOk();
});

it('o comprador paga a parcela pelo simulador', function (): void {
    $invoice = F::invoice($this->scenario, $this->finance, $this->order);
    $installment = F::firstInstallment($this->scenario, $invoice);

    ($this->page)(route('portal.invoices'), $this->buyer['membership'])->assertOk()->assertSeeText($invoice->number);
    ($this->as)(fn () => Livewire::test(PortalInvoiceShow::class, ['invoice' => $invoice->id])
        ->assertSee('não é nota fiscal')
        ->set('method', 'boleto')
        ->call('pay', $installment->id)
        ->assertSee('Gerando a cobrança no gateway'), $this->buyer['membership']);

    $payment = $this->scenario->system(fn (): Payment => Payment::query()->firstOrFail());
    ($this->as)(fn () => Livewire::test(PortalInvoiceShow::class, ['invoice' => $invoice->id])
        ->assertSee('Abrir o pagamento (Boleto)')
        ->assertSee(route('payments.simulator', $payment->id, false)), $this->buyer['membership']);
    ($this->page)(route('payments.simulator', $payment->id), $this->buyer['membership'])->assertOk()->assertSeeText('R$ 300,00');
    ($this->as)(fn () => Livewire::test(PaymentSimulator::class, ['payment' => $payment->id])->call('simulate', true)->assertSee('Pagamento simulado'), $this->buyer['membership']);

    expect(F::firstInstallment($this->scenario, $invoice)->status)->toBe(InstallmentStatus::Paid);
    ($this->page)(route('portal.invoices.show', $invoice->id), $this->buyer['membership'])->assertOk()->assertSeeText('Quitada');
    ($this->page)(route('portal.invoices.show', $invoice->id), $this->scenario->buyerWithCart(quantity: '1')['membership'])->assertNotFound();
});

it('configura o Asaas mostrando o token do webhook uma vez', function (): void {
    ($this->as)(fn () => Livewire::test(GatewaySettings::class)
        ->set('gateway', 'asaas')
        ->call('save')
        ->assertHasErrors('apiKey')
        ->set('apiKey', 'aact_sandbox_'.str_repeat('a', 30))
        ->call('save')
        ->assertSee('Gateway atualizado.')
        ->assertSee('Token do webhook')
        ->assertSee(route('webhooks.asaas', $this->scenario->tenant->organization->id)));

    expect($this->scenario->system(fn (): string => (string) Illuminate\Support\Facades\DB::table('payment_gateway_settings')->value('api_key_encrypted')))->not->toContain('aact_sandbox');
    ($this->page)(route('finance.gateway'), $this->scenario->tenant->addStaff(SystemRole::Viewer))->assertForbidden();
});

it('mostra os indicadores do período com as fórmulas', function (): void {
    $invoice = F::invoice($this->scenario, $this->finance, $this->order);

    ($this->page)(route('reports.indicators', ['de' => now()->subMonth()->toDateString(), 'ate' => now()->addMonths(2)->toDateString()]))
        ->assertOk()
        ->assertSeeText('Ticket médio')
        ->assertSeeText('R$ 300,00')
        ->assertSeeText('Inadimplência')
        ->assertSeeText('÷');
    ($this->page)(route('reports.indicators', ['de' => 'x']))->assertOk();
    expect($invoice->number)->toBe('FAT-0000001');
});

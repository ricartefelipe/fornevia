<?php

declare(strict_types=1);

use App\Actions\Finance\Portal\PayInstallment;
use App\Actions\Finance\Staff\ChargeInstallment;
use App\Actions\Finance\Staff\SavePaymentGatewaySettings;
use App\Actions\Finance\System\GatewayEvents;
use App\Actions\Finance\System\ReconcilePayments;
use App\Actions\Finance\System\SimulatePayment;
use App\Domain\Finance\Exceptions\InvalidPayment;
use App\Enums\Finance\InstallmentStatus;
use App\Enums\Finance\PaymentGatewayKind;
use App\Enums\Finance\PaymentMethod;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Finance\Payment;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Integration\Finance\FinanceFlow as F;
use Tests\Integration\Fulfillment\FulfillmentFlow as S;
use Tests\Support\Builders\OrderingScenario;

/*
| Gateway de pagamento (RF-101, RF-112, RS-100, ADR-028): simulador local,
| Asaas sem cobrança repetida depois de timeout, webhook autenticado e
| deduplicado e conciliação.
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    $this->scenario = OrderingScenario::create(stock: '20', unitPriceCents: 10_000, creditLimitCents: 1_000_000, onCredit: true);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $order = Fx::place($this->scenario, $this->buyer);
    $this->finance = $this->scenario->tenant->addStaff(SystemRole::Finance);
    S::shipAll($this->scenario, $this->scenario->tenant->addStaff(SystemRole::Warehouse), $order, '3');
    $this->invoice = F::invoice($this->scenario, $this->finance, $order);
    $this->installment = F::firstInstallment($this->scenario, $this->invoice);
    $this->pay = fn (PaymentMethod $method = PaymentMethod::Pix): Payment => $this->scenario->tenant->actingAs($this->buyer['membership'], fn (): Payment => app(PayInstallment::class)->handle($this->installment->id, $method));
    $this->payment = fn (Payment $p): Payment => $this->scenario->system(static fn (): Payment => Payment::query()->findOrFail($p->id));
    $this->asaas = function (): string {
        return $this->scenario->tenant->actingAs($this->finance, static fn (): ?string => app(SavePaymentGatewaySettings::class)->handle(PaymentGatewayKind::Asaas, 'aact_teste_'.str_repeat('x', 20), false)) ?? '';
    };
    $this->asaasCharge = ['id' => 'pay_123', 'status' => 'PENDING', 'value' => 300.0, 'invoiceUrl' => 'https://sandbox.asaas.com/i/pay_123'];
});

it('cobra pelo simulador, reaproveita a cobrança pendente e confirma pelo evento', function (): void {
    $payment = ($this->pay)();

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->gateway_charge_id)->toBe('sim_'.$payment->id)
        ->and($payment->checkout_url)->toBe("/simulador-de-pagamento/{$payment->id}")
        ->and(($this->pay)(PaymentMethod::Boleto)->id)->toBe($payment->id)
        ->and(fn () => ($this->pay)(PaymentMethod::Transfer))->toThrow(InvalidPayment::class);

    $this->scenario->tenant->actingAs($this->buyer['membership'], fn () => app(SimulatePayment::class)->handle($payment->id, true));

    $stored = ($this->payment)($payment);
    expect($stored->status)->toBe(PaymentStatus::Confirmed)
        ->and($stored->fee_cents->cents)->toBe(300)
        ->and(F::firstInstallment($this->scenario, $this->invoice)->status)->toBe(InstallmentStatus::Paid)
        ->and(F::credit($this->scenario, $this->buyer['company']->id)['receivable'])->toBe(0);

    // O mesmo evento de novo não tem efeito.
    $events = app(GatewayEvents::class);
    $tenant = $this->scenario->tenant->organization->id;
    expect($this->scenario->system(fn (): bool => $events->record($tenant, PaymentGatewayKind::Simulator, 'evt-1', 'PAYMENT_RECEIVED', $stored->gateway_charge_id, ['value_cents' => 1])))->toBeTrue()
        ->and($this->scenario->system(fn (): bool => $events->record($tenant, PaymentGatewayKind::Simulator, 'evt-1', 'PAYMENT_RECEIVED', $stored->gateway_charge_id, ['value_cents' => 1])))->toBeFalse()
        ->and(($this->payment)($payment)->amount_cents->cents)->toBe(30_000);
});

it('encerra a cobrança recusada no simulador sem quitar', function (): void {
    $payment = $this->scenario->tenant->actingAs($this->finance, fn (): Payment => app(ChargeInstallment::class)->handle($this->installment->id, PaymentMethod::Boleto));
    $this->scenario->tenant->actingAs($this->finance, fn () => app(SimulatePayment::class)->handle($payment->id, false));

    expect(($this->payment)($payment)->status)->toBe(PaymentStatus::Failed)
        ->and(F::firstInstallment($this->scenario, $this->invoice)->status)->toBe(InstallmentStatus::Open)
        ->and(($this->pay)()->id)->not->toBe($payment->id);
});

it('não repete a cobrança no Asaas quando a criação esgota o tempo depois de criada', function (): void {
    ($this->asaas)();
    $created = false;
    $posts = [];
    Http::fake(function (Request $request) use (&$created, &$posts) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $request->method() === 'GET' && str_ends_with($path, '/customers') => Http::response(['data' => []]),
            $request->method() === 'POST' && str_ends_with($path, '/customers') => Http::response(['id' => 'cus_1']),
            $request->method() === 'GET' && str_ends_with($path, '/payments') => Http::response(['data' => $created ? [$this->asaasCharge] : []]),
            $request->method() === 'POST' && str_ends_with($path, '/payments') => (function () use (&$created, &$posts, $request): never {
                $created = true;
                $posts[] = $request->data();

                throw new ConnectionException('Tempo esgotado');
            })(),
            default => Http::response([], 404),
        };
    });

    $payment = ($this->pay)(PaymentMethod::Boleto);

    expect($payment->gateway_charge_id)->toBe('pay_123')
        ->and($payment->checkout_url)->toBe('https://sandbox.asaas.com/i/pay_123');
    // Uma única criação, com a referência do pagamento; depois do timeout, só consulta.
    expect($posts)->toHaveCount(1)
        ->and($posts[0]['externalReference'])->toBe($payment->id)
        ->and($posts[0]['billingType'])->toBe('BOLETO')
        ->and($posts[0]['value'])->toEqual(300);
});

it('recebe o webhook do Asaas com o token do distribuidor, uma vez por evento', function (): void {
    $token = ($this->asaas)();
    Http::fake([
        '*/customers*' => Http::response(['data' => [['id' => 'cus_1']]]),
        '*/payments*' => Http::sequence()->push(['data' => []])->push($this->asaasCharge),
    ]);
    $payment = ($this->pay)();
    $url = route('webhooks.asaas', $this->scenario->tenant->organization->id);
    $event = ['id' => 'evt_1', 'event' => 'PAYMENT_RECEIVED', 'payment' => [...$this->asaasCharge, 'status' => 'RECEIVED', 'netValue' => 297.01]];

    $this->postJson($url, $event, ['asaas-access-token' => 'errado'])->assertUnauthorized();
    $this->postJson(route('webhooks.asaas', 'x'), $event)->assertNotFound();
    $this->postJson($url, ['id' => 'evt_2'], ['asaas-access-token' => $token])->assertUnprocessable();
    $this->postJson($url, $event, ['asaas-access-token' => $token])->assertOk()->assertJson(['received' => true, 'duplicate' => false]);
    $this->postJson($url, $event, ['asaas-access-token' => $token])->assertOk()->assertJson(['duplicate' => true]);

    $stored = ($this->payment)($payment);
    expect($stored->status)->toBe(PaymentStatus::Confirmed)
        ->and($stored->fee_cents->cents)->toBe(299)
        ->and(F::firstInstallment($this->scenario, $this->invoice)->paid_cents->cents)->toBe(30_000);
});

it('deixa a cobrança pendente quando o Asaas não responde e a cria na conciliação', function (): void {
    ($this->asaas)();
    // Um único falso de HTTP, com o estado do Asaas controlado pelo teste.
    $state = 'down';
    $charge = $this->asaasCharge;
    Http::fake(function (Request $request) use (&$state, $charge) {
        if ($state === 'down') {
            throw new ConnectionException('Fora do ar');
        }

        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '/customers') => Http::response(['data' => [['id' => 'cus_1']]]),
            $request->method() === 'GET' && str_ends_with($path, '/payments') => Http::response(['data' => $state === 'paid' ? [[...$charge, 'status' => 'CONFIRMED', 'netValue' => 300]] : ($state === 'created' ? [$charge] : [])]),
            $request->method() === 'POST' && str_ends_with($path, '/payments') => (function () use (&$state, $charge) {
                $state = 'created';

                return Http::response($charge);
            })(),
            default => Http::response([], 404),
        };
    });

    $payment = ($this->pay)();
    expect($payment->status)->toBe(PaymentStatus::Pending)->and($payment->gateway_charge_id)->toBeNull()->and($payment->failure_reason)->not->toBeNull();

    $state = 'up';
    app()->instance(Clock::class, new FrozenClock(now()->addMinutes(11)->toImmutable()));

    expect(app(ReconcilePayments::class)->handle())->toBe(['checked' => 1, 'settled' => 0, 'created' => 1, 'failed' => 0])
        ->and(($this->payment)($payment)->gateway_charge_id)->toBe('pay_123');

    // Pago no Asaas sem webhook: a conciliação aplica.
    $state = 'paid';
    expect(app(ReconcilePayments::class)->handle()['settled'])->toBe(1)
        ->and(($this->payment)($payment)->status)->toBe(PaymentStatus::Confirmed);

    $this->artisan('payments:reconcile')->expectsOutputToContain('Conferidos: 0')->assertSuccessful();
});

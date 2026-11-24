<?php

declare(strict_types=1);

use App\Domain\Finance\Exceptions\RefundNotAllowed;
use App\Enums\Identity\SystemRole;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Integration\Finance\FinanceFlow as F;
use Tests\Integration\Fulfillment\FulfillmentFlow as S;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Concurrency\ConcurrencyHarness;

/*
 * Reembolsos concorrentes do mesmo pagamento, com processos separados
 * (RN-101): cada um cabe sozinho, os dois juntos passam do pago; só um é
 * efetivado.
 */
it('não deixa dois reembolsos simultâneos passarem do valor pago', function (): void {
    $scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $buyer = $scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $order = Fx::place($scenario, $buyer);
    S::shipAll($scenario, $scenario->tenant->addStaff(SystemRole::Warehouse), $order, '3');
    $finance = $scenario->tenant->addStaff(SystemRole::Finance);
    $invoice = F::invoice($scenario, $finance, $order);
    $payment = F::pay($scenario, $finance, F::firstInstallment($scenario, $invoice), '300,00');
    $args = fn (array $extra = []): array => [
        'tenant_id' => $scenario->tenant->organization->id, 'user_id' => $finance->user_id, 'membership_id' => $finance->id,
        'payment_id' => $payment->id, 'amount' => '200,00', ...$extra,
    ];

    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('primeiro-reembolso');

    try {
        $first = $harness->start('refund-payment', $args(['hold' => 'primeiro-reembolso']), 'reembolso-a');
        $harness->waitUntilWaitingAt('primeiro-reembolso', 1);
        $second = $harness->start('refund-payment', $args(), 'reembolso-b');
        $harness->waitUntilBlockedOnRow('reembolso-b', 'orders');
        $hold->release();
        [$ra, $rb] = [$first->result(), $second->result()];
    } finally {
        $harness->shutdown();
    }

    $row = $scenario->system(fn () => DB::table('payments')->where('id', $payment->id)->first(['refunded_cents', 'refund_pending_cents']));

    expect($ra['ok'])->toBeTrue()
        ->and($rb['ok'])->toBeFalse()
        ->and($rb['error'])->toBe(RefundNotAllowed::class)
        ->and((int) $row->refunded_cents)->toBe(20_000)
        ->and((int) $row->refund_pending_cents)->toBe(0);
});

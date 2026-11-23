<?php

declare(strict_types=1);

use App\Domain\Credit\Exceptions\CreditNotApproved;
use Illuminate\Support\Facades\DB;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Concurrency\ConcurrencyHarness;

/*
 * Dois pedidos a prazo da mesma empresa disputam o mesmo limite: cada um
 * cabe sozinho, os dois juntos não. Os pedidos usam SKUs diferentes, para
 * que o recurso disputado seja a conta de crédito (e não o saldo de
 * estoque); apenas um pedido compromete o limite.
 */
it('não permite que duas compras simultâneas consumam o mesmo limite disponível', function (): void {
    $scenario = OrderingScenario::create(stock: '100', unitPriceCents: 10_000, creditLimitCents: 150_000, onCredit: true);
    $first = $scenario->buyerWithCart(quantity: '10', sameCompany: true);
    $otherSku = $scenario->addSku(stock: '100');
    $second = $scenario->buyerWithCart(quantity: '10', sameCompany: true, sku: $otherSku);

    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('primeiro-segura');

    try {
        $a = $harness->start('place-order', $scenario->workerArgs($first, ['hold' => 'primeiro-segura']), 'pedido-a');
        $harness->waitUntilWaitingAt('primeiro-segura', 1);
        $b = $harness->start('place-order', $scenario->workerArgs($second), 'pedido-b');
        $harness->waitUntilBlockedOnLock('pedido-b');
        $hold->release();
        [$ra, $rb] = [$a->result(), $b->result()];
    } finally {
        $harness->shutdown();
    }

    expect($ra['ok'])->toBeTrue()
        ->and($rb['ok'])->toBeFalse()
        ->and($rb['error'])->toBe(CreditNotApproved::class);

    $state = $scenario->system(fn (): array => [
        'committed' => (int) DB::table('credit_accounts')->value('committed_cents'),
        'movements' => DB::table('credit_movements')->count(),
        'orders' => DB::table('orders')->count(),
        'reserved' => (string) DB::table('stock_levels')->where('sku_id', $scenario->sku->id)->value('reserved'),
        // A reserva de estoque do pedido recusado também foi desfeita.
        'reserved_refused' => (string) DB::table('stock_levels')->where('sku_id', $otherSku->id)->value('reserved'),
    ]);

    expect($state)->toBe(['committed' => 100_000, 'movements' => 1, 'orders' => 1, 'reserved' => '10.000', 'reserved_refused' => '0.000']);
});

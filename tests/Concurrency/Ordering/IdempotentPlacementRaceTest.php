<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Concurrency\ConcurrencyHarness;

/*
 * Duplo envio da confirmação com a mesma chave de idempotência, em
 * processos separados: o segundo espera no índice único e recebe o mesmo
 * pedido; não há segunda reserva nem segundo comprometimento.
 */
it('gera um único pedido para envios simultâneos com a mesma chave', function (): void {
    $scenario = OrderingScenario::create(stock: '10', onCredit: true, creditLimitCents: 1_000_000);
    $buyer = $scenario->buyerWithCart(quantity: '2');
    $args = $scenario->workerArgs($buyer, ['idempotency_key' => 'checkout-duplicado-0001']);

    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('primeiro-envio');

    try {
        $a = $harness->start('place-order', [...$args, 'hold' => 'primeiro-envio'], 'envio-a');
        $harness->waitUntilWaitingAt('primeiro-envio', 1);
        $b = $harness->start('place-order', $args, 'envio-b');
        $harness->waitUntilBlockedOnLock('envio-b');
        $hold->release();
        [$ra, $rb] = [$a->result(), $b->result()];
    } finally {
        $harness->shutdown();
    }

    expect($ra['ok'])->toBeTrue()
        ->and($rb['ok'])->toBeTrue()
        ->and($rb['data']['order_id'])->toBe($ra['data']['order_id']);

    expect($scenario->system(fn (): array => [
        DB::table('orders')->count(),
        DB::table('stock_reservations')->count(),
        DB::table('credit_movements')->count(),
        DB::table('outbox_messages')->where('type', 'order.confirmed')->count(),
    ]))->toBe([1, 1, 1, 1]);
});

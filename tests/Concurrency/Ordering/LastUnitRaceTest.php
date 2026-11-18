<?php

declare(strict_types=1);

use App\Domain\Inventory\Exceptions\InsufficientStock;
use Illuminate\Support\Facades\DB;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Concurrency\ConcurrencyHarness;

/*
 * Duas compras disputam a última unidade em processos separados.
 *
 * Sincronização determinística: o comprador A confirma e segura a
 * transação aberta (locks adquiridos) num portão; só então B é iniciado,
 * e o teste confirma em pg_stat_activity que B está bloqueado no lock do
 * saldo. Ao liberar A, B relê o saldo atualizado e é recusado.
 */
it('confirma apenas uma das compras simultâneas pela última unidade', function (): void {
    $scenario = OrderingScenario::create(stock: '1');
    $a = $scenario->buyerWithCart(quantity: '1');
    $b = $scenario->buyerWithCart(quantity: '1');

    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('a-segura-commit');

    try {
        $first = $harness->start('place-order', $scenario->workerArgs($a, ['hold' => 'a-segura-commit']), 'comprador-a');
        $harness->waitUntilWaitingAt('a-segura-commit', 1);

        $second = $harness->start('place-order', $scenario->workerArgs($b), 'comprador-b');
        $harness->waitUntilBlockedOnLock('comprador-b');

        $hold->release();
        [$ra, $rb] = [$first->result(), $second->result()];
    } finally {
        $harness->shutdown();
    }

    expect($ra['ok'])->toBeTrue()
        ->and($rb['ok'])->toBeFalse()
        ->and($rb['error'])->toBe(InsufficientStock::class);

    $state = $scenario->system(fn (): array => [
        'orders' => DB::table('orders')->count(),
        'reserved' => (string) DB::table('stock_levels')->value('reserved'),
        'reservations' => DB::table('stock_reservations')->count(),
        'open_carts' => DB::table('carts')->where('status', 'open')->count(),
    ]);

    expect($state)->toBe(['orders' => 1, 'reserved' => '1.000', 'reservations' => 1, 'open_carts' => 1]);
});

it('mantém o estoque reservado igual à soma das reservas ativas após disputa simultânea', function (): void {
    $scenario = OrderingScenario::create(stock: '5');
    $buyers = array_map(fn (int $i) => $scenario->buyerWithCart(quantity: '2'), range(1, 4));

    $harness = new ConcurrencyHarness;
    $gate = $harness->gate('largada');

    try {
        $workers = array_map(fn ($buyer, int $i) => $harness->start('place-order', $scenario->workerArgs($buyer, ['gate' => 'largada']), "comprador-{$i}"), $buyers, array_keys($buyers));
        $harness->waitUntilWaitingAt('largada', 4);
        $gate->release();
        $results = array_map(static fn ($w): array => $w->result(), $workers);
    } finally {
        $harness->shutdown();
    }

    $succeeded = array_filter($results, static fn (array $r): bool => $r['ok']);
    expect($succeeded)->toHaveCount(2);

    $state = $scenario->system(fn (): array => [
        'reserved' => (string) DB::table('stock_levels')->value('reserved'),
        'active' => (string) DB::table('stock_reservations')->where('status', 'active')->sum('quantity'),
        'on_hand' => (string) DB::table('stock_levels')->value('on_hand'),
    ]);

    expect($state['reserved'])->toBe('4.000')
        ->and(bccomp($state['active'], '4', 3))->toBe(0)
        ->and($state['on_hand'])->toBe('5.000');
});

<?php

declare(strict_types=1);

use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\SystemRole;
use App\Services\Inventory\StockLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Inventory\InventoryFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Concurrency\ConcurrencyHarness;

/*
 * Ajuste negativo de estoque concorrente com a reserva de um pedido, em
 * processos separados: o físico nunca fica abaixo do reservado (RN-071).
 *
 * Sincronização determinística: um dos lados conclui a operação e segura a
 * transação aberta (com o lock do saldo) num portão; o outro é iniciado e o
 * teste confirma em pg_stat_activity que ele está bloqueado nesse lock. Ao
 * liberar o portão, o segundo relê o saldo atualizado e é recusado.
 */

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '5');
    $this->stockist = $this->scenario->tenant->addStaff(SystemRole::Warehouse);

    $this->adjustArgs = fn (string $delta, array $overrides = []): array => [
        'tenant_id' => $this->scenario->tenant->organization->id,
        'actor_user_id' => $this->stockist->user_id,
        'actor_membership_id' => $this->stockist->id,
        'warehouse_id' => $this->scenario->warehouse->id,
        'sku_id' => $this->scenario->sku->id,
        'delta' => $delta,
        'reason' => 'Avaria identificada na conferência',
        ...$overrides,
    ];

    $this->state = fn (): array => $this->scenario->system(static fn (): array => [
        'on_hand' => (string) DB::table('stock_levels')->value('on_hand'),
        'reserved' => (string) DB::table('stock_levels')->value('reserved'),
        'orders' => DB::table('orders')->count(),
        'adjustments' => DB::table('stock_movements')->where('type', 'adjustment_out')->count(),
    ]);
});

it('recusa o ajuste negativo que espera a reserva concorrente e deixaria o físico abaixo do reservado', function (): void {
    $buyer = $this->scenario->buyerWithCart(quantity: '4');

    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('pedido-segura-commit');

    try {
        $order = $harness->start('place-order', $this->scenario->workerArgs($buyer, ['hold' => 'pedido-segura-commit']), 'comprador');
        $harness->waitUntilWaitingAt('pedido-segura-commit', 1);

        $adjust = $harness->start('adjust-stock', ($this->adjustArgs)('-3'), 'estoquista');
        $harness->waitUntilBlockedOnLock('estoquista');

        $hold->release();
        [$ro, $ra] = [$order->result(), $adjust->result()];
    } finally {
        $harness->shutdown();
    }

    expect($ro['ok'])->toBeTrue()
        ->and($ra['ok'])->toBeFalse()
        ->and($ra['error'])->toBe(InsufficientStock::class)
        ->and($ra['message'])->toContain('(físico 5, reservado 4, disponível 1)')
        ->and(($this->state)())->toBe(['on_hand' => '5.000', 'reserved' => '4.000', 'orders' => 1, 'adjustments' => 0])
        ->and(Fx::divergences($this->scenario->tenant))->toBe([]);
});

it('recusa a reserva que espera o ajuste negativo concorrente já aplicado', function (): void {
    $buyer = $this->scenario->buyerWithCart(quantity: '4');

    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('ajuste-segura-commit');

    try {
        $adjust = $harness->start('adjust-stock', ($this->adjustArgs)('-3', ['hold' => 'ajuste-segura-commit']), 'estoquista');
        $harness->waitUntilWaitingAt('ajuste-segura-commit', 1);

        $order = $harness->start('place-order', $this->scenario->workerArgs($buyer), 'comprador');
        $harness->waitUntilBlockedOnLock('comprador');

        $hold->release();
        [$ra, $ro] = [$adjust->result(), $order->result()];
    } finally {
        $harness->shutdown();
    }

    expect($ra['ok'])->toBeTrue()
        ->and($ra['data'])->toBe(['on_hand' => '2.000', 'reserved' => '0.000'])
        ->and($ro['ok'])->toBeFalse()
        ->and($ro['error'])->toBe(InsufficientStock::class)
        ->and(($this->state)())->toBe(['on_hand' => '2.000', 'reserved' => '0.000', 'orders' => 0, 'adjustments' => 1])
        ->and(Fx::divergences($this->scenario->tenant))->toBe([]);
});

it('garante no banco o físico acima do reservado mesmo para um ajuste concorrente sem a verificação da Action', function (): void {
    $buyer = $this->scenario->buyerWithCart(quantity: '4');

    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('pedido-segura-commit');

    try {
        $order = $harness->start('place-order', $this->scenario->workerArgs($buyer, ['hold' => 'pedido-segura-commit']), 'comprador');
        $harness->waitUntilWaitingAt('pedido-segura-commit', 1);

        // Sem SELECT ... FOR UPDATE: o worker só bloqueia no UPDATE do trigger.
        $adjust = $harness->start('adjust-stock-ledger', ($this->adjustArgs)('-3'), 'ledger-direto');
        $harness->waitUntilBlockedOnLock('ledger-direto');

        $hold->release();
        [$ro, $ra] = [$order->result(), $adjust->result()];
    } finally {
        $harness->shutdown();
    }

    expect($ro['ok'])->toBeTrue()
        ->and($ra['ok'])->toBeFalse()
        ->and($ra['error'])->toBe(QueryException::class)
        ->and($ra['message'])->toContain('stock_levels_reserved_lte_on_hand')
        ->and(($this->state)())->toBe(['on_hand' => '5.000', 'reserved' => '4.000', 'orders' => 1, 'adjustments' => 0]);
});

it('mantém o físico acima do reservado com vários ajustes e pedidos simultâneos', function (): void {
    $this->scenario->system(fn () => app(StockLedger::class)->receive($this->scenario->warehouse->id, $this->scenario->sku->id, Quantity::of('5'), 'Reforço do cenário'));
    $buyers = array_map(fn (int $i) => $this->scenario->buyerWithCart(quantity: '3'), range(1, 3));

    $harness = new ConcurrencyHarness;
    $gate = $harness->gate('largada');

    try {
        $workers = [
            ...array_map(fn (array $buyer, int $i) => $harness->start('place-order', $this->scenario->workerArgs($buyer, ['gate' => 'largada']), "comprador-{$i}"), $buyers, array_keys($buyers)),
            ...array_map(fn (int $i) => $harness->start('adjust-stock', ($this->adjustArgs)('-2', ['gate' => 'largada']), "estoquista-{$i}"), range(1, 3)),
        ];
        $harness->waitUntilWaitingAt('largada', 6);
        $gate->release();
        $results = array_map(static fn ($worker): array => $worker->result(), $workers);
    } finally {
        $harness->shutdown();
    }

    foreach ($results as $result) {
        expect($result['ok'] || $result['error'] === InsufficientStock::class)->toBeTrue(json_encode($result, JSON_THROW_ON_ERROR));
    }

    $state = ($this->state)();
    $placed = count(array_filter(array_slice($results, 0, 3), static fn (array $r): bool => $r['ok']));
    $adjusted = count(array_filter(array_slice($results, 3), static fn (array $r): bool => $r['ok']));

    expect($state['orders'])->toBe($placed)
        ->and($state['adjustments'])->toBe($adjusted)
        ->and(bccomp($state['reserved'], (string) (3 * $placed), 3))->toBe(0)
        ->and(bccomp($state['on_hand'], (string) (10 - 2 * $adjusted), 3))->toBe(0)
        ->and(bccomp($state['on_hand'], $state['reserved'], 3))->toBeGreaterThanOrEqual(0)
        ->and($placed + $adjusted)->toBeGreaterThan(0)
        ->and(Fx::divergences($this->scenario->tenant))->toBe([]);
});

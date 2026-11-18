<?php

declare(strict_types=1);

use App\Actions\Inventory\AdjustStock;
use App\Actions\Inventory\TransferStock;
use App\Data\Inventory\StockBalance;
use App\Data\Inventory\StockTransferResult;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\SystemRole;
use App\Models\Inventory\Warehouse;
use App\Services\Inventory\StockLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Inventory\InventoryFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;

/*
| Movimentações manuais diante de reservas reais (pedidos confirmados pelo
| checkout): o físico nunca fica abaixo do reservado (RN-071), a regra é
| verificada na Action e garantida pelo banco, e os saldos continuam
| derivando dos movimentos (RN-070).
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '10');
    $this->tenant = $this->scenario->tenant;
    $this->cd1 = $this->scenario->warehouse;
    $this->sku = $this->scenario->sku;
    $this->cd2 = Fx::warehouse($this->tenant, 'CD2', ['priority' => 5]);
    $this->stockist = $this->tenant->addStaff(SystemRole::Warehouse);

    $this->adjust = fn (Warehouse $warehouse, string $delta, string $reason = 'Perda identificada na contagem'): StockBalance => $this->tenant->actingAs(
        $this->stockist,
        fn (): StockBalance => app(AdjustStock::class)->handle($warehouse, $this->sku, Quantity::of($delta), $reason),
    );

    $this->transfer = fn (Warehouse $from, Warehouse $to, string $quantity): StockTransferResult => $this->tenant->actingAs(
        $this->stockist,
        fn (): StockTransferResult => app(TransferStock::class)->handle($from, $to, $this->sku, Quantity::of($quantity)),
    );
});

it('nunca deixa o físico abaixo do reservado nem negativo em ajuste de saída', function (): void {
    Fx::confirmOrder($this->scenario, '7');
    $code = $this->sku->code;

    expect(fn () => ($this->adjust)($this->cd1, '-4'))
        ->toThrow(InsufficientStock::class, "O ajuste de saída de 4 UN excede o disponível do SKU {$code} no depósito CD1 (físico 10, reservado 7, disponível 3).")
        ->and(Fx::level($this->tenant, $this->cd1, $this->sku))->toBe(['on_hand' => '10.000', 'reserved' => '7.000'])
        ->and(Fx::movementCount($this->tenant, 'adjustment_out'))->toBe(0);

    // Exatamente o disponível: físico igual ao reservado, e nada além disso.
    $balance = ($this->adjust)($this->cd1, '-3');

    expect($balance->onHand->toDatabase())->toBe('7.000')
        ->and($balance->available()->isZero())->toBeTrue()
        ->and(fn () => ($this->adjust)($this->cd1, '-1'))->toThrow(InsufficientStock::class)
        ->and(Fx::level($this->tenant, $this->cd1, $this->sku))->toBe(['on_hand' => '7.000', 'reserved' => '7.000']);
});

it('garante no banco o físico acima do reservado mesmo sem a verificação da Action', function (): void {
    Fx::confirmOrder($this->scenario, '7');

    $direct = fn (string $delta) => $this->tenant->actingAs($this->stockist, fn () => app(StockLedger::class)->adjust($this->cd1->id, $this->sku->id, Quantity::of($delta), 'Movimento direto'));

    expect(fn () => $direct('-4'))->toThrow(QueryException::class, 'stock_levels_reserved_lte_on_hand')
        ->and(fn () => $direct('-11'))->toThrow(QueryException::class, 'stock_levels_')
        ->and(Fx::level($this->tenant, $this->cd1, $this->sku))->toBe(['on_hand' => '10.000', 'reserved' => '7.000']);

    // A aplicação não altera saldos sem movimento nem reescreve o histórico.
    expect(fn () => $this->tenant->actingAs($this->stockist, static fn () => DB::table('stock_levels')->update(['on_hand' => 999])))
        ->toThrow(QueryException::class, 'permission denied')
        ->and(fn () => $this->tenant->actingAs($this->stockist, static fn () => DB::table('stock_movements')->update(['quantity' => 999])))
        ->toThrow(QueryException::class, 'permission denied')
        ->and(fn () => $this->tenant->actingAs($this->stockist, static fn () => DB::table('stock_movements')->delete()))
        ->toThrow(QueryException::class, 'permission denied')
        ->and(Fx::level($this->tenant, $this->cd1, $this->sku))->toBe(['on_hand' => '10.000', 'reserved' => '7.000']);
});

it('transfere apenas o disponível da origem, preservando as reservas', function (): void {
    Fx::confirmOrder($this->scenario, '6');
    $code = $this->sku->code;

    expect(fn () => ($this->transfer)($this->cd1, $this->cd2, '5'))
        ->toThrow(InsufficientStock::class, "A transferência de 5 UN excede o disponível do SKU {$code} no depósito CD1 (físico 10, reservado 6, disponível 4).")
        ->and(Fx::movementCount($this->tenant, 'transfer_out'))->toBe(0);

    ($this->transfer)($this->cd1, $this->cd2, '4');

    expect(Fx::level($this->tenant, $this->cd1, $this->sku))->toBe(['on_hand' => '6.000', 'reserved' => '6.000'])
        ->and(Fx::level($this->tenant, $this->cd2, $this->sku))->toBe(['on_hand' => '4.000', 'reserved' => '0.000']);
});

it('mantém saldos e reservas iguais à soma dos movimentos com pedidos, cancelamento, ajustes e transferências (RN-070)', function (): void {
    Fx::receive($this->tenant, $this->cd1, $this->sku, '5');
    $first = Fx::confirmOrder($this->scenario, '7');
    ($this->adjust)($this->cd1, '-2');
    ($this->transfer)($this->cd1, $this->cd2, '4');
    $this->tenant->system(fn () => app(StockLedger::class)->releaseForOrderItem($first['item_id'], Quantity::of('3'), 'Cancelamento parcial do item', 'order', $first['order_id']));
    // Segundo pedido: 5 do CD1 (maior prioridade) e 1 do CD2.
    Fx::confirmOrder($this->scenario, '6');

    $openReservations = $this->tenant->system(static fn (): array => DB::table('stock_reservations')
        ->where('status', 'active')
        ->groupBy('warehouse_id')
        ->selectRaw('warehouse_id, sum(quantity - quantity_consumed - quantity_released) as open')
        ->pluck('open', 'warehouse_id')
        ->map(static fn (mixed $open): string => (string) $open)
        ->all());

    expect(Fx::divergences($this->tenant))->toBe([])
        ->and(Fx::level($this->tenant, $this->cd1, $this->sku))->toBe(['on_hand' => '9.000', 'reserved' => '9.000'])
        ->and(Fx::level($this->tenant, $this->cd2, $this->sku))->toBe(['on_hand' => '4.000', 'reserved' => '1.000'])
        ->and($openReservations)->toEqual([$this->cd1->id => '9.000', $this->cd2->id => '1.000']);
});

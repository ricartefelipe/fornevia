<?php

declare(strict_types=1);

use App\Domain\Fulfillment\Exceptions\ShipmentNotAllowed;
use App\Domain\Ordering\Exceptions\OrderNotCancellable;
use App\Enums\Identity\SystemRole;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Integration\Fulfillment\FulfillmentFlow as S;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Concurrency\ConcurrencyHarness;

/*
 * Expedição sob concorrência, com processos separados (RN-073, RN-090):
 * duas expedições da mesma remessa baixam o estoque uma única vez, e o
 * cancelamento de itens disputa o pedido com a expedição sem passar do
 * pedido.
 */

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '4', sameCompany: true);
    $this->order = Fx::place($this->scenario, $this->buyer);
    $this->staff = $this->scenario->tenant->addStaff(SystemRole::Warehouse);
    $this->item = S::item($this->scenario, $this->order);
    $this->args = fn (string $shipmentId, array $extra = []): array => [
        'tenant_id' => $this->scenario->tenant->organization->id, 'user_id' => $this->staff->user_id,
        'membership_id' => $this->staff->id, 'shipment_id' => $shipmentId, ...$extra,
    ];
    $this->movements = fn (): int => $this->scenario->system(static fn (): int => DB::table('stock_movements')->where('type', 'shipment')->count());
});

it('expede a mesma remessa uma única vez', function (): void {
    $shipment = S::create($this->scenario, $this->staff, $this->order, [$this->item->id => '4']);
    S::check($this->scenario, $this->staff, $shipment);
    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('primeira-expedicao');

    try {
        $first = $harness->start('ship-shipment', ($this->args)($shipment->id, ['hold' => 'primeira-expedicao']), 'expedicao-a');
        $harness->waitUntilWaitingAt('primeira-expedicao', 1);
        $second = $harness->start('ship-shipment', ($this->args)($shipment->id), 'expedicao-b');
        $harness->waitUntilBlockedOnRow('expedicao-b', 'orders');
        $hold->release();
        [$ra, $rb] = [$first->result(), $second->result()];
    } finally {
        $harness->shutdown();
    }

    expect($ra['ok'])->toBeTrue()
        ->and($rb['ok'])->toBeFalse()
        ->and($rb['error'])->toBe(ShipmentNotAllowed::class)
        ->and(($this->movements)())->toBe(1)
        ->and(S::level($this->scenario))->toBe(['on_hand' => '6.000', 'reserved' => '0.000'])
        ->and(S::item($this->scenario, $this->order)->quantity_shipped->toDatabase())->toBe('4.000');
});

it('cancelamento esperando a expedição não encontra saldo e não passa do pedido', function (): void {
    $shipment = S::create($this->scenario, $this->staff, $this->order, [$this->item->id => '4']);
    S::check($this->scenario, $this->staff, $shipment);
    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('expedicao-segura');

    try {
        $ship = $harness->start('ship-shipment', ($this->args)($shipment->id, ['hold' => 'expedicao-segura']), 'expedicao');
        $harness->waitUntilWaitingAt('expedicao-segura', 1);
        $cancel = $harness->start('cancel-order', [
            'tenant_id' => $this->scenario->tenant->organization->id, 'user_id' => $this->buyer['membership']->user_id,
            'membership_id' => $this->buyer['membership']->id, 'buyer_company_id' => $this->buyer['company']->id, 'order_id' => $this->order->id,
        ], 'cancelamento');
        $harness->waitUntilBlockedOnRow('cancelamento', 'orders');
        $hold->release();
        [$rs, $rc] = [$ship->result(), $cancel->result()];
    } finally {
        $harness->shutdown();
    }

    $item = S::item($this->scenario, $this->order);
    expect($rs['ok'])->toBeTrue()
        ->and($rc['ok'])->toBeFalse()
        ->and($rc['error'])->toBe(OrderNotCancellable::class)
        ->and($item->quantity_shipped->toDatabase())->toBe('4.000')
        ->and($item->quantity_cancelled->toDatabase())->toBe('0.000');
});

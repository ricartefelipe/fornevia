<?php

declare(strict_types=1);

use App\Actions\Fulfillment\Staff\CancelShipment;
use App\Actions\Fulfillment\Staff\ReserveBackorder;
use App\Actions\Ordering\CancelOrderItems;
use App\Domain\Fulfillment\Exceptions\InvalidShipment;
use App\Domain\Fulfillment\Exceptions\ShipmentNotAllowed;
use App\Domain\Ordering\Exceptions\OrderNotCancellable;
use App\Domain\Shared\Quantity;
use App\Enums\Fulfillment\ShipmentEventKind;
use App\Enums\Fulfillment\ShipmentStatus;
use App\Enums\Identity\SystemRole;
use App\Enums\Ordering\FulfillmentStatus;
use App\Models\Inventory\Warehouse;
use App\Services\Inventory\StockLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Integration\Fulfillment\FulfillmentFlow as S;
use Tests\Support\Builders\OrderingScenario;

/*
| Separação, conferência, expedição e entrega (RF-090, RN-073, RN-090,
| RN-091, ADR-027).
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '6', sameCompany: true);
    $this->order = Fx::place($this->scenario, $this->buyer);
    $this->staff = $this->scenario->tenant->addStaff(SystemRole::Warehouse);
    $this->item = S::item($this->scenario, $this->order);
});

it('expede a quantidade conferida consumindo a reserva e registra a remessa', function (): void {
    expect(S::level($this->scenario))->toBe(['on_hand' => '10.000', 'reserved' => '6.000']);

    $shipment = S::create($this->scenario, $this->staff, $this->order, [$this->item->id => '4']);
    expect($shipment->number)->toBe('EXP-0000001')->and(S::item($this->scenario, $this->order)->quantity_picking->toDatabase())->toBe('4.000');

    S::check($this->scenario, $this->staff, $shipment);
    S::ship($this->scenario, $this->staff, $shipment);

    $item = S::item($this->scenario, $this->order);
    $stored = S::fresh($this->scenario, $shipment);
    expect($stored->status)->toBe(ShipmentStatus::Shipped)
        ->and($stored->carrier)->toBe('Transportadora Rápida')
        ->and($item->quantity_shipped->toDatabase())->toBe('4.000')
        ->and($item->quantity_picking->toDatabase())->toBe('0.000')
        ->and($item->quantity_reserved->toDatabase())->toBe('2.000')
        ->and(S::level($this->scenario))->toBe(['on_hand' => '6.000', 'reserved' => '2.000'])
        ->and(Fx::fresh($this->scenario, $this->order)->fulfillment_status)->toBe(FulfillmentStatus::PartiallyShipped)
        ->and($this->scenario->system(fn (): int => DB::table('outbox_messages')->where('type', 'shipment.shipped')->count()))->toBe(1);

    expect(fn () => S::ship($this->scenario, $this->staff, $shipment))->toThrow(ShipmentNotAllowed::class, 'já foi expedida');
});

it('expede só o conferido e devolve a diferença ao que falta separar', function (): void {
    $shipment = S::create($this->scenario, $this->staff, $this->order, [$this->item->id => '6']);
    $line = S::fresh($this->scenario, $shipment)->items->first();

    expect(fn () => S::ship($this->scenario, $this->staff, $shipment))->toThrow(ShipmentNotAllowed::class, 'Confira')
        ->and(fn () => S::check($this->scenario, $this->staff, $shipment, [$line->id => '7']))->toThrow(InvalidShipment::class, 'até a separada')
        ->and(fn () => S::check($this->scenario, $this->staff, $shipment, [$line->id => '0']))->toThrow(InvalidShipment::class, 'Nada foi conferido');

    S::check($this->scenario, $this->staff, $shipment, [$line->id => '5']);
    S::ship($this->scenario, $this->staff, $shipment);

    $item = S::item($this->scenario, $this->order);
    expect($item->quantity_shipped->toDatabase())->toBe('5.000')
        ->and($item->unallocated()->toDatabase())->toBe('1.000')
        ->and(S::level($this->scenario))->toBe(['on_hand' => '5.000', 'reserved' => '1.000']);
});

it('não separa além do que falta nem além da reserva do depósito', function (): void {
    S::create($this->scenario, $this->staff, $this->order, [$this->item->id => '5']);

    expect(fn () => S::create($this->scenario, $this->staff, $this->order, [$this->item->id => '2']))->toThrow(InvalidShipment::class, 'ainda falta separar')
        ->and(fn () => S::create($this->scenario, $this->staff, $this->order, []))->toThrow(InvalidShipment::class, 'ao menos um item')
        ->and(fn () => S::create($this->scenario, $this->staff, $this->order, [$this->item->id => 'abc']))->toThrow(InvalidShipment::class, 'inválida');

    $other = $this->scenario->system(function (): Warehouse {
        $warehouse = new Warehouse;
        $warehouse->forceFill(['branch_id' => $this->scenario->warehouse->branch_id, 'code' => 'CD2', 'name' => 'Depósito 2', 'priority' => 5, 'allows_sales' => true, 'is_active' => true])->save();

        return $warehouse;
    });

    expect(fn () => S::create($this->scenario, $this->staff, $this->order, [$this->item->id => '1'], $other->id))->toThrow(InvalidShipment::class, 'não tem reserva suficiente no depósito Depósito 2');
});

it('cancela a separação e não cancela item em separação', function (): void {
    $shipment = S::create($this->scenario, $this->staff, $this->order, [$this->item->id => '4']);
    $manager = $this->scenario->tenant->addStaff(SystemRole::SalesManager);

    expect(fn () => $this->scenario->tenant->actingAs($manager, fn () => app(CancelOrderItems::class)->handle($this->order, [$this->item->id => Quantity::of('3')], 'Cliente reduziu o pedido')))
        ->toThrow(OrderNotCancellable::class, 'nem em separação');

    $this->scenario->tenant->actingAs($this->staff, fn () => app(CancelShipment::class)->handle($shipment->id, 'Separação refeita'));

    expect(S::fresh($this->scenario, $shipment)->status)->toBe(ShipmentStatus::Cancelled)
        ->and(S::item($this->scenario, $this->order)->quantity_picking->toDatabase())->toBe('0.000')
        ->and(fn () => $this->scenario->tenant->actingAs($this->staff, fn () => app(CancelShipment::class)->handle($shipment->id, 'De novo')))->toThrow(ShipmentNotAllowed::class)
        ->and(fn () => $this->scenario->tenant->actingAs($this->staff, fn () => app(CancelShipment::class)->handle($shipment->id, 'x')))->toThrow(InvalidShipment::class);
});

it('marca o pedido como expedido quando o restante é cancelado', function (): void {
    S::shipAll($this->scenario, $this->staff, $this->order, '4');
    $manager = $this->scenario->tenant->addStaff(SystemRole::SalesManager);
    $this->scenario->tenant->actingAs($manager, fn () => app(CancelOrderItems::class)->handle($this->order, null, 'Cliente não quer o restante'));

    expect(Fx::fresh($this->scenario, $this->order)->fulfillment_status)->toBe(FulfillmentStatus::Shipped)
        ->and(S::level($this->scenario))->toBe(['on_hand' => '6.000', 'reserved' => '0.000']);
});

it('entrega por evento sem repetir, sem regredir e só depois da expedição', function (): void {
    $shipment = S::create($this->scenario, $this->staff, $this->order, [$this->item->id => '6']);

    expect(fn () => S::event($this->scenario, $this->staff, $shipment, ShipmentEventKind::Delivered, 'trk-1'))->toThrow(ShipmentNotAllowed::class, 'ainda não foi expedida');

    S::check($this->scenario, $this->staff, $shipment);
    S::ship($this->scenario, $this->staff, $shipment);
    $transit = S::event($this->scenario, $this->staff, $shipment, ShipmentEventKind::InTransit, 'trk-1');
    $delivered = S::event($this->scenario, $this->staff, $shipment, ShipmentEventKind::Delivered, 'trk-2');
    $again = S::event($this->scenario, $this->staff, $shipment, ShipmentEventKind::Delivered, 'trk-2');
    // Evento fora de ordem: ocorreu antes da entrega, chega depois dela.
    $late = S::event($this->scenario, $this->staff, $shipment, ShipmentEventKind::InTransit, 'trk-0', S::fresh($this->scenario, $shipment)->shipped_at);

    $stored = S::fresh($this->scenario, $shipment);
    expect($transit->applied)->toBeFalse()
        ->and($delivered->applied)->toBeTrue()
        ->and($again->id)->toBe($delivered->id)
        ->and($late->applied)->toBeFalse()
        ->and($stored->status)->toBe(ShipmentStatus::Delivered)
        ->and($stored->events)->toHaveCount(3)
        ->and(S::item($this->scenario, $this->order)->quantity_delivered->toDatabase())->toBe('6.000')
        ->and(Fx::fresh($this->scenario, $this->order)->fulfillment_status)->toBe(FulfillmentStatus::Delivered);

    expect(fn () => S::event($this->scenario, $this->staff, $shipment, ShipmentEventKind::Exception, 'trk-9'))->toThrow(InvalidShipment::class, 'Descreva')
        ->and(fn () => S::event($this->scenario, $this->staff, $shipment, ShipmentEventKind::InTransit, 'trk-8', now()->addDay()->toImmutable()))->toThrow(InvalidShipment::class, 'futuro')
        ->and(fn () => S::event($this->scenario, $this->staff, $shipment, ShipmentEventKind::InTransit, 'trk-7', now()->subYear()->toImmutable()))->toThrow(InvalidShipment::class, 'anterior à expedição');
});

it('reserva o que estava sob encomenda quando o estoque chega', function (): void {
    $scenario = OrderingScenario::create(stock: '2', unitPriceCents: 10_000, allowBackorder: true);
    $order = Fx::place($scenario, $scenario->buyerWithCart(quantity: '5'));
    $staff = $scenario->tenant->addStaff(SystemRole::Warehouse);
    $item = S::item($scenario, $order);

    expect($item->quantity_backordered->toDatabase())->toBe('3.000')
        ->and(fn () => S::create($scenario, $staff, $order, [$item->id => '3']))->toThrow(InvalidShipment::class, 'Reserve o saldo sob encomenda');

    $scenario->system(fn () => DB::transaction(fn () => app(StockLedger::class)->receive($scenario->warehouse->id, $scenario->sku->id, Quantity::of('2'), 'Compra do fornecedor')));
    $reserved = $scenario->tenant->actingAs($staff, fn () => app(ReserveBackorder::class)->handle($order->id));

    $item = S::item($scenario, $order);
    expect($reserved)->toBe([$item->id => '2.000'])
        ->and($item->quantity_backordered->toDatabase())->toBe('1.000')
        ->and($item->quantity_reserved->toDatabase())->toBe('4.000');

    S::create($scenario, $staff, $order, [$item->id => '4']);
});

it('exige permissão de expedição e pedido confirmado', function (): void {
    $rep = $this->scenario->tenant->addStaff(SystemRole::SalesRep);

    expect(fn () => S::create($this->scenario, $rep, $this->order, [$this->item->id => '1']))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->scenario->tenant->actingAs($this->staff, fn () => app(ReserveBackorder::class)->handle($this->order->id)))->toThrow(ShipmentNotAllowed::class, 'não tem itens sob encomenda');

    $ana = $this->scenario->tenant->addBuyerUser($this->buyer['company'], SystemRole::Approver);
    Fx::rule($this->scenario, $this->buyer['company'], [[1, [$ana]]]);
    $pending = Fx::place($this->scenario, $this->scenario->buyerWithCart(quantity: '1', sameCompany: true));

    expect(fn () => S::create($this->scenario, $this->staff, $pending, [S::item($this->scenario, $pending)->id => '1']))->toThrow(ShipmentNotAllowed::class, 'confirmados');
});

it('não libera no cancelamento a reserva de que a separação depende', function (): void {
    // Item de 10: 5 reservados em cada depósito.
    $scenario = OrderingScenario::create(stock: '5', unitPriceCents: 10_000);
    $second = $scenario->system(function () use ($scenario): Warehouse {
        $warehouse = new Warehouse;
        $warehouse->forceFill(['branch_id' => $scenario->warehouse->branch_id, 'code' => 'CD2', 'name' => 'Depósito 2', 'priority' => 5, 'allows_sales' => true, 'is_active' => true])->save();
        DB::transaction(fn () => app(StockLedger::class)->receive($warehouse->id, $scenario->sku->id, Quantity::of('5'), 'Saldo inicial'));

        return $warehouse;
    });
    $buyer = $scenario->buyerWithCart(quantity: '10', sameCompany: true);
    $order = Fx::place($scenario, $buyer);
    $staff = $scenario->tenant->addStaff(SystemRole::Warehouse);
    $item = S::item($scenario, $order);
    // A separação fica no depósito que a liberação percorreria primeiro.
    $first = strcmp($scenario->warehouse->id, $second->id) < 0 ? $scenario->warehouse->id : $second->id;
    $shipment = S::create($scenario, $staff, $order, [$item->id => '5'], $first);
    $manager = $scenario->tenant->addStaff(SystemRole::SalesManager);

    $scenario->tenant->actingAs($manager, fn () => app(CancelOrderItems::class)->handle($order, null, 'Cliente reduziu o pedido'));

    S::check($scenario, $staff, $shipment);
    S::ship($scenario, $staff, $shipment);

    $item = S::item($scenario, $order);
    expect($item->quantity_shipped->toDatabase())->toBe('5.000')
        ->and($item->quantity_cancelled->toDatabase())->toBe('5.000')
        ->and(S::level($scenario, warehouseId: $first)['reserved'])->toBe('0.000');
});

it('recusa com mensagem a expedição sem reserva no depósito', function (): void {
    $shipment = S::create($this->scenario, $this->staff, $this->order, [$this->item->id => '4']);
    S::check($this->scenario, $this->staff, $shipment);
    // Reserva liberada por fora da separação.
    $this->scenario->system(fn () => DB::transaction(fn () => app(StockLedger::class)->releaseForOrderItem($this->item->id, Quantity::of('6'), 'Ajuste', 'order', $this->order->id)));

    expect(fn () => S::ship($this->scenario, $this->staff, $shipment))->toThrow(ShipmentNotAllowed::class, 'Cancele a separação')
        ->and(S::fresh($this->scenario, $shipment)->status)->toBe(ShipmentStatus::Preparing);
});

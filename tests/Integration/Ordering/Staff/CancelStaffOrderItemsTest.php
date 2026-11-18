<?php

declare(strict_types=1);

use App\Actions\Ordering\Staff\CancelStaffOrderItems;
use App\Actions\Ordering\Staff\StaffCancellationOutcome;
use App\Domain\Ordering\Exceptions\OrderNotCancellable;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use App\Models\Ordering\Order;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Ordering\Staff\StaffOrderFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Cancelamento pela equipe (RF-062): mesmo escopo das telas (tenant,
| orders.view e carteira), orders.manage e medição do que foi liberado de
| reserva, de encomenda e de crédito, sob o lock do pedido.
*/

beforeEach(function (): void {
    $this->cancel = fn (TenantScenario $tenant, Membership $actor, mixed $orderId, ?array $quantities, string $reason = 'Cliente desistiu da compra'): StaffCancellationOutcome => $tenant->actingAs(
        $actor,
        static fn (): StaffCancellationOutcome => app(CancelStaffOrderItems::class)->handle($orderId, $quantities, $reason),
    );
    $this->items = fn (TenantScenario $tenant, Order $order): array => $tenant->system(
        static fn (): array => DB::table('order_items')->where('order_id', $order->id)->orderBy('line_number')->pluck('id')->all(),
    );
});

it('cancela parte do item e informa a reserva e o crédito liberados', function (): void {
    $scenario = OrderingScenario::create(stock: '10', unitPriceCents: 2_500, creditLimitCents: 100_000, onCredit: true);
    $placed = Fx::place($scenario, '5');
    $manager = $scenario->tenant->addStaff(SystemRole::SalesManager);
    [$itemId] = ($this->items)($scenario->tenant, $placed['order']);

    $outcome = ($this->cancel)($scenario->tenant, $manager, $placed['order']->id, [$itemId => Quantity::of('2')]);
    $line = $outcome->lines[0];

    expect($outcome->fullyCancelled)->toBeFalse()
        ->and($outcome->creditReleased->cents)->toBe(5_000)
        ->and($outcome->lines)->toHaveCount(1)
        ->and($line['sku_code'])->toBe($scenario->sku->code)
        ->and($line['cancelled']->toDatabase())->toBe('2.000')
        ->and($line['reservation_released']->toDatabase())->toBe('2.000')
        ->and($line['backorder_released']->isZero())->toBeTrue()
        ->and($outcome->summary())->toBe([
            $scenario->sku->code.': 2 UN cancelado(s) · 2 UN liberado(s) da reserva.',
            'Crédito comercial liberado: R$ 50,00.',
        ])
        ->and(Fx::state($scenario->tenant, $placed['order']))->toMatchArray([
            'status' => 'partially_cancelled',
            'credit' => 7_500,
            'stock_reserved' => '3.000',
            'account_committed' => 7_500,
            'events' => ['order.confirmed', 'order.items_cancelled'],
        ]);

    $audit = $scenario->system(static fn () => DB::table('audit_logs')->where('action', 'order.items_cancelled')->first(['subject_id', 'reason', 'actor_user_id']));

    expect($audit?->subject_id)->toBe($placed['order']->id)
        ->and($audit?->reason)->toBe('Cliente desistiu da compra')
        ->and($audit?->actor_user_id)->toBe($manager->user_id);
});

it('retira primeiro a parte encomendada e depois libera a reserva', function (): void {
    $scenario = OrderingScenario::create(stock: '2', allowBackorder: true);
    $placed = Fx::place($scenario, '5');
    $manager = $scenario->tenant->addStaff(SystemRole::SalesManager);
    [$itemId] = ($this->items)($scenario->tenant, $placed['order']);

    $first = ($this->cancel)($scenario->tenant, $manager, $placed['order']->id, [$itemId => Quantity::of('2')]);
    $second = ($this->cancel)($scenario->tenant, $manager, $placed['order']->id, [$itemId => Quantity::of('2')]);

    expect([$first->lines[0]['backorder_released']->toDatabase(), $first->lines[0]['reservation_released']->toDatabase()])->toBe(['2.000', '0.000'])
        ->and([$second->lines[0]['backorder_released']->toDatabase(), $second->lines[0]['reservation_released']->toDatabase()])->toBe(['1.000', '1.000'])
        ->and($second->summary())->toBe([
            $scenario->sku->code.': 2 UN cancelado(s) · 1 UN liberado(s) da reserva · 1 UN retirado(s) da encomenda.',
            'Nenhum crédito comprometido a liberar.',
        ])
        ->and(Fx::state($scenario->tenant, $placed['order'])['stock_reserved'])->toBe('1.000');
});

it('cancela todo o saldo de um pedido com vários itens', function (): void {
    $scenario = OrderingScenario::create(stock: '10', unitPriceCents: 1_000, creditLimitCents: 100_000, onCredit: true);
    $cable = Fx::sku($scenario, 'CABO-25MM', 'Cabo flexível 2,5 mm rolo 100 m', 18_990, '4');
    $placed = Fx::place($scenario, '3', prepare: static fn (array $buyer) => Fx::addToCart($scenario, $buyer['cart'], $cable, '2'));
    $manager = $scenario->tenant->addStaff(SystemRole::SalesManager);

    $outcome = ($this->cancel)($scenario->tenant, $manager, $placed['order']->id, null);

    expect($outcome->fullyCancelled)->toBeTrue()
        ->and($outcome->order->commercial_status->value)->toBe('cancelled')
        ->and(array_map(static fn (array $l): array => [$l['sku_code'], $l['cancelled']->toDatabase()], $outcome->lines))
        ->toEqualCanonicalizing([[$scenario->sku->code, '3.000'], ['CABO-25MM', '2.000']])
        ->and($outcome->creditReleased->cents)->toBe(40_980)
        ->and(Fx::state($scenario->tenant, $placed['order']))->toMatchArray([
            'status' => 'cancelled',
            'credit' => 0,
            'stock_reserved' => '0.000',
            'account_committed' => 0,
            'events' => ['order.confirmed', 'order.cancelled'],
        ]);
});

it('exige orders.manage e contexto de equipe', function (): void {
    $scenario = OrderingScenario::create();
    $placed = Fx::place($scenario, '2');
    $viewer = $scenario->tenant->addStaff(SystemRole::Viewer);
    $rep = $scenario->tenant->addStaff(SystemRole::SalesRep);
    Fx::assignRep($scenario->tenant, $placed['company'], $rep);
    $before = Fx::state($scenario->tenant, $placed['order']);

    expect(fn () => ($this->cancel)($scenario->tenant, $viewer, $placed['order']->id, null))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->cancel)($scenario->tenant, $rep, $placed['order']->id, null))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->cancel)($scenario->tenant, $placed['membership'], $placed['order']->id, null))->toThrow(AuthorizationException::class);

    expect(Fx::state($scenario->tenant, $placed['order']))->toBe($before);
});

it('limita o representante com orders.manage aos pedidos da sua carteira', function (): void {
    $scenario = OrderingScenario::create();
    $rep = $scenario->tenant->addStaff(SystemRole::SalesRep);
    Fx::adjustRole($scenario->tenant, SystemRole::SalesRep, grant: [Permission::OrdersManage]);
    $mine = Fx::place($scenario, '2');
    $other = Fx::place($scenario, '2');
    Fx::assignRep($scenario->tenant, $mine['company'], $rep);
    $before = Fx::state($scenario->tenant, $other['order']);

    expect(fn () => ($this->cancel)($scenario->tenant, $rep, $other['order']->id, null))->toThrow(ModelNotFoundException::class)
        ->and(Fx::state($scenario->tenant, $other['order']))->toBe($before)
        ->and(($this->cancel)($scenario->tenant, $rep, $mine['order']->id, null)->fullyCancelled)->toBeTrue();
});

it('trata pedido de outro distribuidor e identificador malformado como inexistentes', function (): void {
    $scenario = OrderingScenario::create();
    $foreignScenario = OrderingScenario::create();
    $foreign = Fx::place($foreignScenario, '2');
    $manager = $scenario->tenant->addStaff(SystemRole::SalesManager);

    expect(fn () => ($this->cancel)($scenario->tenant, $manager, $foreign['order']->id, null))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->cancel)($scenario->tenant, $manager, $foreign['order']->number, null))->toThrow(ModelNotFoundException::class)
        ->and(Fx::state($foreignScenario->tenant, $foreign['order'])['status'])->toBe('confirmed');
});

it('recusa item de outro pedido e quantidade acima do saldo, sem efeito parcial', function (): void {
    $scenario = OrderingScenario::create(stock: '20');
    $placed = Fx::place($scenario, '3');
    $other = Fx::place($scenario, '3');
    $manager = $scenario->tenant->addStaff(SystemRole::SalesManager);
    [$itemId] = ($this->items)($scenario->tenant, $placed['order']);
    [$foreignItemId] = ($this->items)($scenario->tenant, $other['order']);
    $before = Fx::state($scenario->tenant, $placed['order']);

    expect(fn () => ($this->cancel)($scenario->tenant, $manager, $placed['order']->id, [$foreignItemId => Quantity::of('1')]))
        ->toThrow(OrderNotCancellable::class, 'Item não pertence ao pedido.');

    try {
        ($this->cancel)($scenario->tenant, $manager, $placed['order']->id, [$itemId => Quantity::of('4')]);
        $this->fail('O cancelamento acima do saldo deveria ser recusado.');
    } catch (OrderNotCancellable $e) {
        expect($e->details())->toBe(['order_item_id' => $itemId, 'cancellable' => '3.000']);
    }

    expect(fn () => ($this->cancel)($scenario->tenant, $manager, $placed['order']->id, null, 'não'))->toThrow(InvalidArgumentException::class)
        ->and(Fx::state($scenario->tenant, $placed['order']))->toBe($before);
});

<?php

declare(strict_types=1);

use App\Actions\Ordering\CancelOrderItems;
use App\Actions\Ordering\PlaceOrder;
use App\Data\Ordering\PlaceOrderData;
use App\Domain\Ordering\Exceptions\OrderNotCancellable;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\SystemRole;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Identity\Membership;
use App\Models\Ordering\Order;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Builders\OrderingScenario;

/*
| Cancelamento de itens não expedidos (RF-062): libera primeiro a parte
| encomendada, depois as reservas, e o crédito proporcional; no cancelamento
| total, todo o comprometimento restante.
*/

beforeEach(function (): void {
    $this->placed = function (OrderingScenario $scenario, string $quantity): array {
        $buyer = $scenario->buyerWithCart(quantity: $quantity);
        $args = $scenario->workerArgs($buyer);
        $order = $scenario->tenant->actingAs($buyer['membership'], static fn (): Order => app(PlaceOrder::class)->handle(new PlaceOrderData(
            cartId: $args['cart_id'],
            idempotencyKey: $args['idempotency_key'],
            shippingAddressId: $args['shipping_address_id'],
            paymentTermId: $args['payment_term_id'],
        )));

        return [$buyer, $order];
    };

    $this->cancel = fn (OrderingScenario $scenario, Membership $actor, Order $order, ?array $quantities, string $reason = 'Pedido duplicado pelo cliente'): Order => $scenario->tenant->actingAs(
        $actor,
        static fn (): Order => app(CancelOrderItems::class)->handle($order, $quantities, $reason),
    );

    $this->snapshot = fn (OrderingScenario $scenario, Order $order): array => $scenario->system(static function () use ($order): array {
        $stored = Order::query()->with('items')->findOrFail($order->id);
        $item = $stored->items->sole();

        return [
            'status' => $stored->commercial_status,
            'credit' => $stored->credit_committed_cents->cents,
            'cancelled' => $item->quantity_cancelled->toDatabase(),
            'reserved' => $item->quantity_reserved->toDatabase(),
            'backordered' => $item->quantity_backordered->toDatabase(),
            'stock_reserved' => (string) DB::table('stock_levels')->value('reserved'),
            'account_committed' => (int) DB::table('credit_accounts')->sum('committed_cents'),
            'events' => DB::table('order_events')->where('order_id', $order->id)->orderBy('created_at')->pluck('type')->all(),
        ];
    });
});

it('cancela parte do item liberando reserva e crédito proporcionais', function (): void {
    $scenario = OrderingScenario::create(stock: '10', unitPriceCents: 1_000, creditLimitCents: 100_000, onCredit: true);
    [$buyer, $order] = ($this->placed)($scenario, '5');
    $itemId = $scenario->system(static fn () => DB::table('order_items')->where('order_id', $order->id)->value('id'));

    ($this->cancel)($scenario, $buyer['membership'], $order, [$itemId => Quantity::of('2')]);

    expect(($this->snapshot)($scenario, $order))->toMatchArray([
        'status' => CommercialStatus::PartiallyCancelled,
        'credit' => 3_000,
        'cancelled' => '2.000',
        'reserved' => '3.000',
        'stock_reserved' => '3.000',
        'account_committed' => 3_000,
        'events' => ['order.confirmed', 'order.items_cancelled'],
    ]);
});

it('cancela o pedido inteiro pela equipe e zera o crédito comprometido', function (): void {
    $scenario = OrderingScenario::create(stock: '10', unitPriceCents: 333, creditLimitCents: 100_000, onCredit: true);
    [, $order] = ($this->placed)($scenario, '3');
    $manager = $scenario->tenant->addStaff(SystemRole::SalesManager);

    ($this->cancel)($scenario, $manager, $order, null);

    expect(($this->snapshot)($scenario, $order))->toMatchArray([
        'status' => CommercialStatus::Cancelled,
        'credit' => 0,
        'cancelled' => '3.000',
        'reserved' => '0.000',
        'stock_reserved' => '0.000',
        'account_committed' => 0,
    ]);

    expect(fn () => ($this->cancel)($scenario, $manager, $order, null))->toThrow(OrderNotCancellable::class);
});

it('libera primeiro a parte encomendada', function (): void {
    $scenario = OrderingScenario::create(stock: '2', allowBackorder: true);
    [$buyer, $order] = ($this->placed)($scenario, '5');
    $itemId = $scenario->system(static fn () => DB::table('order_items')->where('order_id', $order->id)->value('id'));

    ($this->cancel)($scenario, $buyer['membership'], $order, [$itemId => Quantity::of('2')]);

    expect(($this->snapshot)($scenario, $order))->toMatchArray(['backordered' => '1.000', 'reserved' => '2.000', 'stock_reserved' => '2.000']);
});

it('exige motivo e permissão, e restringe o comprador à própria empresa', function (): void {
    $scenario = OrderingScenario::create();
    [$buyer, $order] = ($this->placed)($scenario, '1');
    $other = $scenario->buyerWithCart(quantity: '1');
    $viewer = $scenario->tenant->addStaff(SystemRole::Viewer);

    expect(fn () => ($this->cancel)($scenario, $buyer['membership'], $order, null, 'não'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ($this->cancel)($scenario, $viewer, $order, null))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->cancel)($scenario, $other['membership'], $order, null))->toThrow(AuthorizationException::class);

    expect(($this->snapshot)($scenario, $order)['status'])->toBe(CommercialStatus::Confirmed);
});

<?php

declare(strict_types=1);

use App\Actions\Ordering\PlaceOrder;
use App\Data\Ordering\PlaceOrderData;
use App\Models\Ordering\CartItem;
use App\Models\Ordering\Order;
use Illuminate\Support\Facades\DB;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Concurrency\ConcurrencyHarness;

/*
 * Cancelamento × confirmação com os mesmos saldos em ordem inversa (ADR-006).
 *
 * O pedido X tem a linha 1 no SKU de maior id e a linha 2 no de menor id.
 * Um terceiro processo segura o lock da reserva da linha 2, pausando o
 * cancelamento entre as linhas; nesse intervalo a confirmação de Y disputa
 * os dois saldos. Com os saldos bloqueados em (depósito, SKU) antes do laço,
 * não há ciclo: as duas operações concluem.
 */
it('não entra em impasse entre cancelamento e confirmação com SKUs em ordem inversa', function (): void {
    $scenario = OrderingScenario::create(stock: '50');
    $lower = $scenario->sku;
    $higher = $scenario->addSku(stock: '50');
    expect(strcmp($lower->id, $higher->id))->toBeLessThan(0);

    // Pedido X: linha 1 = SKU de maior id, linha 2 = SKU de menor id.
    $x = $scenario->buyerWithCart(quantity: '2', sku: $higher);
    // Criado depois (created_at explícito): vira a linha 2 do pedido.
    $scenario->system(fn () => (new CartItem)->forceFill([
        'cart_id' => $x['cart']->id, 'buyer_company_id' => $x['company']->id, 'sku_id' => $lower->id, 'quantity' => '2',
        'created_at' => now()->addMinute(), 'updated_at' => now()->addMinute(),
    ])->save());
    $args = $scenario->workerArgs($x);
    $order = $scenario->tenant->actingAs($x['membership'], static fn (): Order => app(PlaceOrder::class)->handle(new PlaceOrderData(
        cartId: $args['cart_id'], idempotencyKey: $args['idempotency_key'], shippingAddressId: $args['shipping_address_id'], paymentTermId: $args['payment_term_id'],
    )));
    $lines = $scenario->system(static fn (): array => DB::table('order_items')->where('order_id', $order->id)->orderBy('line_number')->pluck('sku_id')->all());
    expect($lines)->toBe([$higher->id, $lower->id]);

    $lineTwoReservation = $scenario->system(static fn () => DB::table('stock_reservations')
        ->join('order_items', 'order_items.id', '=', 'stock_reservations.order_item_id')
        ->where('order_items.order_id', $order->id)->where('order_items.line_number', 2)
        ->value('stock_reservations.id'));

    // Comprador Y com os dois SKUs no carrinho.
    $y = $scenario->buyerWithCart(quantity: '1', sku: $lower);
    $scenario->system(fn () => (new CartItem)->forceFill([
        'cart_id' => $y['cart']->id, 'buyer_company_id' => $y['company']->id, 'sku_id' => $higher->id, 'quantity' => '1',
    ])->save());

    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('segura-reserva');

    try {
        $holder = $harness->start('hold-reservation', ['tenant_id' => $scenario->tenant->organization->id, 'reservation_id' => $lineTwoReservation, 'hold' => 'segura-reserva'], 'terceiro');
        $harness->waitUntilWaitingAt('segura-reserva', 1);

        $cancel = $harness->start('cancel-order', [...$scenario->workerArgs($x), 'order_id' => $order->id], 'cancelamento');
        $harness->waitUntilBlockedOnLock('cancelamento');

        $place = $harness->start('place-order', $scenario->workerArgs($y), 'confirmacao');
        $harness->waitUntilBlockedOnLock('confirmacao');

        $hold->release();
        [$rh, $rc, $rp] = [$holder->result(), $cancel->result(), $place->result()];
    } finally {
        $harness->shutdown();
    }

    expect($rh['ok'])->toBeTrue()
        ->and($rc['ok'])->toBeTrue('cancelamento: '.json_encode($rc))
        ->and($rp['ok'])->toBeTrue('confirmação: '.json_encode($rp));

    $levels = $scenario->system(static fn (): array => DB::table('stock_levels')->orderBy('sku_id')->pluck('reserved')->map(static fn ($v): string => (string) $v)->all());

    expect($levels)->toBe(['1.000', '1.000']);
});

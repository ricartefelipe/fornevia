<?php

declare(strict_types=1);

use App\Actions\Ordering\PlaceOrder;
use App\Data\Ordering\PlaceOrderData;
use App\Domain\Credit\Exceptions\CreditNotApproved;
use App\Domain\Ordering\Exceptions\CartAlreadyConverted;
use App\Domain\Ordering\Exceptions\CartNotReady;
use App\Domain\Ordering\Exceptions\InvalidCheckout;
use App\Domain\Ordering\Exceptions\PricesChanged;
use App\Enums\Buyers\BuyerCompanyStatus;
use App\Enums\Identity\SystemRole;
use App\Enums\Ordering\CartStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Ordering\Cart;
use App\Models\Ordering\Order;
use App\Support\Idempotency\IdempotencyKeyReused;
use App\Support\Idempotency\InvalidIdempotencyKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\OrderingScenario;

/*
| Confirmação do pedido (RF-061, RN-060, RN-061): tudo ou nada na mesma
| transação, preços recalculados no servidor, referências de checkout da
| própria empresa e idempotência por chave.
*/

beforeEach(function (): void {
    $this->place = function (OrderingScenario $scenario, array $buyer, array $overrides = []): Order {
        $args = $scenario->workerArgs($buyer);
        $data = new PlaceOrderData(...[
            'cartId' => $args['cart_id'],
            'idempotencyKey' => $args['idempotency_key'],
            'shippingAddressId' => $args['shipping_address_id'],
            'paymentTermId' => $args['payment_term_id'],
            ...$overrides,
        ]);

        return $scenario->tenant->actingAs($buyer['membership'], static fn (): Order => app(PlaceOrder::class)->handle($data));
    };

    $this->state = fn (OrderingScenario $scenario): array => $scenario->system(static fn (): array => [
        'orders' => DB::table('orders')->count(),
        'reserved' => (string) DB::table('stock_levels')->value('reserved'),
        'reservations' => DB::table('stock_reservations')->count(),
        'committed' => (int) DB::table('credit_accounts')->sum('committed_cents'),
        'outbox' => DB::table('outbox_messages')->count(),
        'usage' => (int) DB::table('tenant_usage_counters')->sum('orders_confirmed'),
    ]);
});

it('confirma o pedido com snapshot, reserva, eventos, outbox, auditoria e consumo do plano', function (): void {
    $scenario = OrderingScenario::create(stock: '10', unitPriceCents: 2_590);
    $buyer = $scenario->buyerWithCart(quantity: '3');

    $order = ($this->place)($scenario, $buyer, ['purchaseOrderReference' => 'OC-7781', 'notes' => 'Entregar pela doca 2']);

    $stored = $scenario->system(static fn (): array => [
        'order' => Order::query()->with('items')->findOrFail($order->id),
        'events' => DB::table('order_events')->where('order_id', $order->id)->pluck('type')->all(),
        'outbox' => DB::table('outbox_messages')->where('aggregate_id', $order->id)->pluck('type')->all(),
        'audit' => DB::table('audit_logs')->where('subject_id', $order->id)->pluck('action')->all(),
        'cart' => Cart::query()->findOrFail($order->source_cart_id),
    ]);
    $item = $stored['order']->items->sole();

    expect($order->number)->toStartWith('PED-')
        ->and($stored['order']->commercial_status)->toBe(CommercialStatus::Confirmed)
        ->and($stored['order']->total_cents->cents)->toBe(7_770)
        ->and($stored['order']->purchase_order_reference)->toBe('OC-7781')
        ->and($item->sku_code)->toBe($scenario->sku->code)
        ->and($item->unit_price_cents->cents)->toBe(2_590)
        ->and($item->quantity_reserved->toDatabase())->toBe('3.000')
        ->and($item->quantity_backordered->isZero())->toBeTrue()
        ->and($stored['events'])->toBe(['order.confirmed'])
        ->and($stored['outbox'])->toBe(['order.confirmed'])
        ->and($stored['audit'])->toBe(['order.placed'])
        ->and($stored['cart']->status)->toBe(CartStatus::Converted)
        ->and(($this->state)($scenario))->toMatchArray(['orders' => 1, 'reserved' => '3.000', 'reservations' => 1, 'usage' => 1]);
});

it('compromete o crédito do pedido a prazo', function (): void {
    $scenario = OrderingScenario::create(unitPriceCents: 10_000, creditLimitCents: 50_000, onCredit: true);
    $buyer = $scenario->buyerWithCart(quantity: '2');

    $order = ($this->place)($scenario, $buyer);

    expect($order->credit_committed_cents->cents)->toBe(20_000)
        ->and(($this->state)($scenario)['committed'])->toBe(20_000);
});

it('não deixa efeito parcial quando o crédito é insuficiente', function (): void {
    $scenario = OrderingScenario::create(unitPriceCents: 10_000, creditLimitCents: 15_000, onCredit: true);
    $buyer = $scenario->buyerWithCart(quantity: '2');

    expect(fn () => ($this->place)($scenario, $buyer))->toThrow(CreditNotApproved::class);

    expect(($this->state)($scenario))->toBe(['orders' => 0, 'reserved' => '0.000', 'reservations' => 0, 'committed' => 0, 'outbox' => 0, 'usage' => 0])
        ->and($scenario->system(static fn () => Cart::query()->value('status')))->toBe(CartStatus::Open);
});

it('recusa sem reservar quando falta estoque e o produto não admite encomenda', function (): void {
    $scenario = OrderingScenario::create(stock: '2');
    $buyer = $scenario->buyerWithCart(quantity: '3');

    // A conferência do carrinho já aponta a falta; sob corrida, a reserva com
    // lock recusa com InsufficientStock (tests/Concurrency/Ordering).
    expect(fn () => ($this->place)($scenario, $buyer))->toThrow(CartNotReady::class);
    expect(($this->state)($scenario))->toMatchArray(['orders' => 0, 'reserved' => '0.000', 'reservations' => 0]);
});

it('reserva o disponível e encomenda o restante quando o produto admite encomenda', function (): void {
    $scenario = OrderingScenario::create(stock: '2', allowBackorder: true);
    $buyer = $scenario->buyerWithCart(quantity: '5');

    $order = ($this->place)($scenario, $buyer);
    $item = $scenario->system(static fn () => Order::query()->with('items')->findOrFail($order->id)->items->sole());

    expect($item->quantity_reserved->toDatabase())->toBe('2.000')
        ->and($item->quantity_backordered->toDatabase())->toBe('3.000');
});

it('devolve o mesmo pedido para a mesma chave e recusa a chave com outro conteúdo', function (): void {
    $scenario = OrderingScenario::create();
    $buyer = $scenario->buyerWithCart(quantity: '1');

    $first = ($this->place)($scenario, $buyer, ['idempotencyKey' => 'checkout-fixa-0001']);
    $again = ($this->place)($scenario, $buyer, ['idempotencyKey' => 'checkout-fixa-0001']);

    expect($again->id)->toBe($first->id)
        ->and(fn () => ($this->place)($scenario, $buyer, ['idempotencyKey' => 'checkout-fixa-0001', 'notes' => 'outro conteúdo']))
        ->toThrow(IdempotencyKeyReused::class)
        ->and(fn () => ($this->place)($scenario, $buyer, ['idempotencyKey' => 'curta']))
        ->toThrow(InvalidIdempotencyKey::class)
        ->and(fn () => ($this->place)($scenario, $buyer, ['idempotencyKey' => 'checkout-nova-0002']))
        ->toThrow(CartAlreadyConverted::class);

    expect(($this->state)($scenario)['orders'])->toBe(1);
});

it('recusa confirmar quando o total mudou desde a exibição', function (): void {
    $scenario = OrderingScenario::create(unitPriceCents: 1_000);
    $buyer = $scenario->buyerWithCart(quantity: '2');

    expect(fn () => ($this->place)($scenario, $buyer, ['expectedTotalCents' => 1_900]))->toThrow(PricesChanged::class);

    expect(($this->place)($scenario, $buyer, ['expectedTotalCents' => 2_000])->total_cents->cents)->toBe(2_000);
});

it('recusa carrinho com item fora da regra de compra', function (): void {
    $scenario = OrderingScenario::create();
    $buyer = $scenario->buyerWithCart(quantity: '1,5');

    expect(fn () => ($this->place)($scenario, $buyer))->toThrow(CartNotReady::class);
});

it('exige endereço, condição, unidade e centro de custo da própria empresa', function (string $field): void {
    $scenario = OrderingScenario::create();
    $buyer = $scenario->buyerWithCart(quantity: '1');
    $other = $scenario->buyerWithCart(quantity: '1');

    $overrides = match ($field) {
        'shipping' => ['shippingAddressId' => $other['address']->id],
        'payment' => ['paymentTermId' => $scenario->system(static fn () => App\Models\Pricing\PaymentTerm::query()->where('code', '30-60')->value('id'))],
        'unit' => ['buyerUnitId' => (string) Str::uuid7()],
        'cost_center' => ['costCenterId' => (string) Str::uuid7()],
    };

    expect(fn () => ($this->place)($scenario, $buyer, $overrides))->toThrow(InvalidCheckout::class);
    expect(($this->state)($scenario)['orders'])->toBe(0);
})->with(['shipping', 'payment', 'unit', 'cost_center']);

it('não confirma carrinho de outro usuário nem com identificadores malformados', function (): void {
    $scenario = OrderingScenario::create();
    $buyer = $scenario->buyerWithCart(quantity: '1');
    $other = $scenario->buyerWithCart(quantity: '1');

    expect(fn () => ($this->place)($scenario, $buyer, ['cartId' => $other['cart']->id]))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->place)($scenario, $buyer, ['cartId' => 'carrinho-1']))->toThrow(ModelNotFoundException::class);
});

it('exige a permissão de confirmar pedidos e empresa apta a comprar', function (): void {
    $scenario = OrderingScenario::create();
    $buyer = $scenario->buyerWithCart(quantity: '1');
    $approver = $scenario->tenant->addBuyerUser($buyer['company'], SystemRole::Approver);

    expect(fn () => ($this->place)($scenario, [...$buyer, 'membership' => $approver]))->toThrow(AuthorizationException::class);

    $scenario->system(fn () => $buyer['company']->forceFill(['status' => BuyerCompanyStatus::Blocked])->save());

    expect(fn () => ($this->place)($scenario, $buyer))->toThrow(InvalidCheckout::class);
});

it('consome a cota do plano em contexto de sistema e impede o comprador de alterar o contador', function (): void {
    $scenario = OrderingScenario::create();
    $buyer = $scenario->buyerWithCart(quantity: '1');

    ($this->place)($scenario, $buyer);

    $changed = $scenario->tenant->actingAs($buyer['membership'], static fn (): array => [
        DB::table('tenant_usage_counters')->update(['orders_confirmed' => 0]),
        DB::table('tenant_usage_counters')->delete(),
    ]);

    expect($changed)->toBe([0, 0])
        ->and(($this->state)($scenario)['usage'])->toBe(1);
});

it('numera os pedidos por distribuidor, sem expor o volume de outros tenants', function (): void {
    $first = OrderingScenario::create();
    $second = OrderingScenario::create();

    $a1 = ($this->place)($first, $first->buyerWithCart(quantity: '1'));
    $b1 = ($this->place)($second, $second->buyerWithCart(quantity: '1'));
    $a2 = ($this->place)($first, $first->buyerWithCart(quantity: '1'));

    expect([$a1->number, $a2->number, $b1->number])->toBe(['PED-0000001', 'PED-0000002', 'PED-0000001']);
});

it('recusa pedido cujo total arredonda para zero', function (): void {
    $scenario = OrderingScenario::create(stock: '10', unitPriceCents: 1);
    $scenario->system(function () use ($scenario): void {
        $kg = App\Models\Catalog\UnitOfMeasure::query()->where('code', 'KG')->firstOrFail();
        App\Models\Catalog\Product::query()->whereKey($scenario->sku->product_id)->update(['base_unit_id' => $kg->id]);
        App\Models\Catalog\Sku::query()->whereKey($scenario->sku->id)->update([
            'sales_unit_id' => $kg->id, 'min_order_quantity' => '0.001', 'order_multiple' => '0.001',
        ]);
    });
    $buyer = $scenario->buyerWithCart(quantity: '0,001');

    expect(fn () => ($this->place)($scenario, $buyer))->toThrow(CartNotReady::class, 'maior que zero');
    expect(($this->state)($scenario)['orders'])->toBe(0);
});

it('não mostra ao comprador reservas e movimentações de estoque, nem as do próprio pedido', function (): void {
    $scenario = OrderingScenario::create();
    $buyer = $scenario->buyerWithCart(quantity: '2');
    ($this->place)($scenario, $buyer);

    $seen = $scenario->tenant->actingAs($buyer['membership'], static fn (): array => [
        DB::table('stock_reservations')->count(),
        DB::table('stock_movements')->count(),
    ]);

    expect($seen)->toBe([0, 0])
        ->and($scenario->system(static fn (): int => DB::table('stock_reservations')->count()))->toBe(1);
});

it('grava a origem do preço sem a política comercial interna', function (): void {
    $scenario = OrderingScenario::create(stock: '10', unitPriceCents: 1_000);
    $buyer = $scenario->buyerWithCart(quantity: '2');
    $order = Tests\Integration\Approvals\ApprovalFixtures::place($scenario, $buyer);

    $source = $scenario->system(fn () => json_decode((string) DB::table('order_items')->where('order_id', $order->id)->value('price_source'), true));

    expect($source)->toHaveKeys(['source', 'unit_price_cents', 'version'])
        ->and($source)->not->toHaveKey('min_unit_price_cents');
});

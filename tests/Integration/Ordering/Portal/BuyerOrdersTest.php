<?php

declare(strict_types=1);

use App\Actions\Ordering\CancelOrderItems;
use App\Actions\Ordering\Portal\BuyerOrders;
use App\Actions\Ordering\Portal\Reorder;
use App\Data\Ordering\Portal\CartAdditionLine;
use App\Data\Ordering\Portal\CartAdditionReport;
use App\Data\Ordering\Portal\OrderFilters;
use App\Enums\Identity\SystemRole;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Identity\Membership;
use App\Models\Ordering\Order;
use App\Services\Ordering\CartPricing;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\Ordering\Portal\PortalOrderingFixtures as Fixtures;
use Tests\Support\Builders\OrderingScenario;

/*
| Pedidos do comprador: visibilidade (próprios ou de toda a empresa com
| portal.orders.view_all), filtros, cancelamento permitido e recompra com
| preço e disponibilidade vigentes (RF-060).
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '100', unitPriceCents: 1_000);
    $this->buyer = Fixtures::buyer($this->scenario);
    $this->requester = Fixtures::buyer($this->scenario, $this->buyer['company'], SystemRole::Requester);
    $this->approver = Fixtures::buyer($this->scenario, $this->buyer['company'], SystemRole::Approver);
    $this->colleague = Fixtures::buyer($this->scenario, $this->buyer['company']);
    $this->sku = $this->scenario->sku;

    $this->numbers = fn (Membership $who, ?OrderFilters $filters = null): array => Fixtures::as($this->scenario, $who, static function () use ($filters): array {
        $orders = app(BuyerOrders::class);

        return collect($orders->paginate($orders->actor(), $filters ?? new OrderFilters)->items())->pluck('number')->all();
    });
    $this->find = fn (Membership $who, string $id): Order => Fixtures::as($this->scenario, $who, static function () use ($id): Order {
        $orders = app(BuyerOrders::class);

        return $orders->find($orders->actor(), $id);
    });
});

it('mostra todos os pedidos da empresa a quem tem view_all e só os próprios aos demais', function (): void {
    $mine = Fixtures::order($this->scenario, $this->buyer, [$this->sku->id => '1']);
    $theirs = Fixtures::order($this->scenario, $this->colleague, [$this->sku->id => '2']);
    $otherCompany = Fixtures::order($this->scenario, Fixtures::buyer($this->scenario), [$this->sku->id => '1']);

    expect(($this->numbers)($this->buyer['membership']))->toBe([$theirs->number, $mine->number])
        ->and(($this->numbers)($this->approver['membership']))->toBe([$theirs->number, $mine->number])
        ->and(($this->numbers)($this->requester['membership']))->toBe([])
        ->and(($this->numbers)($this->buyer['membership'], new OrderFilters(onlyMine: true)))->toBe([$mine->number])
        ->and(($this->find)($this->approver['membership'], $theirs->id)->number)->toBe($theirs->number)
        ->and(fn () => ($this->find)($this->buyer['membership'], $otherCompany->id))->toThrow(ModelNotFoundException::class);
});

it('restringe o comprador sem view_all aos próprios pedidos', function (): void {
    $this->scenario->system(function (): void {
        $role = App\Models\Identity\Role::query()->where('key', SystemRole::Purchaser->value)->firstOrFail();
        $role->forceFill(['permissions' => array_values(array_diff($role->permissions, ['portal.orders.view_all']))])->save();
    });
    $mine = Fixtures::order($this->scenario, $this->buyer, [$this->sku->id => '1']);
    $theirs = Fixtures::order($this->scenario, $this->colleague, [$this->sku->id => '1']);

    expect(($this->numbers)($this->buyer['membership']))->toBe([$mine->number])
        ->and(fn () => ($this->find)($this->buyer['membership'], $theirs->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->find)($this->buyer['membership'], 'PED-0000001'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->find)($this->buyer['membership'], (string) Str::uuid7()))->toThrow(ModelNotFoundException::class);
});

it('filtra por situação comercial e período', function (): void {
    $old = Fixtures::order($this->scenario, $this->buyer, [$this->sku->id => '1']);
    $cancelled = Fixtures::order($this->scenario, $this->buyer, [$this->sku->id => '1']);
    $recent = Fixtures::order($this->scenario, $this->buyer, [$this->sku->id => '1']);

    Fixtures::as($this->scenario, $this->buyer['membership'], static fn () => app(CancelOrderItems::class)->handle($cancelled, null, 'Pedido em duplicidade'));
    $this->scenario->system(static function () use ($old, $cancelled): void {
        DB::table('orders')->where('id', $old->id)->update(['created_at' => '2026-03-10 15:00:00+00']);
        DB::table('orders')->where('id', $cancelled->id)->update(['created_at' => '2026-04-01 02:00:00+00']);
    });

    expect(($this->numbers)($this->buyer['membership'], OrderFilters::fromInput('cancelled', '', '')))->toBe([$cancelled->number])
        ->and(($this->numbers)($this->buyer['membership'], OrderFilters::fromInput('', '2026-03-01', '2026-03-31')))->toBe([$cancelled->number, $old->number])
        // 02h UTC de 01/04 ainda é 31/03 no fuso comercial; o período invertido é reordenado.
        ->and(($this->numbers)($this->buyer['membership'], OrderFilters::fromInput('', '2026-03-31', '2026-03-11')))->toBe([$cancelled->number])
        ->and(($this->numbers)($this->buyer['membership'], OrderFilters::fromInput('inexistente', '2026-02-30', 'ontem')))->toBe([$recent->number, $cancelled->number, $old->number]);
});

it('normaliza filtros adulterados', function (mixed $status, mixed $from, mixed $until): void {
    $filters = OrderFilters::fromInput($status, $from, $until, 'sim');

    expect($filters->isFiltered())->toBeFalse();
})->with([
    'listas' => [['confirmed'], ['2026-01-01'], ['2026-01-31']],
    'fora do intervalo' => ['', '1999-12-31', '2100-01-01'],
    'formato errado' => ['CONFIRMED', '01/02/2026', '2026-1-1'],
]);

it('libera o cancelamento só para quem confirma pedidos e enquanto houver saldo não expedido', function (): void {
    $order = Fixtures::order($this->scenario, $this->buyer, [$this->sku->id => '3']);
    $canCancel = fn (Membership $who): bool => Fixtures::as($this->scenario, $who, static function () use ($order): bool {
        $orders = app(BuyerOrders::class);

        return $orders->canCancel($orders->find($orders->actor(), $order->id));
    });

    expect($canCancel($this->buyer['membership']))->toBeTrue()
        ->and($canCancel($this->approver['membership']))->toBeFalse();

    $this->scenario->system(static fn () => DB::table('order_items')->where('order_id', $order->id)->update(['quantity_shipped' => '3']));

    expect($canCancel($this->buyer['membership']))->toBeFalse();
});

it('recompra com preço vigente, ajusta à regra atual e informa itens indisponíveis', function (): void {
    $screw = Fixtures::sku($this->scenario, 'PAR-CX12', priceCents: 300, options: ['minimum' => '12', 'multiple' => '12'], name: 'Parafuso sextavado');
    $glove = Fixtures::sku($this->scenario, 'LUV-01', priceCents: 1_500, name: 'Luva de raspa');
    $order = Fixtures::order($this->scenario, $this->buyer, [$this->sku->id => '2', $screw->id => '24', $glove->id => '1'], 'OC-2026-17');

    Fixtures::setPrice($this->scenario, $this->sku, 1_250);
    $this->scenario->system(static function () use ($screw, $glove): void {
        DB::table('skus')->where('id', $screw->id)->update(['min_order_quantity' => '50', 'order_multiple' => '50']);
        DB::table('products')->where('id', $glove->product_id)->update(['visibility' => 'restricted']);
    });

    $report = Fixtures::as($this->scenario, $this->buyer['membership'], static fn (): CartAdditionReport => app(Reorder::class)->handle($order->id));

    expect(array_map(static fn (CartAdditionLine $line): array => [$line->status, $line->skuCode, $line->quantity?->format()], $report->lines))->toBe([
        ['added', $this->sku->code, '2'],
        ['adjusted', 'PAR-CX12', '50'],
        ['rejected', 'LUV-01', null],
    ])->and($report->lines[2]->messages)->toBe(['O item não está mais disponível para a sua empresa.'])
        ->and($report->lines[2]->productName)->toBe($glove->product->name);

    $total = Fixtures::as($this->scenario, $this->buyer['membership'], function (): int {
        $cart = App\Models\Ordering\Cart::query()->where('membership_id', $this->buyer['membership']->id)->where('status', 'open')->firstOrFail();

        return app(CartPricing::class)->quote($cart, $this->buyer['company'])->totals->total->cents;
    });

    expect($total)->toBe(2 * 1_250 + 50 * 300);
});

it('recompra pedido de colega quando enxerga os pedidos da empresa, mas não de outra empresa', function (): void {
    $theirs = Fixtures::order($this->scenario, $this->colleague, [$this->sku->id => '4']);
    $foreign = Fixtures::order($this->scenario, Fixtures::buyer($this->scenario), [$this->sku->id => '1']);
    $reorder = fn (Membership $who, string $id) => Fixtures::as($this->scenario, $who, static fn (): CartAdditionReport => app(Reorder::class)->handle($id));

    expect($reorder($this->buyer['membership'], $theirs->id)->addedCount())->toBe(1)
        ->and(fn () => $reorder($this->buyer['membership'], $foreign->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $reorder($this->buyer['membership'], 'PED-1'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $reorder($this->approver['membership'], $theirs->id))->toThrow(AuthorizationException::class)
        ->and(fn () => $reorder($this->requester['membership'], $theirs->id))->toThrow(ModelNotFoundException::class);
});

it('nega a lista de pedidos do portal no contexto da equipe', function (): void {
    $staff = $this->scenario->tenant->addStaff(SystemRole::SalesManager);

    expect(fn () => ($this->numbers)($staff))->toThrow(AuthorizationException::class);
});

it('mantém a situação comercial ao recomprar', function (): void {
    $order = Fixtures::order($this->scenario, $this->buyer, [$this->sku->id => '1']);

    Fixtures::as($this->scenario, $this->buyer['membership'], static fn () => app(Reorder::class)->handle($order->id));

    expect(($this->find)($this->buyer['membership'], $order->id)->commercial_status)->toBe(CommercialStatus::Confirmed);
});

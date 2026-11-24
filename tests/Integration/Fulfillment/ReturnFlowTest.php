<?php

declare(strict_types=1);

use App\Actions\Fulfillment\Portal\CancelReturn;
use App\Actions\Fulfillment\Portal\RequestReturn;
use App\Actions\Fulfillment\Staff\DecideReturn;
use App\Actions\Fulfillment\Staff\ReceiveReturn;
use App\Domain\Fulfillment\Exceptions\InvalidReturn;
use App\Domain\Fulfillment\Exceptions\ReturnNotAllowed;
use App\Enums\Fulfillment\ReturnStatus;
use App\Enums\Fulfillment\ShipmentEventKind;
use App\Enums\Identity\SystemRole;
use App\Models\Fulfillment\ReturnRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Integration\Fulfillment\FulfillmentFlow as S;
use Tests\Support\Builders\OrderingScenario;

/*
| Devoluções (RF-091, ADR-027): pedido do comprador sobre o entregue,
| análise, recebimento e inspeção; só o aprovado volta ao disponível.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '6', sameCompany: true);
    $this->order = Fx::place($this->scenario, $this->buyer);
    $this->staff = $this->scenario->tenant->addStaff(SystemRole::Warehouse);
    $this->item = S::item($this->scenario, $this->order);
    $shipment = S::shipAll($this->scenario, $this->staff, $this->order, '6');
    S::event($this->scenario, $this->staff, $shipment, ShipmentEventKind::Delivered, 'trk-entrega');

    $this->request = fn (string $quantity, string $code = 'damaged', ?array $buyer = null): ReturnRequest => $this->scenario->tenant->actingAs(
        ($buyer ?? $this->buyer)['membership'],
        fn (): ReturnRequest => app(RequestReturn::class)->handle($this->order->id, [$this->item->id => $quantity], $code, 'Caixas chegaram amassadas e molhadas', (string) Str::uuid7()),
    );
    $this->decide = fn (ReturnRequest $r, bool $authorize = true, bool $inspection = true) => $this->scenario->tenant->actingAs($this->staff, fn (): ReturnRequest => $authorize
        ? app(DecideReturn::class)->authorize($r->id, $this->scenario->warehouse->id, $inspection, null)
        : app(DecideReturn::class)->reject($r->id, 'Prazo de devolução encerrado'));
    $this->receive = fn (ReturnRequest $r, array $received = []) => $this->scenario->tenant->actingAs($this->staff, fn (): ReturnRequest => app(ReceiveReturn::class)->receive($r->id, $received));
    $this->inspect = fn (ReturnRequest $r, array $approved = []) => $this->scenario->tenant->actingAs($this->staff, fn (): ReturnRequest => app(ReceiveReturn::class)->inspect($r->id, $approved, []));
    $this->fresh = fn (ReturnRequest $r): ReturnRequest => $this->scenario->system(static fn (): ReturnRequest => ReturnRequest::query()->with('items')->findOrFail($r->id));
});

it('devolve ao disponível só o aprovado na inspeção e registra o valor a devolver', function (): void {
    $return = ($this->request)('3');
    expect($return->number)->toBe('DEV-0000001')
        ->and(S::item($this->scenario, $this->order)->quantity_return_open->toDatabase())->toBe('3.000');

    ($this->decide)($return);
    $line = ($this->fresh)($return)->items->first();
    ($this->receive)($return, [$line->id => '3']);

    expect(($this->fresh)($return)->status)->toBe(ReturnStatus::Received)
        ->and(S::level($this->scenario)['on_hand'])->toBe('4.000');

    ($this->inspect)($return, [$line->id => '2']);

    $stored = ($this->fresh)($return);
    $item = S::item($this->scenario, $this->order);
    expect($stored->status)->toBe(ReturnStatus::Closed)
        ->and($stored->refund_cents->cents)->toBe(20_000)
        ->and($stored->items->first()->quantity_rejected->toDatabase())->toBe('1.000')
        ->and($item->quantity_returned->toDatabase())->toBe('3.000')
        ->and($item->quantity_return_open->toDatabase())->toBe('0.000')
        ->and(S::level($this->scenario)['on_hand'])->toBe('6.000')
        ->and($this->scenario->system(fn (): int => DB::table('outbox_messages')->where('type', 'return.closed')->count()))->toBe(1);
});

it('volta ao disponível no recebimento quando a inspeção não é exigida', function (): void {
    $return = ($this->request)('2');
    ($this->decide)($return, inspection: false);
    ($this->receive)($return);

    expect(($this->fresh)($return)->status)->toBe(ReturnStatus::Closed)
        ->and(($this->fresh)($return)->refund_cents->cents)->toBe(20_000)
        ->and(S::level($this->scenario)['on_hand'])->toBe('6.000');
});

it('não devolve além do entregue nem do que já está em devolução aberta', function (): void {
    expect(fn () => ($this->request)('7'))->toThrow(ReturnNotAllowed::class, 'até 6')
        ->and(fn () => ($this->request)('1', 'inexistente'))->toThrow(InvalidReturn::class, 'motivo');

    ($this->request)('5');

    expect(fn () => ($this->request)('2'))->toThrow(ReturnNotAllowed::class, 'até 1');
});

it('libera a quantidade na recusa e na desistência e não recebe o que não foi autorizado', function (): void {
    $rejected = ($this->request)('4');

    expect(fn () => ($this->receive)($rejected))->toThrow(ReturnNotAllowed::class, 'autorizada');

    ($this->decide)($rejected, authorize: false);
    expect(S::item($this->scenario, $this->order)->quantity_return_open->toDatabase())->toBe('0.000')
        ->and(fn () => ($this->decide)($rejected))->toThrow(ReturnNotAllowed::class);

    $cancelled = ($this->request)('6');
    $this->scenario->tenant->actingAs($this->buyer['membership'], fn () => app(CancelReturn::class)->handle($cancelled->id));

    expect(($this->fresh)($cancelled)->status)->toBe(ReturnStatus::Cancelled)
        ->and(S::item($this->scenario, $this->order)->returnable()->toDatabase())->toBe('6.000');
});

it('fecha sem estoque nem valor quando nada chega', function (): void {
    $return = ($this->request)('2');
    ($this->decide)($return);
    ($this->receive)($return, [($this->fresh)($return)->items->first()->id => '0']);

    $stored = ($this->fresh)($return);
    expect($stored->status)->toBe(ReturnStatus::Closed)
        ->and($stored->refund_cents->cents)->toBe(0)
        ->and(S::item($this->scenario, $this->order)->returnable()->toDatabase())->toBe('6.000')
        ->and($this->scenario->system(fn (): int => DB::table('outbox_messages')->where('type', 'return.closed')->where('aggregate_id', $return->id)->count()))->toBe(1);
});

it('recusa devolução de outro comprador, sem permissão e sem dados', function (): void {
    $outsider = $this->scenario->buyerWithCart(quantity: '1');
    $rep = $this->scenario->tenant->addStaff(SystemRole::SalesRep);
    $return = ($this->request)('1');

    expect(fn () => ($this->request)('1', buyer: $outsider))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class)
        ->and(fn () => $this->scenario->tenant->actingAs($rep, fn () => app(DecideReturn::class)->reject($return->id, 'Sem permissão')))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->scenario->tenant->actingAs($this->staff, fn () => app(DecideReturn::class)->reject($return->id, 'x')))->toThrow(InvalidReturn::class, 'motivo da recusa')
        ->and(fn () => $this->scenario->tenant->actingAs($this->buyer['membership'], fn () => app(RequestReturn::class)->handle($this->order->id, [$this->item->id => '1'], 'damaged', 'curto', (string) Str::uuid7())))->toThrow(InvalidReturn::class, 'Descreva')
        ->and(fn () => $this->scenario->tenant->actingAs($this->buyer['membership'], fn () => app(RequestReturn::class)->handle($this->order->id, [], 'damaged', 'Caixas chegaram amassadas', (string) Str::uuid7())))->toThrow(InvalidReturn::class, 'ao menos um item');
});

it('mantém devoluções e remessas somente leitura no contexto de comprador', function (): void {
    $return = ($this->request)('1');

    expect($this->scenario->tenant->actingAs($this->buyer['membership'], fn (): int => DB::table('return_requests')->where('id', $return->id)->update(['status' => 'closed'])))->toBe(0)
        ->and($this->scenario->tenant->actingAs($this->buyer['membership'], fn (): int => DB::table('shipments')->update(['carrier' => 'x'])))->toBe(0)
        ->and($this->scenario->tenant->actingAs($this->buyer['membership'], fn (): int => DB::table('shipments')->count()))->toBe(1);
});

it('mostra a devolução de um colega a quem fez o pedido, mesmo sem ver toda a empresa', function (): void {
    // Quem fez o pedido perde a visão de todos os pedidos da empresa.
    $this->scenario->system(function (): void {
        $role = App\Models\Identity\Role::query()->where('key', SystemRole::Purchaser->value)->firstOrFail();
        $role->forceFill(['permissions' => array_values(array_diff($role->permissions, [App\Enums\Identity\Permission::PortalOrdersViewAll->value]))])->save();
    });
    $colleague = ['membership' => $this->scenario->tenant->addBuyerUser($this->buyer['company'], SystemRole::BuyerAdmin)];
    $return = ($this->request)('1', buyer: $colleague);
    $placer = $this->scenario->system(fn () => App\Models\Identity\Membership::query()->with(['role', 'tenant', 'buyerCompany'])->findOrFail($this->buyer['membership']->id));

    expect($this->scenario->tenant->actingAs($placer, fn (): string => app(App\Actions\Fulfillment\Portal\BuyerReturns::class)->find(
        app(App\Actions\Fulfillment\Portal\BuyerReturns::class)->actor(), $return->id,
    )->number))->toBe($return->number);

    $stranger = $this->scenario->buyerWithCart(quantity: '1', sameCompany: true)['membership'];
    expect(fn () => $this->scenario->tenant->actingAs($stranger, fn () => app(App\Actions\Fulfillment\Portal\BuyerReturns::class)->find(
        app(App\Actions\Fulfillment\Portal\BuyerReturns::class)->actor(), $return->id,
    )))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

<?php

declare(strict_types=1);

use App\Actions\Ordering\Staff\LoadStaffOrderDetail;
use App\Actions\Ordering\Staff\StaffOrderDetail;
use App\Enums\Identity\SystemRole;
use App\Enums\Inventory\ReservationStatus;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Models\Ordering\OrderEvent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Ordering\Staff\StaffOrderFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;

/*
| Detalhe do pedido para a equipe: snapshots do cabeçalho e dos itens,
| estados separados, histórico cronológico, reservas por depósito e crédito
| comprometido. Custos e margens só com catalog.view_costs, sem sequer
| consultar order_item_costs para os demais perfis.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '3', unitPriceCents: 2_500, creditLimitCents: 100_000, onCredit: true);
    $this->tenant = $this->scenario->tenant;
    $this->south = Fx::warehouse($this->scenario, 'CD-SUL', 'Depósito Zona Sul', priority: 5, stock: '10');
    Fx::cost($this->scenario, 1_500);

    $this->placed = Fx::place($this->scenario, '5', [
        'purchaseOrderReference' => 'OC-2026-118',
        'notes' => 'Entregar no almoxarifado da obra',
    ], prepare: function (array $buyer): array {
        [$unit, $center] = Fx::unitAndCostCenter($this->tenant, $buyer['company']);

        return ['buyerUnitId' => $unit->id, 'costCenterId' => $center->id];
    });
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);

    $this->load = fn (Membership $member): StaffOrderDetail => $this->tenant->actingAs(
        $member,
        fn (): StaffOrderDetail => app(LoadStaffOrderDetail::class)->handle($this->placed['order']->id),
    );
});

it('monta cabeçalho, itens, estados, histórico, reservas e crédito do pedido', function (): void {
    $itemId = $this->tenant->system(fn () => DB::table('order_items')->where('order_id', $this->placed['order']->id)->value('id'));
    $this->tenant->actingAs($this->manager, fn () => app(App\Actions\Ordering\Staff\CancelStaffOrderItems::class)
        ->handle($this->placed['order']->id, [$itemId => App\Domain\Shared\Quantity::of('1')], 'Cliente reduziu a quantidade'));
    $purchaser = $this->tenant->system(fn (): string => User::query()->findOrFail($this->placed['membership']->user_id)->name);

    $detail = ($this->load)($this->manager);
    $item = $detail->items->sole();

    expect($detail->order->number)->toBe($this->placed['order']->number)
        ->and($detail->order->buyerCompany->id)->toBe($this->placed['company']->id)
        ->and($detail->order->purchase_order_reference)->toBe('OC-2026-118')
        ->and($detail->order->notes)->toBe('Entregar no almoxarifado da obra')
        ->and($detail->placedByName())->toBe($purchaser)
        ->and($detail->unit?->name)->toBe('Obra Jardim Paulista')
        ->and($detail->costCenter?->code)->toBe('CC-ELETRICA')
        ->and($detail->paymentTermLabel())->toBe('28 dias · vencimentos em 28 dias · usa crédito comercial')
        ->and(StaffOrderDetail::addressLines($detail->order->shipping_address))->toBe([
            'Obra central',
            'Avenida das Indústrias, 1500',
            'Distrito Industrial · Campinas/SP',
            'CEP 13050-000',
        ])
        ->and($item->unit_price_cents->cents)->toBe(2_500)
        ->and($detail->priceOrigin($item))->toBe('Tabela padrão PADRAO')
        ->and($item->quantity_cancelled->toDatabase())->toBe('1.000')
        ->and($item->quantity_reserved->toDatabase())->toBe('4.000')
        ->and(StaffOrderDetail::openQuantity($item)->toDatabase())->toBe('4.000')
        ->and($detail->isCancellable())->toBeTrue()
        ->and($detail->baseUnit($item))->toBe('UN')
        ->and($detail->unitDecimals($item))->toBe(0)
        ->and($detail->events->map(static fn (OrderEvent $e): string => $e->type)->all())->toBe(['order.confirmed', 'order.items_cancelled'])
        ->and($detail->eventLabel($detail->events[1]))->toBe('Itens cancelados')
        ->and($detail->eventDetails($detail->events[0]))->toBe(['Total de R$ 125,00 em 1 item.'])
        ->and($detail->eventDetails($detail->events[1]))->toBe([
            $item->sku_code.': 1 UN',
            'Valor cancelado: R$ 25,00',
            'Motivo: Cliente reduziu a quantidade',
        ])
        ->and($detail->actorName($detail->events[1]))->toBe($this->tenant->system(fn (): string => User::query()->findOrFail($this->manager->user_id)->name))
        ->and($detail->creditCommitted()->cents)->toBe(12_500)
        ->and($detail->creditReleased()->cents)->toBe(2_500)
        ->and($detail->order->credit_committed_cents->cents)->toBe(10_000);

    // Reservas agrupadas por depósito, do de maior prioridade para o de menor.
    $groups = array_map(static fn (array $g): array => [
        $g['warehouse']->code,
        array_map(static fn ($r): string => $r->quantity->toDatabase(), $g['reservations']),
    ], $detail->reservationsByWarehouse);
    $open = array_sum(array_map(static fn (array $g): float => array_sum(array_map(
        static fn ($r): float => (float) $r->quantity->minus($r->quantity_consumed)->minus($r->quantity_released)->toDatabase(),
        $g['reservations'],
    )), $detail->reservationsByWarehouse));

    expect($groups)->toBe([['CD1', ['3.000']], ['CD-SUL', ['2.000']]])
        ->and($open)->toBe(4.0);
});

it('calcula custo e margem por item com catalog.view_costs', function (SystemRole $role): void {
    $member = $role === SystemRole::Owner ? $this->tenant->ownerMembership : $this->tenant->addStaff($role);
    $detail = ($this->load)($member);
    $item = $detail->items->sole();
    $cost = $detail->costs[$item->id] ?? null;

    expect($cost?->unitCost?->cents)->toBe(1_500)
        ->and($cost?->margin?->basisPoints)->toBe(4_000)
        ->and($cost?->margin?->format())->toBe('40%')
        ->and($cost?->lineMargin?->cents)->toBe(5_000);
})->with([
    'proprietário' => [SystemRole::Owner],
    'administrador' => [SystemRole::Admin],
    'gerente comercial' => [SystemRole::SalesManager],
]);

it('não consulta nem expõe custos sem catalog.view_costs', function (SystemRole $role): void {
    $member = $this->tenant->addStaff($role);

    if ($role === SystemRole::SalesRep) {
        Fx::assignRep($this->tenant, $this->placed['company'], $member);
    }

    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $detail = ($this->load)($member);

    expect($detail->costs)->toBeNull()
        ->and(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'order_item_costs')))->toBe([])
        // Sem pricing.view, a origem aparece sem o código da tabela.
        ->and($detail->priceOrigin($detail->items->sole()))->toBe($role === SystemRole::Warehouse || $role === SystemRole::Finance ? 'Tabela padrão' : 'Tabela padrão PADRAO');
})->with([
    'representante comercial' => [SystemRole::SalesRep],
    'estoque e expedição' => [SystemRole::Warehouse],
    'financeiro e crédito' => [SystemRole::Finance],
    'consulta' => [SystemRole::Viewer],
]);

it('indica item sem custo registrado na confirmação', function (): void {
    $scenario = OrderingScenario::create();
    $placed = Fx::place($scenario, '2');
    $manager = $scenario->tenant->addStaff(SystemRole::SalesManager);

    $detail = $scenario->tenant->actingAs($manager, static fn (): StaffOrderDetail => app(LoadStaffOrderDetail::class)->handle($placed['order']->id));
    $cost = $detail->costs[$detail->items->sole()->id] ?? null;

    expect($cost)->not->toBeNull()
        ->and($cost?->unitCost)->toBeNull()
        ->and($cost?->margin)->toBeNull()
        ->and($detail->creditMovements)->toBeEmpty()
        ->and($detail->paymentTermLabel())->toBe('À vista');
});

it('não monta o detalhe de pedido fora da carteira do representante', function (): void {
    $rep = $this->tenant->addStaff(SystemRole::SalesRep);

    expect(fn () => ($this->load)($rep))->toThrow(ModelNotFoundException::class);
});

it('deixa de oferecer cancelamento quando não há saldo cancelável', function (): void {
    $this->tenant->actingAs($this->manager, fn () => app(App\Actions\Ordering\Staff\CancelStaffOrderItems::class)
        ->handle($this->placed['order']->id, null, 'Pedido duplicado pelo cliente'));

    $detail = ($this->load)($this->manager);

    expect($detail->isCancellable())->toBeFalse()
        ->and($detail->order->commercial_status->value)->toBe('cancelled')
        ->and(collect($detail->reservationsByWarehouse)->flatMap(static fn (array $g): array => $g['reservations'])
            ->every(static fn ($r): bool => $r->status === ReservationStatus::Released))->toBeTrue();
});

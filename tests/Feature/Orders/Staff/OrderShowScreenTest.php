<?php

declare(strict_types=1);

use App\Actions\Ordering\Staff\CancelStaffOrderItems;
use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Orders\OrderShow;
use App\Models\Identity\Membership;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Ordering\Staff\StaffOrderFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;

/*
| Detalhe do pedido e cancelamento pela tela: snapshots, estados, reservas
| por depósito, histórico e crédito; custo e margem só com
| catalog.view_costs; cancelamento parcial ou total com motivo, validado
| antes da Action, com retorno da liberação de estoque e crédito.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '3', unitPriceCents: 2_500, creditLimitCents: 100_000, onCredit: true);
    $this->tenant = $this->scenario->tenant;
    Fx::warehouse($this->scenario, 'CD-SUL', 'Depósito Zona Sul', priority: 5, stock: '10');
    Fx::cost($this->scenario, 1_500);

    $this->placed = Fx::place($this->scenario, '5', [
        'purchaseOrderReference' => 'OC-2026-118',
        'notes' => 'Entregar no almoxarifado da obra',
    ], prepare: function (array $buyer): array {
        [$unit, $center] = Fx::unitAndCostCenter($this->tenant, $buyer['company']);

        return ['buyerUnitId' => $unit->id, 'costCenterId' => $center->id];
    });
    $this->placed['company'] = Fx::rename($this->tenant, $this->placed['company'], 'Construtora Serra Azul');
    $this->order = $this->placed['order'];
    $this->itemId = $this->tenant->system(fn () => DB::table('order_items')->where('order_id', $this->order->id)->value('id'));
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $this->viewer = $this->tenant->addStaff(SystemRole::Viewer);

    $this->screen = fn (?string $orderId = null) => Livewire::test(OrderShow::class, ['order' => $orderId ?? $this->order->id]);
    $this->as = fn (?Membership $member, Closure $callback) => Fx::asMember($this->tenant, $member ?? $this->manager, $callback);
    $this->state = fn (): array => Fx::state($this->tenant, $this->order);
});

it('exibe cabeçalho, estados, itens com snapshot, reservas, histórico e crédito', function (): void {
    ($this->as)(null, fn () => ($this->screen)()
        ->assertSee('Pedido '.$this->order->number)
        ->assertSee('Construtora Serra Azul')
        ->assertSee(route('buyers.show', ['buyer' => $this->placed['company']->id]), escape: false)
        ->assertSee('Obra Jardim Paulista (OBRA-07)')
        ->assertSee('Instalações elétricas (CC-ELETRICA)')
        ->assertSee('28 dias · vencimentos em 28 dias · usa crédito comercial')
        ->assertSee('OC-2026-118')
        ->assertSee('Entregar no almoxarifado da obra')
        ->assertSee('Avenida das Indústrias, 1500')
        ->assertSee('Campinas/SP')
        ->assertSeeInOrder(['Comercial', 'Confirmado', 'Aprovação do comprador', 'Dispensada', 'Atendimento', 'Aguardando expedição', 'Financeiro', 'Não faturado'])
        ->assertSee($this->scenario->sku->code)
        ->assertSee('Disjuntor bipolar 32A')
        ->assertSee('R$ 25,00')
        ->assertSee('R$ 125,00')
        ->assertSee('Tabela padrão PADRAO')
        ->assertSeeInOrder(['Reservas por depósito', 'CD1 · Centro de distribuição', '3 UN', 'CD-SUL · Depósito Zona Sul', '2 UN'])
        ->assertSee('Pedido confirmado')
        ->assertSee('Total de R$ 125,00 em 1 item.')
        ->assertSee('Comprometimento de pedido')
        ->assertSeeInOrder(['Crédito comprometido atual', 'R$ 125,00'])
        ->assertSee('Custo unitário')
        ->assertSee('R$ 15,00')
        ->assertSee('40%')
        ->assertSee('R$ 50,00 na linha'));
});

it('oculta custo e margem sem catalog.view_costs', function (SystemRole $role): void {
    $member = $this->tenant->addStaff($role);

    ($this->as)($member, fn () => ($this->screen)()
        ->assertSee($this->scenario->sku->code)
        ->assertDontSee('Custo unitário')
        ->assertDontSee('R$ 15,00')
        ->assertDontSee('40%')
        ->assertDontSee('na linha'));
})->with([
    'consulta' => [SystemRole::Viewer],
    'estoque e expedição' => [SystemRole::Warehouse],
    'financeiro e crédito' => [SystemRole::Finance],
]);

it('cancela parte do item com motivo e informa a liberação de estoque e crédito', function (): void {
    ($this->as)(null, fn () => ($this->screen)()
        ->assertSee('Cancelar itens não expedidos')
        ->set("quantities.{$this->itemId}", '2')
        ->set('cancelReason', '  Cliente reduziu a quantidade  ')
        ->call('cancelSelected')
        ->assertHasNoErrors()
        ->assertSet('notice', "Cancelamento registrado no pedido {$this->order->number}.")
        ->assertSet('releaseNotes', [
            $this->scenario->sku->code.': 2 UN cancelado(s) · 2 UN liberado(s) da reserva.',
            'Crédito comercial liberado: R$ 50,00.',
        ])
        ->assertSet('quantities', [])
        ->assertSet('cancelReason', '')
        ->assertSee('Crédito comercial liberado: R$ 50,00.')
        ->assertSee('Parcialmente cancelado')
        ->assertSee('Itens cancelados')
        ->assertSee('Motivo: Cliente reduziu a quantidade'));

    expect(($this->state)())->toMatchArray([
        'status' => 'partially_cancelled',
        'credit' => 7_500,
        'items' => [$this->scenario->sku->code => ['cancelled' => '2.000', 'reserved' => '3.000', 'backordered' => '0.000']],
        'stock_reserved' => '3.000',
        'events' => ['order.confirmed', 'order.items_cancelled'],
    ]);
});

it('cancela todo o saldo do pedido', function (): void {
    ($this->as)(null, fn () => ($this->screen)()
        ->set('cancelReason', 'Pedido duplicado pelo cliente')
        ->call('cancelAll')
        ->assertHasNoErrors()
        ->assertSet('notice', "Pedido {$this->order->number} cancelado.")
        ->assertSee('Crédito comercial liberado: R$ 125,00.')
        ->assertSee('Motivo do último cancelamento:')
        ->assertDontSee('Cancelar itens não expedidos'));

    expect(($this->state)())->toMatchArray([
        'status' => 'cancelled',
        'credit' => 0,
        'stock_reserved' => '0.000',
        'account_committed' => 0,
        'events' => ['order.confirmed', 'order.cancelled'],
    ]);
});

it('valida motivo e quantidades antes de chamar a Action', function (array $input, string $method, array $errors): void {
    $before = ($this->state)();

    ($this->as)(null, function () use ($input, $method, $errors): void {
        $component = ($this->screen)();

        foreach ($input as $property => $value) {
            $component->set(str_replace('{item}', $this->itemId, $property), $value);
        }

        $component->call($method)->assertHasErrors(array_combine(
            array_map(fn (string $key): string => str_replace('{item}', $this->itemId, $key), array_keys($errors)),
            array_values($errors),
        ))->assertSet('notice', null);
    });

    expect(($this->state)())->toBe($before);
})->with([
    'motivo ausente no cancelamento total' => [[], 'cancelAll', ['cancelReason' => 'required']],
    'motivo curto' => [['cancelReason' => 'não', 'quantities.{item}' => '1'], 'cancelSelected', ['cancelReason' => 'min']],
    'nenhuma quantidade' => [['cancelReason' => 'Cliente reduziu a quantidade'], 'cancelSelected', ['quantities' => 'Informe a quantidade a cancelar em ao menos um item.']],
    'casas decimais' => [['cancelReason' => 'Cliente reduziu a quantidade', 'quantities.{item}' => '1,5'], 'cancelSelected', ['quantities.{item}' => 'A unidade UN não aceita casas decimais.']],
    'zero' => [['cancelReason' => 'Cliente reduziu a quantidade', 'quantities.{item}' => '0'], 'cancelSelected', ['quantities.{item}' => 'Informe uma quantidade maior que zero.']],
    'acima do saldo' => [['cancelReason' => 'Cliente reduziu a quantidade', 'quantities.{item}' => '6'], 'cancelSelected', ['quantities.{item}' => 'O saldo cancelável deste item é 5 UN.']],
    'texto' => [['cancelReason' => 'Cliente reduziu a quantidade', 'quantities.{item}' => 'duas'], 'cancelSelected', ['quantities.{item}' => 'Informe uma quantidade válida, por exemplo 2 ou 1,5.']],
]);

it('mostra a recusa da Action quando o pedido já não admite cancelamento', function (): void {
    ($this->as)(null, function (): void {
        $component = ($this->screen)()->set('cancelReason', 'Cliente desistiu da compra');

        // Outro usuário cancela o pedido enquanto a tela está aberta.
        app(CancelStaffOrderItems::class)->handle($this->order->id, null, 'Pedido duplicado pelo cliente');

        $component->call('cancelAll')
            ->assertHasErrors(['cancellation' => 'O pedido não está em situação que permita cancelamento.'])
            ->assertSee('O pedido não está em situação que permita cancelamento.')
            ->assertSet('notice', null);
    });

    expect(($this->state)()['events'])->toBe(['order.confirmed', 'order.cancelled']);
});

it('exige orders.manage para cancelar, mesmo chamando a ação diretamente', function (): void {
    $before = ($this->state)();

    ($this->as)($this->viewer, fn () => ($this->screen)()
        ->assertDontSee('Cancelar itens não expedidos')
        ->assertSee('Seu perfil permite consultar o pedido. Cancelar itens exige a permissão de gerenciar pedidos.')
        ->set('cancelReason', 'Cancelamento sem permissão')
        ->call('cancelAll')
        ->assertForbidden());

    ($this->as)($this->viewer, fn () => ($this->screen)()
        ->set("quantities.{$this->itemId}", '1')
        ->set('cancelReason', 'Cancelamento sem permissão')
        ->call('cancelSelected')
        ->assertForbidden());

    expect(($this->state)())->toBe($before);
});

it('trata itens adulterados como inexistentes', function (string $case): void {
    $other = Fx::place($this->scenario, '1');
    $otherItem = $this->tenant->system(static fn () => DB::table('order_items')->where('order_id', $other['order']->id)->value('id'));
    $before = ($this->state)();

    $key = match ($case) {
        'malformado' => 'linha-1',
        'de outro pedido' => $otherItem,
    };

    ($this->as)(null, fn () => ($this->screen)()
        ->set('quantities', [$key => '1'])
        ->set('cancelReason', 'Cliente reduziu a quantidade')
        ->call('cancelSelected')
        ->assertNotFound());

    expect(($this->state)())->toBe($before)
        ->and(Fx::state($this->tenant, $other['order'])['status'])->toBe('confirmed');
})->with(['malformado', 'de outro pedido']);

it('não abre pedido de outro distribuidor, fora da carteira ou com identificador malformado', function (string $case): void {
    $rep = $this->tenant->addStaff(SystemRole::SalesRep);
    $foreign = Fx::place(OrderingScenario::create(), '1')['order'];

    [$member, $id] = match ($case) {
        'outro distribuidor' => [$this->manager, $foreign->id],
        'fora da carteira' => [$rep, $this->order->id],
        'malformado' => [$this->manager, $this->order->number],
    };

    ($this->as)($member, fn () => ($this->screen)($id)->assertNotFound());
})->with(['outro distribuidor', 'fora da carteira', 'malformado']);

it('protege o identificador do pedido contra alteração pelo cliente', function (): void {
    $other = Fx::place($this->scenario, '1')['order'];

    ($this->as)(null, function () use ($other): void {
        $component = ($this->screen)();

        expect(fn () => $component->set('orderId', $other->id))->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

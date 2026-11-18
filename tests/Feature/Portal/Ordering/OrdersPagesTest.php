<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Enums\Ordering\CommercialStatus;
use App\Livewire\Portal\Ordering\OrderShow;
use App\Livewire\Portal\Ordering\OrdersIndex;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Ordering\Portal\PortalOrderingFixtures as Fixtures;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Telas de pedidos do comprador (portal.orders, portal.orders.show,
| portal.orders.confirmation): lista paginada com filtros, detalhe com
| estados separados e histórico, cancelamento de itens não expedidos e
| recompra.
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->scenario = OrderingScenario::create(stock: '100', unitPriceCents: 1_000);
    $this->buyer = Fixtures::buyer($this->scenario);
    $this->colleague = Fixtures::buyer($this->scenario, $this->buyer['company']);
    $this->sku = $this->scenario->sku;
    $this->screw = Fixtures::sku($this->scenario, 'PAR-CX12', priceCents: 300, options: ['minimum' => '12', 'multiple' => '12'], name: 'Parafuso sextavado');

    $this->page = function (string $url, ?Membership $membership = null): TestResponse {
        $membership ??= $this->buyer['membership'];
        app()->forgetScopedInstances();

        return $this->actingAs(User::query()->findOrFail($membership->user_id))
            ->withSession(TenantScenario::sessionFor($membership))
            ->get($url);
    };

    $this->asBuyer = function (Closure $callback, ?Membership $membership = null): mixed {
        $membership ??= $this->buyer['membership'];
        $user = User::query()->findOrFail($membership->user_id);

        return $this->scenario->tenant->actingAs($membership, static function () use ($user, $callback): mixed {
            Livewire::actingAs($user);

            return $callback();
        });
    };

    $this->order = Fixtures::order($this->scenario, $this->buyer, [$this->sku->id => '5', $this->screw->id => '24'], 'OC-9001');
    $this->items = $this->scenario->system(fn (): array => OrderItem::query()->where('order_id', $this->order->id)->pluck('id', 'sku_code')->all());
});

it('lista os pedidos com situação, OC, total e quem fez, com link para o detalhe', function (): void {
    $theirs = Fixtures::order($this->scenario, $this->colleague, [$this->sku->id => '1']);

    ($this->page)(route('portal.orders'))
        ->assertOk()
        ->assertSeeText('Pedidos feitos pelos usuários da sua empresa.')
        ->assertSeeText($this->order->number)
        ->assertSeeText($theirs->number)
        ->assertSeeText('OC-9001')
        ->assertSeeText('R$ 122,00')
        ->assertSeeText('Confirmado')
        ->assertSeeText('Aguardando expedição')
        ->assertSeeText('Não faturado')
        ->assertSeeText('Você')
        ->assertSeeText(User::query()->findOrFail($this->colleague['membership']->user_id)->name)
        ->assertSee(route('portal.orders.show', $this->order->id), escape: false);
});

it('mostra ao solicitante apenas os próprios pedidos', function (): void {
    $requester = Fixtures::buyer($this->scenario, $this->buyer['company'], SystemRole::Requester)['membership'];

    ($this->page)(route('portal.orders'), $requester)
        ->assertOk()
        ->assertSeeText('Pedidos feitos por você.')
        ->assertSeeText('Nenhum pedido ainda')
        ->assertDontSeeText($this->order->number);

    ($this->page)(route('portal.orders.show', $this->order->id), $requester)->assertNotFound();
});

it('filtra por situação e período e limpa os filtros', function (): void {
    $cancelled = Fixtures::order($this->scenario, $this->buyer, [$this->sku->id => '1']);
    $this->scenario->system(static fn () => DB::table('orders')->where('id', $cancelled->id)->update([
        'commercial_status' => CommercialStatus::Cancelled->value, 'created_at' => '2026-02-10 12:00:00+00',
    ]));

    ($this->asBuyer)(fn () => Livewire::test(OrdersIndex::class)
        ->assertSee($this->order->number)
        ->assertSee($cancelled->number)
        ->set('status', 'cancelled')
        ->assertSee($cancelled->number)
        ->assertDontSee($this->order->number)
        ->set('status', '')
        ->set('from', '2026-02-01')
        ->set('until', '2026-02-28')
        ->assertSee($cancelled->number)
        ->assertDontSee($this->order->number)
        ->set('from', '2025-01-01')
        ->set('until', '2025-01-31')
        ->assertSee('Nenhum pedido encontrado')
        ->call('clearFilters')
        ->assertSet('from', '')
        ->assertSee($this->order->number));
});

it('ignora filtros adulterados na URL', function (array $query): void {
    ($this->page)(route('portal.orders', $query))
        ->assertOk()
        ->assertSeeText($this->order->number);
})->with([
    'situação inexistente' => [['situacao' => 'aprovadissimo']],
    'situação como lista' => [['situacao' => ['confirmed']]],
    'data inválida' => [['de' => '2026-13-45']],
    'data como lista' => [['ate' => ['2026-01-01']]],
    'página além da última' => [['page' => '50']],
    'página não numérica' => [['page' => 'fim']],
]);

it('exibe o detalhe com itens, totais, estados separados, entrega e histórico', function (): void {
    ($this->page)(route('portal.orders.show', $this->order->id))
        ->assertOk()
        ->assertSeeText("Pedido {$this->order->number}")
        ->assertSeeText('Parafuso sextavado PAR-CX12')
        ->assertSeeText('R$ 72,00')
        ->assertSeeText('R$ 122,00')
        ->assertSeeText('Comercial')
        ->assertSeeText('Aprovação interna')
        ->assertSeeText('Dispensada')
        ->assertSeeText('Atendimento')
        ->assertSeeText('Financeiro')
        ->assertSeeText('OC-9001')
        ->assertSeeText('À vista')
        ->assertSeeText('Rua das Indústrias, 200')
        ->assertSeeText('CEP 18010-000')
        ->assertSeeText('Pedido confirmado')
        ->assertSeeText('Total: R$ 122,00')
        ->assertSeeText('Comprar novamente')
        ->assertSeeText('Cancelar o pedido inteiro');
});

it('cancela parte de um item pela tela e registra no histórico', function (): void {
    $screwItem = $this->items['PAR-CX12'];

    ($this->asBuyer)(fn () => Livewire::test(OrderShow::class, ['order' => $this->order->id])
        ->set("cancelQuantities.{$screwItem}", '12')
        ->set('cancelReason', 'não')
        ->call('cancelItems')
        ->assertHasErrors(['cancelReason'])
        ->set('cancelReason', 'Obra adiada pelo cliente')
        ->call('cancelItems')
        ->assertHasNoErrors()
        ->assertSee('Itens cancelados. As reservas e o crédito correspondentes foram liberados.')
        ->assertSee('Parcialmente cancelado')
        ->assertSee('Itens cancelados')
        ->assertSee('Motivo: Obra adiada pelo cliente')
        ->assertSee('Valor cancelado: R$ 36,00'));

    $stored = $this->scenario->system(fn (): array => [
        'cancelled' => OrderItem::query()->findOrFail($screwItem)->quantity_cancelled->toDatabase(),
        'reserved' => (string) DB::table('stock_levels')->where('sku_id', $this->screw->id)->value('reserved'),
    ]);

    expect($stored)->toBe(['cancelled' => '12.000', 'reserved' => '12.000']);
});

it('valida no campo as quantidades a cancelar', function (string $input, string $message): void {
    $screwItem = $this->items['PAR-CX12'];

    ($this->asBuyer)(fn () => Livewire::test(OrderShow::class, ['order' => $this->order->id])
        ->set("cancelQuantities.{$screwItem}", $input)
        ->set('cancelReason', 'Pedido revisado')
        ->call('cancelItems')
        ->assertHasErrors(["cancelQuantities.{$screwItem}"])
        ->assertSee($message));

    expect($this->scenario->system(static fn (): string => OrderItem::query()->findOrFail($screwItem)->quantity_cancelled->toDatabase()))->toBe('0.000');
})->with([
    'texto' => ['doze', 'Informe a quantidade em números'],
    'zero' => ['0', 'Informe uma quantidade maior que zero.'],
    'acima do saldo' => ['25', 'Só é possível cancelar até 24 UN deste item'],
    'casas decimais' => ['1,5', 'A unidade UN não aceita casas decimais.'],
]);

it('exige ao menos uma quantidade e trata item adulterado como inexistente', function (): void {
    ($this->asBuyer)(fn () => Livewire::test(OrderShow::class, ['order' => $this->order->id])
        ->set('cancelReason', 'Pedido revisado')
        ->call('cancelItems')
        ->assertHasErrors(['cancelQuantities'])
        ->assertSee('Informe a quantidade a cancelar em ao menos um item.'));

    ($this->asBuyer)(fn () => Livewire::test(OrderShow::class, ['order' => $this->order->id])
        ->set('cancelQuantities.'.Str::uuid7(), '1')
        ->set('cancelReason', 'Pedido revisado')
        ->call('cancelItems')
        ->assertNotFound());
});

it('cancela o pedido inteiro e deixa de oferecer o cancelamento', function (): void {
    ($this->asBuyer)(fn () => Livewire::test(OrderShow::class, ['order' => $this->order->id])
        ->set('cancelReason', 'Compra feita em duplicidade')
        ->call('cancelAll')
        ->assertSee('Pedido cancelado. As reservas e o crédito correspondentes foram liberados.')
        ->assertSee('Motivo do último cancelamento: Compra feita em duplicidade')
        ->assertDontSee('Cancelar o pedido inteiro'));

    expect($this->scenario->system(fn (): array => [
        Order::query()->findOrFail($this->order->id)->commercial_status,
        (string) DB::table('stock_levels')->sum('reserved'),
    ]))->toBe([CommercialStatus::Cancelled, '0.000']);
});

it('não oferece cancelamento a quem não confirma pedidos e recusa a ação', function (): void {
    $approver = Fixtures::buyer($this->scenario, $this->buyer['company'], SystemRole::Approver)['membership'];

    ($this->page)(route('portal.orders.show', $this->order->id), $approver)
        ->assertOk()
        ->assertDontSeeText('Cancelar o pedido inteiro')
        ->assertDontSeeText('Comprar novamente');

    ($this->asBuyer)(fn () => Livewire::test(OrderShow::class, ['order' => $this->order->id])
        ->set('cancelReason', 'Tentativa sem permissão')
        ->call('cancelAll')
        ->assertForbidden(), $approver);

    expect($this->scenario->system(fn () => Order::query()->findOrFail($this->order->id)->commercial_status))->toBe(CommercialStatus::Confirmed);
});

it('compra novamente pela tela com o relatório dos itens', function (): void {
    $this->scenario->system(fn () => DB::table('skus')->where('id', $this->screw->id)->update(['status' => 'inactive']));

    ($this->asBuyer)(fn () => Livewire::test(OrderShow::class, ['order' => $this->order->id])
        ->call('reorder')
        ->assertSee('Recompra: itens com preço e disponibilidade de hoje')
        ->assertSee('1 item adicionado ao carrinho; 1 não adicionado.')
        ->assertSee('O item não está mais disponível para a sua empresa.')
        ->assertSee(route('portal.cart')));

    expect(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toBe([$this->sku->code => '5']);
});

it('responde 404 a pedido de outra empresa, de outro tenant ou malformado', function (string $route, string $case): void {
    $id = match ($case) {
        'outra empresa' => Fixtures::order($this->scenario, Fixtures::buyer($this->scenario), [$this->sku->id => '1'])->id,
        'outro tenant' => (function (): string {
            $foreign = OrderingScenario::create();

            return Fixtures::order($foreign, Fixtures::buyer($foreign), [$foreign->sku->id => '1'])->id;
        })(),
        'malformado' => $this->order->number,
    };

    ($this->page)(route($route, $id))->assertNotFound();
})->with(['portal.orders.show', 'portal.orders.confirmation'])->with(['outra empresa', 'outro tenant', 'malformado']);

it('protege o pedido da tela contra alteração pelo cliente', function (): void {
    $other = Fixtures::order($this->scenario, Fixtures::buyer($this->scenario), [$this->sku->id => '1']);

    ($this->asBuyer)(function () use ($other): void {
        $component = Livewire::test(OrderShow::class, ['order' => $this->order->id]);

        expect(fn () => $component->set('orderId', $other->id))->toThrow(CannotUpdateLockedPropertyException::class)
            ->and(fn () => $component->set('report', null))->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

it('nega as telas de pedidos do portal no contexto da equipe', function (): void {
    $staff = $this->scenario->tenant->addStaff(SystemRole::SalesManager);

    ($this->page)(route('portal.orders'), $staff)->assertForbidden();
    ($this->page)(route('portal.orders.show', $this->order->id), $staff)->assertForbidden();
});

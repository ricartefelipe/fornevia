<?php

declare(strict_types=1);

use App\Domain\Shared\Quantity;
use App\Enums\Approvals\ApprovalDecision;
use App\Enums\Approvals\ApprovalOutcome;
use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Orders\OrderShow;
use App\Livewire\Staff\Orders\OrdersIndex;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Services\Inventory\StockLedger;
use Livewire\Livewire;
use Tests\Integration\Approvals\ApprovalFixtures as Approvals;
use Tests\Integration\Ordering\Staff\StaffOrderFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;

/*
| Equipe do distribuidor: situação da aprovação interna do comprador na
| lista e no detalhe do pedido (níveis, aprovadores e decisões), somente
| para leitura, e o motivo de pedido aprovado ainda não confirmado.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $this->tenant = $this->scenario->tenant;
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->company = Fx::rename($this->tenant, $this->buyer['company'], 'Construtora Serra Azul');
    $this->ana = $this->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->bruno = $this->tenant->addBuyerUser($this->company, SystemRole::Approver);
    Approvals::rule($this->scenario, $this->company, [[1, [$this->ana]], [1, [$this->bruno]]], name: 'Compras de obra');
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);

    $this->as = fn (?Membership $member, Closure $callback) => Fx::asMember($this->tenant, $member ?? $this->manager, $callback);
    $this->name = static fn (Membership $member): string => User::query()->findOrFail($member->user_id)->name;
});

it('mostra na lista a aprovação pendente com o nível em análise e filtra pela situação', function (): void {
    $pending = Approvals::place($this->scenario, $this->buyer);
    Approvals::decide($this->scenario, $this->ana, $pending);
    $direct = Fx::place($this->scenario, '1')['order'];

    ($this->as)(null, fn () => Livewire::test(OrdersIndex::class)
        ->assertSee('aprovação interna do comprador')
        ->assertSee($pending->number)
        ->assertSee($direct->number)
        ->assertSee('Aguardando aprovação interna')
        ->assertSee('Nível 2 de 2')
        ->set('approvalStatus', 'pending')
        ->assertSee($pending->number)
        ->assertDontSee($direct->number));
});

it('mostra no detalhe os níveis, aprovadores e decisões, sem ações de aprovação', function (): void {
    $order = Approvals::place($this->scenario, $this->buyer);
    Approvals::decide($this->scenario, $this->ana, $order, ApprovalDecision::Approved, 'Obra prevista no cronograma');

    ($this->as)(null, fn () => Livewire::test(OrderShow::class, ['order' => $order->id])
        ->assertSee('Pedido aguardando a aprovação interna do comprador.')
        ->assertSee('Aprovação interna do comprador')
        ->assertSee('Somente leitura')
        ->assertSee('Compras de obra')
        ->assertSeeInOrder(['Nível 1', 'Aprovado', ($this->name)($this->ana), 'por '.($this->name)($this->ana), 'Comentário: Obra prevista no cronograma'])
        ->assertSeeInOrder(['Nível 2', 'Pendente', 'Em análise', ($this->name)($this->bruno)])
        ->assertSeeInOrder(['Pedido enviado para aprovação interna', 'Total: R$ 300,00', 'Regra: Compras de obra', 'Nível de aprovação concluído', 'Nível 1'])
        ->assertDontSee('order.submitted_for_approval')
        ->assertDontSee('Cancelar itens não expedidos'));
});

it('mostra o motivo do pedido aprovado ainda não confirmado', function (): void {
    $order = Approvals::place($this->scenario, $this->buyer);
    $this->scenario->system(fn () => app(StockLedger::class)->adjust($this->scenario->warehouse->id, $this->scenario->sku->id, Quantity::of('-49'), 'Quebra no depósito'));
    Approvals::decide($this->scenario, $this->ana, $order);

    expect(Approvals::decide($this->scenario, $this->bruno, $order))->toBe(ApprovalOutcome::AwaitingConfirmation);

    ($this->as)(null, fn () => Livewire::test(OrdersIndex::class)->assertSee('Confirmação pendente'));
    ($this->as)(null, fn () => Livewire::test(OrderShow::class, ['order' => $order->id])
        ->assertSee('Aprovado pelo comprador, ainda não confirmado.')
        ->assertSee('Motivo: Estoque insuficiente')
        ->assertSee('Confirmação com o distribuidor pendente')
        ->assertSeeInOrder(['Nível 2', 'Aprovado', 'por '.($this->name)($this->bruno)]));
});

it('mostra o motivo da rejeição pelo comprador', function (): void {
    $order = Approvals::place($this->scenario, $this->buyer);
    Approvals::decide($this->scenario, $this->ana, $order, ApprovalDecision::Rejected, 'Fora do orçamento do trimestre');

    ($this->as)(null, fn () => Livewire::test(OrderShow::class, ['order' => $order->id])
        ->assertSee('Motivo da rejeição pelo comprador:')
        ->assertSee('Fora do orçamento do trimestre')
        ->assertSee('Pedido rejeitado na aprovação interna'));
});

it('não exibe a seção de aprovação em pedido sem aprovação interna', function (): void {
    $order = Fx::place($this->scenario, '1')['order'];

    ($this->as)(null, fn () => Livewire::test(OrderShow::class, ['order' => $order->id])
        ->assertSee('Dispensada')
        ->assertDontSee('Aprovação interna do comprador'));
});

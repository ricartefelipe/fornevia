<?php

declare(strict_types=1);

use App\Domain\Approvals\Exceptions\OrderNotAwaitingApproval;
use App\Domain\Shared\Quantity;
use App\Enums\Approvals\ApprovalDecision;
use App\Enums\Approvals\ApprovalOutcome;
use App\Enums\Identity\SystemRole;
use App\Enums\Ordering\CommercialStatus;
use App\Livewire\Portal\Ordering\CartPage;
use App\Livewire\Portal\Ordering\OrderShow;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Models\Ordering\Order;
use App\Services\Inventory\StockLedger;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Integration\Ordering\Portal\PortalOrderingFixtures as Fixtures;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Telas de pedido do portal com aprovação interna (RF-050, RN-055, RN-056):
| prévia da regra no checkout, orçamento insuficiente como erro do campo de
| centro de custo, retorno "enviado para aprovação", níveis e decisões no
| detalhe e na lista, nova tentativa de confirmação e cancelamento de
| pedido ainda não confirmado.
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->company = $this->buyer['company'];
    $this->ana = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->bruno = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->center = Fx::costCenter($this->scenario, $this->company, 'OBRAS');
    $this->name = static fn (Membership $member): string => User::query()->findOrFail($member->user_id)->name;

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

    $this->orders = fn (): int => $this->scenario->system(static fn (): int => Order::query()->count());
    $this->stock = fn (string $delta): mixed => $this->scenario->system(fn () => str_starts_with($delta, '-')
        ? app(StockLedger::class)->adjust($this->scenario->warehouse->id, $this->scenario->sku->id, Quantity::of($delta), 'Quebra no depósito')
        : app(StockLedger::class)->receive($this->scenario->warehouse->id, $this->scenario->sku->id, Quantity::of($delta), 'Reposição'));
});

it('antecipa no checkout a regra de aprovação e envia o pedido para aprovação', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]], [2, [$this->ana, $this->bruno]]], ['min_total_cents' => 20_000], name: 'Compras acima de R$ 200');

    $component = ($this->asBuyer)(fn () => Livewire::test(CartPage::class)
        ->assertSee('Este pedido seguirá para aprovação interna da sua empresa.')
        ->assertSee('Regra aplicável: Compras acima de R$ 200 (2 níveis de aprovação).')
        ->assertSee('A aprovação interna não garante estoque nem crédito no distribuidor')
        ->assertSee('Enviar para aprovação')
        ->assertDontSee('Confirmar pedido')
        ->call('placeOrder')
        ->assertHasNoErrors());

    $order = $this->scenario->system(static fn (): Order => Order::query()->sole());
    $component->assertRedirect(route('portal.orders.confirmation', $order->id));

    expect($order->commercial_status)->toBe(CommercialStatus::PendingApproval)
        ->and(Fx::reservations($this->scenario, $order))->toBe(0);

    ($this->page)(route('portal.orders.confirmation', $order->id))
        ->assertOk()
        ->assertSeeText("Pedido {$order->number} enviado para aprovação")
        ->assertSeeText('Aguardando aprovação interna.')
        ->assertSeeText('Estoque e crédito ainda não foram reservados: a aprovação interna não garante estoque nem crédito no distribuidor.')
        ->assertSeeText('Aguardando aprovação')
        ->assertDontSeeText('Reservado')
        ->assertSeeText('Regra aplicada: Compras acima de R$ 200.')
        ->assertSeeInOrder(['Nível 1', 'Pendente', 'Em análise', '0 de 1 aprovação necessária', ($this->name)($this->ana)])
        ->assertSeeInOrder(['Nível 2', 'Pendente', '0 de 2 aprovações necessárias', ($this->name)($this->ana).', '.($this->name)($this->bruno)]);
});

it('acompanha os critérios da regra na prévia e confirma direto quando nenhuma se aplica', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]], ['cost_center_id' => $this->center->id], name: 'Obras');

    $component = ($this->asBuyer)(fn () => Livewire::test(CartPage::class)
        ->assertDontSee('seguirá para aprovação interna')
        ->assertSee('Confirmar pedido')
        ->set('costCenterId', $this->center->id)
        ->assertSee('Regra aplicável: Obras (1 nível de aprovação).')
        ->set('costCenterId', '')
        ->assertDontSee('Regra aplicável')
        ->call('placeOrder')
        ->assertHasNoErrors());

    $order = $this->scenario->system(static fn (): Order => Order::query()->sole());
    $component->assertRedirect(route('portal.orders.confirmation', $order->id));

    expect($order->commercial_status)->toBe(CommercialStatus::Confirmed);

    ($this->page)(route('portal.orders.confirmation', $order->id))
        ->assertOk()
        ->assertSeeText("Pedido {$order->number} confirmado")
        ->assertSeeText('Reservado')
        ->assertDontSeeText('Aprovação interna');
});

it('mostra o orçamento do centro de custo e trata o orçamento insuficiente como erro do campo', function (bool $withRule): void {
    if ($withRule) {
        Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    }

    Fx::budget($this->scenario, $this->center, 25_000);

    $component = ($this->asBuyer)(fn () => Livewire::test(CartPage::class)
        ->set('costCenterId', $this->center->id)
        ->assertSee('Orçamento do centro de custo no mês: R$ 250,00 disponíveis de R$ 250,00.')
        ->assertSee('O total do carrinho excede o disponível'));
    $firstKey = $component->get('checkoutKey');

    ($this->asBuyer)(fn () => $component->call('placeOrder')
        ->assertHasErrors(['costCenterId'])
        ->assertHasNoErrors(['checkout'])
        ->assertSee('O orçamento do centro de custo no mês não comporta este pedido. Disponível: R$ 250,00.'));

    // A recusa não deixa pedido; a próxima tentativa usa outra chave.
    expect(($this->orders)())->toBe(0)
        ->and($component->get('checkoutKey'))->not->toBe($firstKey);

    // Sem centro de custo, não há orçamento a controlar.
    ($this->asBuyer)(fn () => $component->set('costCenterId', '')->call('placeOrder')->assertHasNoErrors());

    expect(($this->orders)())->toBe(1);
})->with(['com aprovação' => [true], 'sem aprovação' => [false]]);

it('mostra na lista e no detalhe a aprovação pendente, os níveis e as decisões', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]], [1, [$this->bruno]]], name: 'Compras de obra');
    $order = Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->center->id]);
    Fx::decide($this->scenario, $this->ana, $order, ApprovalDecision::Approved, 'De acordo com o cronograma');

    ($this->page)(route('portal.orders'))
        ->assertOk()
        ->assertSeeText('Aprovação interna')
        ->assertSeeText('Aguardando aprovação interna')
        ->assertSeeText('Nível 2 de 2');

    ($this->page)(route('portal.orders.show', $order->id))
        ->assertOk()
        ->assertSeeText('Este pedido aguarda a aprovação interna da sua empresa.')
        ->assertSeeText('a aprovação interna não garante estoque nem crédito no distribuidor')
        ->assertSeeText('Regra aplicada')
        ->assertSeeText('Compras de obra')
        ->assertSeeInOrder(['Nível 1', 'Aprovado', '1 de 1 aprovação necessária', 'por '.($this->name)($this->ana), 'Comentário: De acordo com o cronograma'])
        ->assertSeeInOrder(['Nível 2', 'Pendente', 'Em análise', 'Nenhuma decisão registrada neste nível.'])
        ->assertSeeInOrder(['Pedido enviado para aprovação interna', 'Total: R$ 300,00', 'Regra: Compras de obra', 'Nível de aprovação concluído', 'Nível 1'])
        ->assertSeeText('Cancelar o pedido');
});

it('mostra as decisões invalidadas pela reavaliação de preços', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]], [1, [$this->bruno]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    Fx::decide($this->scenario, $this->ana, $order);
    Fixtures::setPrice($this->scenario, $this->scenario->sku, 12_000);

    expect(Fx::decide($this->scenario, $this->bruno, $order))->toBe(ApprovalOutcome::Invalidated);

    ($this->page)(route('portal.orders.show', $order->id))
        ->assertOk()
        ->assertSeeText('R$ 360,00')
        ->assertSeeInOrder(['Nível 1', 'Pendente', 'Em análise', '0 de 1 aprovação necessária', 'por '.($this->name)($this->ana), 'Invalidada em'])
        ->assertSeeInOrder(['Nível 2', 'Pendente', 'por '.($this->name)($this->bruno), 'Invalidada em'])
        ->assertSeeText('Aprovações invalidadas pela reavaliação do pedido')
        ->assertSeeText('Total anterior: R$ 300,00');
});

it('mostra o motivo da confirmação recusada e confirma na nova tentativa', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    ($this->stock)('-49');

    expect(Fx::decide($this->scenario, $this->ana, $order))->toBe(ApprovalOutcome::AwaitingConfirmation);

    ($this->page)(route('portal.orders'))
        ->assertOk()
        ->assertSeeText('Aprovado internamente')
        ->assertSeeText('Confirmação com o distribuidor pendente');

    $component = ($this->asBuyer)(fn () => Livewire::test(OrderShow::class, ['order' => $order->id])
        ->assertSee('Aprovado internamente, mas ainda não confirmado com o distribuidor.')
        ->assertSee('Motivo: Estoque insuficiente')
        ->assertSee('Tentar confirmar novamente')
        ->call('retryConfirmation')
        ->assertSee('O pedido continua sem confirmação com o distribuidor. Veja o motivo atualizado abaixo.'));

    ($this->stock)('10');

    ($this->asBuyer)(fn () => $component->call('retryConfirmation')
        ->assertSee('Pedido aprovado e confirmado com o distribuidor.')
        ->assertDontSee('Tentar confirmar novamente')
        ->assertSee('Pedido confirmado'));

    $stored = Fx::fresh($this->scenario, $order);
    expect($stored->commercial_status)->toBe(CommercialStatus::Confirmed)
        ->and($stored->confirmation_issue)->toBeNull()
        ->and(Fx::reservations($this->scenario, $order))->toBe(1);
});

it('recusa a nova tentativa de pedido que já não aguarda confirmação', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    ($this->stock)('-49');
    Fx::decide($this->scenario, $this->ana, $order);

    $component = ($this->asBuyer)(fn () => Livewire::test(OrderShow::class, ['order' => $order->id])->assertSee('Tentar confirmar novamente'));

    // Cancelado em outra aba antes do clique.
    ($this->asBuyer)(fn () => app(App\Actions\Ordering\CancelOrderItems::class)->handle($order, null, 'Cancelado em outra aba'));

    ($this->asBuyer)(fn () => $component->call('retryConfirmation')
        ->assertSee('Somente pedidos aprovados e ainda não confirmados podem ser confirmados novamente.')
        ->assertDontSee('Tentar confirmar novamente'));
});

it('cancela pela tela o pedido ainda não confirmado, liberando o orçamento', function (string $case): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $budget = Fx::budget($this->scenario, $this->center, 100_000);
    $order = Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->center->id]);

    if ($case === 'aprovado sem confirmação') {
        ($this->stock)('-49');
        expect(Fx::decide($this->scenario, $this->ana, $order))->toBe(ApprovalOutcome::AwaitingConfirmation);
    }

    expect(Fx::budgetState($this->scenario, $budget))->toBe(['reserved' => 30_000, 'consumed' => 0]);

    ($this->asBuyer)(fn () => Livewire::test(OrderShow::class, ['order' => $order->id])
        ->assertSee('só pode ser cancelado por inteiro')
        ->assertDontSee('Cancelar as quantidades informadas')
        ->set('cancelReason', 'não')
        ->call('cancelAll')
        ->assertHasErrors(['cancelReason'])
        ->set('cancelReason', 'Compra feita por engano')
        ->call('cancelAll')
        ->assertHasNoErrors()
        ->assertSee('Pedido cancelado antes da confirmação com o distribuidor.')
        ->assertSee('Motivo do último cancelamento: Compra feita por engano')
        ->assertDontSee('Tentar confirmar novamente'));

    expect(Fx::fresh($this->scenario, $order)->commercial_status)->toBe(CommercialStatus::Cancelled)
        ->and(Fx::budgetState($this->scenario, $budget))->toBe(['reserved' => 0, 'consumed' => 0])
        ->and(fn () => Fx::decide($this->scenario, $this->ana, $order))->toThrow(OrderNotAwaitingApproval::class);
})->with(['aguardando aprovação', 'aprovado sem confirmação']);

it('não oferece nova tentativa nem cancelamento a quem não confirma pedidos', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    ($this->stock)('-49');
    Fx::decide($this->scenario, $this->ana, $order);

    ($this->page)(route('portal.orders.show', $order->id), $this->bruno)
        ->assertOk()
        ->assertSeeText('Aprovado internamente, mas ainda não confirmado com o distribuidor.')
        ->assertDontSeeText('Tentar confirmar novamente')
        ->assertDontSeeText('Cancelar o pedido');

    ($this->asBuyer)(fn () => Livewire::test(OrderShow::class, ['order' => $order->id])->call('retryConfirmation')->assertForbidden(), $this->bruno);
    ($this->asBuyer)(fn () => Livewire::test(OrderShow::class, ['order' => $order->id])
        ->set('cancelReason', 'Tentativa sem permissão')
        ->call('cancelAll')
        ->assertForbidden(), $this->bruno);

    expect(Fx::fresh($this->scenario, $order)->commercial_status)->toBe(CommercialStatus::Approved);
});

it('mostra o motivo da rejeição e a decisão do aprovador', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    Fx::decide($this->scenario, $this->ana, $order, ApprovalDecision::Rejected, 'Fora do orçamento do trimestre');

    ($this->page)(route('portal.orders.show', $order->id))
        ->assertOk()
        ->assertSeeText('Reprovado')
        ->assertSeeText('Motivo da rejeição: Fora do orçamento do trimestre')
        ->assertSeeInOrder(['Nível 1', 'Rejeitado', 'Rejeitado', 'por '.($this->name)($this->ana), 'Comentário: Fora do orçamento do trimestre'])
        ->assertSeeText('Pedido rejeitado na aprovação interna')
        ->assertDontSeeText('Cancelar o pedido')
        ->assertDontSeeText('Tentar confirmar novamente');
});

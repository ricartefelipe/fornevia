<?php

declare(strict_types=1);

use App\Actions\Fulfillment\Staff\SaveFreightRule;
use App\Enums\Fulfillment\ReturnStatus;
use App\Enums\Fulfillment\ShipmentEventKind;
use App\Enums\Identity\SystemRole;
use App\Livewire\Portal\Ordering\CartPage;
use App\Livewire\Portal\Ordering\OrderShow;
use App\Livewire\Portal\Returns\ReturnShow;
use App\Models\Fulfillment\ReturnRequest;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Integration\Fulfillment\FulfillmentFlow as S;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Portal: frete no carrinho, entregas com rastreamento no pedido e
| devolução pedida, acompanhada e desistida pelo comprador.
*/

beforeEach(function (): void {
    $this->withoutVite();
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '4', sameCompany: true);
    $this->staff = $this->scenario->tenant->addStaff(SystemRole::Warehouse);

    $this->page = function (string $url, ?Membership $membership = null): TestResponse {
        $membership ??= $this->buyer['membership'];
        app()->forgetScopedInstances();

        return $this->actingAs(User::query()->findOrFail($membership->user_id))->withSession(TenantScenario::sessionFor($membership))->get($url);
    };
    $this->as = function (Closure $callback, ?Membership $membership = null): mixed {
        $membership ??= $this->buyer['membership'];
        $user = User::query()->findOrFail($membership->user_id);

        return $this->scenario->tenant->actingAs($membership, static function () use ($user, $callback): mixed {
            Livewire::actingAs($user);

            return $callback();
        });
    };
});

it('mostra no carrinho o frete do distribuidor para o endereço escolhido', function (): void {
    $manager = $this->scenario->tenant->addStaff(SystemRole::SalesManager);
    $this->scenario->tenant->actingAs($manager, fn () => app(SaveFreightRule::class)->handle(null, 'Capital paulista', 'SP', '25,00', null, 0, true));

    ($this->as)(fn () => Livewire::test(CartPage::class)
        ->assertSee('Frete do distribuidor')
        ->assertSee('Capital paulista')
        ->assertSee('R$ 25,00')
        ->assertSee('R$ 425,00')
        ->call('placeOrder')
        ->assertRedirect());

    expect($this->scenario->system(fn (): int => App\Models\Ordering\Order::query()->firstOrFail()->total_cents->cents))->toBe(42_500);
});

it('mostra as entregas com rastreamento e pede a devolução do entregue', function (): void {
    $order = Fx::place($this->scenario, $this->buyer);

    ($this->as)(fn () => Livewire::test(OrderShow::class, ['order' => $order->id])->assertDontSee('Pedir devolução'));

    $shipment = S::shipAll($this->scenario, $this->staff, $order, '4');
    S::event($this->scenario, $this->staff, $shipment, ShipmentEventKind::Delivered, 'trk-entrega');
    $item = S::item($this->scenario, $order);

    ($this->page)(route('portal.orders.show', $order->id))
        ->assertOk()
        ->assertSeeText('Remessa '.$shipment->number.' · Entregue')
        ->assertSeeText('rastreamento BR123')
        ->assertSeeText('Remessa entregue');

    ($this->as)(fn () => Livewire::test(OrderShow::class, ['order' => $order->id])
        ->assertSee('Pedir devolução')
        ->set("returnQuantities.{$item->id}", '9')
        ->set('returnReasonCode', 'damaged')
        ->set('returnReason', 'Caixas amassadas no transporte')
        ->call('requestReturn')
        ->assertHasErrors("returnQuantities.{$item->id}")
        ->set("returnQuantities.{$item->id}", '1')
        ->set('returnReasonCode', '')
        ->call('requestReturn')
        ->assertHasErrors('returnReasonCode')
        ->set('returnReasonCode', 'damaged')
        ->call('requestReturn')
        ->assertRedirect());

    $return = $this->scenario->system(fn (): ReturnRequest => ReturnRequest::query()->firstOrFail());

    ($this->page)(route('portal.returns'))->assertOk()->assertSeeText($return->number)->assertSeeText('Aguardando análise');
    ($this->as)(fn () => Livewire::test(ReturnShow::class, ['return' => $return->id])
        ->assertSee('O distribuidor está analisando')
        ->call('cancel')
        ->assertSee('Você desistiu da devolução.')
        ->assertDontSee('Desistir da devolução</button>', false));

    expect($this->scenario->system(fn (): ReturnStatus => ReturnRequest::query()->findOrFail($return->id)->status))->toBe(ReturnStatus::Cancelled);
});

it('esconde a devolução de outra empresa', function (): void {
    $order = Fx::place($this->scenario, $this->buyer);
    $shipment = S::shipAll($this->scenario, $this->staff, $order, '4');
    S::event($this->scenario, $this->staff, $shipment, ShipmentEventKind::Delivered, 'trk-entrega');
    $itemId = S::item($this->scenario, $order)->id;
    $return = $this->scenario->tenant->actingAs($this->buyer['membership'], fn () => app(App\Actions\Fulfillment\Portal\RequestReturn::class)
        ->handle($order->id, [$itemId => '1'], 'regret', 'Comprei a mais do que precisava', (string) Illuminate\Support\Str::uuid7()));
    $outsider = $this->scenario->buyerWithCart(quantity: '1');

    ($this->page)(route('portal.returns.show', $return->id), $outsider['membership'])->assertNotFound();
    ($this->page)(route('portal.returns'), $outsider['membership'])->assertOk()->assertDontSeeText($return->number);
});

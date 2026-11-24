<?php

declare(strict_types=1);

use App\Enums\Fulfillment\ReturnStatus;
use App\Enums\Fulfillment\ShipmentStatus;
use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Fulfillment\FulfillmentOrder;
use App\Livewire\Staff\Fulfillment\ShipmentShow;
use App\Livewire\Staff\Pricing\FreightIndex;
use App\Livewire\Staff\Returns\ReturnShow;
use App\Models\Fulfillment\FreightRule;
use App\Models\Fulfillment\ReturnRequest;
use App\Models\Fulfillment\Shipment;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Integration\Fulfillment\FulfillmentFlow as S;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Telas da equipe: fila da expedição, separação do pedido, remessa
| (conferência, expedição, eventos), devoluções e frete.
*/

beforeEach(function (): void {
    $this->withoutVite();
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '4', sameCompany: true);
    $this->order = Fx::place($this->scenario, $this->buyer);
    $this->staff = $this->scenario->tenant->addStaff(SystemRole::Warehouse);
    $this->item = S::item($this->scenario, $this->order);

    $this->page = function (string $url, ?Membership $membership = null): TestResponse {
        $membership ??= $this->staff;
        app()->forgetScopedInstances();

        return $this->actingAs(User::query()->findOrFail($membership->user_id))->withSession(TenantScenario::sessionFor($membership))->get($url);
    };
    $this->as = function (Closure $callback, ?Membership $membership = null): mixed {
        $membership ??= $this->staff;
        $user = User::query()->findOrFail($membership->user_id);

        return $this->scenario->tenant->actingAs($membership, static function () use ($user, $callback): mixed {
            Livewire::actingAs($user);

            return $callback();
        });
    };
});

it('separa, confere, expede e entrega pelas telas', function (): void {
    ($this->page)(route('fulfillment.index'))->assertOk()->assertSeeText($this->order->number)->assertSeeText('Separar');

    ($this->as)(fn () => Livewire::test(FulfillmentOrder::class, ['order' => $this->order->id])
        ->assertSee('Falta separar')
        ->set("quantities.{$this->item->id}", '9')
        ->call('createShipment')
        ->assertHasErrors("quantities.{$this->item->id}")
        ->set("quantities.{$this->item->id}", '4')
        ->call('createShipment')
        ->assertRedirect());

    $shipment = $this->scenario->system(fn (): Shipment => Shipment::query()->with('items')->firstOrFail());

    ($this->as)(fn () => Livewire::test(ShipmentShow::class, ['shipment' => $shipment->id])
        ->assertSee('Registrar conferência')
        ->set('carrier', 'Rápida Cargas')
        ->call('ship')
        ->assertSee('Confira a remessa antes de expedir')
        ->call('check')
        ->assertSee('Conferência registrada')
        ->set('carrier', '')
        ->call('ship')
        ->assertHasErrors('carrier')
        ->set('carrier', 'Rápida Cargas')
        ->set('trackingCode', 'RC-998')
        ->call('ship')
        ->assertSee('Remessa expedida')
        ->set('eventKind', 'exception')
        ->call('recordEvent')
        ->assertHasErrors('eventDescription')
        ->set('eventKind', 'delivered')
        ->call('recordEvent')
        ->assertSee('Evento registrado'));

    expect(S::fresh($this->scenario, $shipment)->status)->toBe(ShipmentStatus::Delivered);
    ($this->page)(route('fulfillment.index', ['fila' => 'entregues']))->assertOk()->assertSeeText($shipment->number);
    ($this->page)(route('fulfillment.shipments.show', $shipment->id))->assertOk()->assertSeeText('Entregue')->assertSeeText('RC-998');
});

it('analisa, recebe e inspeciona a devolução pela tela', function (): void {
    $shipment = S::shipAll($this->scenario, $this->staff, $this->order, '4');
    S::event($this->scenario, $this->staff, $shipment, App\Enums\Fulfillment\ShipmentEventKind::Delivered, 'trk');
    $return = $this->scenario->tenant->actingAs($this->buyer['membership'], fn () => app(App\Actions\Fulfillment\Portal\RequestReturn::class)
        ->handle($this->order->id, [$this->item->id => '2'], 'damaged', 'Embalagem rasgada na entrega', (string) Str::uuid7()));

    ($this->page)(route('returns.index'))->assertOk()->assertSeeText($return->number);

    ($this->as)(fn () => Livewire::test(ReturnShow::class, ['return' => $return->id])
        ->call('reject')
        ->assertHasErrors('comment')
        ->set('inspectionRequired', true)
        ->call('approve')
        ->assertSee('Devolução autorizada')
        ->call('receive')
        ->assertSee('Recebimento registrado')
        ->call('inspect')
        ->assertSee('Inspeção registrada'));

    // Na mesma tela: a inspeção começa pelo recebido e aprova tudo por padrão.
    $stored = $this->scenario->system(fn (): ReturnRequest => ReturnRequest::query()->findOrFail($return->id));
    expect($stored->status)->toBe(ReturnStatus::Closed)
        ->and($stored->refund_cents->cents)->toBe(20_000)
        ->and(S::level($this->scenario)['on_hand'])->toBe('8.000');
    ($this->page)(route('returns.index', ['fila' => 'encerradas']))->assertOk()->assertSeeText($return->number);
});

it('mantém regras e condições de frete pela tela de preços', function (): void {
    $manager = $this->scenario->tenant->addStaff(SystemRole::SalesManager);

    ($this->page)(route('pricing.freight'), $manager)->assertOk()->assertSeeText('Nenhuma regra cadastrada');

    ($this->as)(fn () => Livewire::test(FreightIndex::class)
        ->set('name', 'Sudeste')
        ->set('states', 'SP, XX')
        ->set('amount', '35,00')
        ->call('save')
        ->assertHasErrors('states')
        ->set('states', 'SP, RJ')
        ->set('freeFrom', '500,00')
        ->call('save')
        ->assertSee('Regra Sudeste salva.')
        ->set('companyId', $this->buyer['company']->id)
        ->set('conditionKind', 'free')
        ->call('saveCondition')
        ->assertSee('Condição de frete da empresa atualizada.')
        ->assertSee('Frete isento'), $manager);

    $rule = $this->scenario->system(fn (): FreightRule => FreightRule::query()->firstOrFail());
    ($this->as)(fn () => Livewire::test(FreightIndex::class)->call('edit', $rule->id)->assertSet('states', 'RJ, SP')->set('amount', '40,00')->call('save')->assertSee('Regra Sudeste salva.'), $manager);

    expect($this->scenario->system(fn (): int => FreightRule::query()->firstOrFail()->amount_cents->cents))->toBe(4_000);
});

it('restringe as telas pela permissão', function (): void {
    $rep = $this->scenario->tenant->addStaff(SystemRole::SalesRep);

    ($this->page)(route('fulfillment.index'), $rep)->assertForbidden();
    ($this->page)(route('returns.index'), $rep)->assertForbidden();

    $viewer = $this->scenario->tenant->addStaff(SystemRole::Viewer);
    ($this->as)(fn () => Livewire::test(FulfillmentOrder::class, ['order' => $this->order->id])->assertDontSee('Abrir remessa</button>', false), $viewer);
});

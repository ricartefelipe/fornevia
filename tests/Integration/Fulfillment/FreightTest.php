<?php

declare(strict_types=1);

use App\Actions\Fulfillment\Staff\SaveBuyerFreightCondition;
use App\Actions\Fulfillment\Staff\SaveFreightRule;
use App\Domain\Fulfillment\Exceptions\InvalidFreightRule;
use App\Domain\Ordering\Exceptions\PricesChanged;
use App\Enums\Approvals\ApprovalOutcome;
use App\Enums\Fulfillment\FreightConditionKind;
use App\Enums\Identity\SystemRole;
use App\Models\Fulfillment\FreightRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;

/*
| Frete do distribuidor no pedido (RF-092, ADR-027): regra por UF,
| isenção por subtotal e condição negociada, calculados no servidor.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '20', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->manager = $this->scenario->tenant->addStaff(SystemRole::SalesManager);
    $this->rule = fn (string $states, string $amount, ?string $freeFrom = null, int $priority = 0, string $name = 'Capital') => $this->scenario->tenant->actingAs(
        $this->manager,
        static fn (): FreightRule => app(SaveFreightRule::class)->handle(null, $name, $states, $amount, $freeFrom, $priority, true),
    );
});

it('soma o frete da regra da UF ao pedido', function (): void {
    ($this->rule)('SP, rj', '45,90');
    ($this->rule)('', '99,00', name: 'Brasil');

    $order = Fx::place($this->scenario, $this->buyer);

    expect($order->freight_cents->cents)->toBe(4_590)
        ->and($order->total_cents->cents)->toBe(30_000 + 4_590);
});

it('isenta pelo subtotal e respeita a condição negociada da empresa', function (): void {
    ($this->rule)('SP', '45,90', freeFrom: '300,00');

    expect(Fx::place($this->scenario, $this->buyer)->freight_cents->cents)->toBe(0);

    ($this->rule)('SP', '10,00', priority: 5, name: 'Prioritária');
    $this->scenario->tenant->actingAs($this->manager, fn () => app(SaveBuyerFreightCondition::class)->handle($this->buyer['company']->id, FreightConditionKind::Fixed, '12,50'));

    expect(Fx::place($this->scenario, $this->scenario->buyerWithCart(quantity: '1', sameCompany: true))->freight_cents->cents)->toBe(1_250);

    $this->scenario->tenant->actingAs($this->manager, fn () => app(SaveBuyerFreightCondition::class)->handle($this->buyer['company']->id, null));

    expect(Fx::place($this->scenario, $this->scenario->buyerWithCart(quantity: '1', sameCompany: true))->freight_cents->cents)->toBe(1_000);
});

it('recusa confirmar quando o total exibido não tem o frete atual', function (): void {
    ($this->rule)('SP', '45,90');

    expect(fn () => Fx::place($this->scenario, $this->buyer, ['expectedTotalCents' => 30_000]))->toThrow(PricesChanged::class);

    expect(Fx::place($this->scenario, $this->buyer, ['expectedTotalCents' => 34_590])->total_cents->cents)->toBe(34_590);
});

it('recalcula o frete pela regra vigente na reavaliação da aprovação', function (): void {
    ($this->rule)('SP', '45,90', freeFrom: '340,00');
    $ana = $this->scenario->tenant->addBuyerUser($this->buyer['company'], SystemRole::Approver);
    Fx::rule($this->scenario, $this->buyer['company'], [[1, [$ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    $this->scenario->system(fn () => DB::table('price_list_items')->where('sku_id', $this->scenario->sku->id)->update(['unit_price_cents' => 12_000]));
    $this->scenario->system(static fn () => app(App\Services\Pricing\PriceVersion::class)->bump());

    expect(Fx::decide($this->scenario, $ana, $order))->toBe(ApprovalOutcome::Invalidated);

    $stored = Fx::fresh($this->scenario, $order);
    // Reprecificado para 360,00: passa do mínimo de isenção da regra.
    expect($order->freight_cents->cents)->toBe(4_590)
        ->and($stored->freight_cents->cents)->toBe(0)
        ->and($stored->total_cents->cents)->toBe(36_000);
});

it('valida a regra e a condição de frete e exige permissão', function (): void {
    $save = fn (array $args) => $this->scenario->tenant->actingAs($this->manager, static fn () => app(SaveFreightRule::class)->handle(...$args));

    expect(fn () => $save([null, '', 'SP', '10,00', null, 0, true]))->toThrow(InvalidFreightRule::class, 'nome')
        ->and(fn () => $save([null, 'Sul', 'XX, SP', '10,00', null, 0, true]))->toThrow(InvalidFreightRule::class, 'UF inválida: XX')
        ->and(fn () => $save([null, 'Sul', 'SP', 'dez', null, 0, true]))->toThrow(InvalidFreightRule::class, 'Valor inválido')
        ->and(fn () => $save([null, 'Sul', 'SP', '-1,00', null, 0, true]))->toThrow(InvalidFreightRule::class, 'negativo')
        ->and(fn () => $save([null, 'Sul', 'SP', '10,00', '0,00', 0, true]))->toThrow(InvalidFreightRule::class, 'subtotal positivo')
        ->and(fn () => $save([null, 'Sul', 'SP', '10,00', null, 5000, true]))->toThrow(InvalidFreightRule::class, 'prioridade')
        ->and(fn () => $this->scenario->tenant->actingAs($this->manager, fn () => app(SaveBuyerFreightCondition::class)->handle($this->buyer['company']->id, FreightConditionKind::Fixed, 'x')))->toThrow(InvalidFreightRule::class);

    $rule = $save([null, 'Sul', 'rs sc pr', '10,00', null, 1, true]);
    $updated = $save([$rule->id, 'Sul e Sudeste', 'RS SC PR SP', '12,00', '500,00', 2, false]);
    expect($updated->id)->toBe($rule->id)->and($updated->states)->toBe(['PR', 'RS', 'SC', 'SP'])->and($updated->is_active)->toBeFalse();

    $rep = $this->scenario->tenant->addStaff(SystemRole::SalesRep);
    expect(fn () => $this->scenario->tenant->actingAs($rep, static fn () => app(SaveFreightRule::class)->handle(null, 'Sul', 'SP', '10,00', null, 0, true)))->toThrow(AuthorizationException::class);
});

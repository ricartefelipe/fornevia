<?php

declare(strict_types=1);

use App\Domain\Fulfillment\FreightCalculator;
use App\Domain\Fulfillment\FreightRuleView;
use App\Domain\Fulfillment\FulfillmentProgress;
use App\Domain\Fulfillment\ShipmentEventPolicy;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Fulfillment\FreightConditionKind;
use App\Enums\Fulfillment\ShipmentEventKind;
use App\Enums\Fulfillment\ShipmentStatus;
use App\Enums\Ordering\FulfillmentStatus;

/*
| Regras puras da expedição (RN-090, RN-091) e do frete (RF-092).
*/

function progressItem(string $quantity, string $cancelled = '0', string $shipped = '0', string $delivered = '0'): array
{
    return ['quantity' => Quantity::of($quantity), 'cancelled' => Quantity::of($cancelled), 'shipped' => Quantity::of($shipped), 'delivered' => Quantity::of($delivered)];
}

it('calcula a situação de atendimento pelo que não foi cancelado', function (array $items, FulfillmentStatus $expected): void {
    expect(FulfillmentProgress::of($items))->toBe($expected);
})->with([
    'nada expedido' => [[progressItem('10')], FulfillmentStatus::Pending],
    'parte expedida' => [[progressItem('10', shipped: '4'), progressItem('2')], FulfillmentStatus::PartiallyShipped],
    'restante cancelado' => [[progressItem('10', cancelled: '6', shipped: '4')], FulfillmentStatus::Shipped],
    'parte entregue' => [[progressItem('10', shipped: '10', delivered: '3')], FulfillmentStatus::PartiallyDelivered],
    'tudo entregue' => [[progressItem('10', cancelled: '2', shipped: '8', delivered: '8'), progressItem('1', cancelled: '1')], FulfillmentStatus::Delivered],
    'tudo cancelado' => [[progressItem('5', cancelled: '5')], FulfillmentStatus::Pending],
    'sem itens' => [[], FulfillmentStatus::Pending],
]);

it('avança a remessa só de expedida para entregue', function (ShipmentStatus $current, ShipmentEventKind $kind, ?ShipmentStatus $expected): void {
    expect(ShipmentEventPolicy::transition($current, $kind))->toBe($expected);
})->with([
    'entrega da expedida' => [ShipmentStatus::Shipped, ShipmentEventKind::Delivered, ShipmentStatus::Delivered],
    'em trânsito' => [ShipmentStatus::Shipped, ShipmentEventKind::InTransit, null],
    'ocorrência' => [ShipmentStatus::Shipped, ShipmentEventKind::Exception, null],
    'trânsito depois da entrega' => [ShipmentStatus::Delivered, ShipmentEventKind::InTransit, null],
    'entrega repetida' => [ShipmentStatus::Delivered, ShipmentEventKind::Delivered, null],
    'entrega antes da expedição' => [ShipmentStatus::Preparing, ShipmentEventKind::Delivered, null],
]);

it('aceita eventos só de remessa expedida', function (): void {
    expect(ShipmentEventPolicy::accepts(ShipmentStatus::Shipped))->toBeTrue()
        ->and(ShipmentEventPolicy::accepts(ShipmentStatus::Delivered))->toBeTrue()
        ->and(ShipmentEventPolicy::accepts(ShipmentStatus::Preparing))->toBeFalse()
        ->and(ShipmentEventPolicy::accepts(ShipmentStatus::Cancelled))->toBeFalse();
});

function freightRule(string $id, array $states, int $amount, ?int $freeFrom = null, int $priority = 0): FreightRuleView
{
    return new FreightRuleView($id, "Regra {$id}", $states, Money::ofCents($amount), $freeFrom !== null ? Money::ofCents($freeFrom) : null, $priority);
}

it('calcula o frete pela condição negociada, pela regra da UF ou zero', function (?FreightConditionKind $negotiated, ?string $state, int $subtotal, int $expected, string $source): void {
    $rules = [
        freightRule('a', [], 5_000),
        freightRule('b', ['SP', 'RJ'], 3_000, freeFrom: 100_000, priority: 10),
        freightRule('c', ['SP'], 2_000, priority: 10),
    ];
    $quote = FreightCalculator::calculate($negotiated, Money::ofCents(1_500), $rules, $state, Money::ofCents($subtotal));

    expect($quote->amount->cents)->toBe($expected)->and($quote->source)->toContain($source);
})->with([
    'negociado isento' => [FreightConditionKind::Free, 'SP', 10_000, 0, 'isento negociado'],
    'negociado fixo' => [FreightConditionKind::Fixed, 'SP', 10_000, 1_500, 'negociado'],
    'empate de prioridade fica com o menor' => [null, 'sp', 10_000, 2_000, 'Regra c'],
    'isenção pelo subtotal' => [null, 'RJ', 150_000, 0, 'isento (Regra b)'],
    'abaixo do mínimo de isenção' => [null, 'RJ', 99_999, 3_000, 'Regra b'],
    'regra geral' => [null, 'MG', 10_000, 5_000, 'Regra a'],
    'sem UF usa a geral' => [null, null, 10_000, 5_000, 'Regra a'],
]);

it('cobra zero sem regra para a região e guarda a origem do cálculo', function (): void {
    $quote = FreightCalculator::calculate(null, Money::zero(), [freightRule('b', ['SP'], 3_000)], 'BA', Money::ofCents(10_000));

    expect($quote->amount->cents)->toBe(0)
        ->and($quote->source)->toBe('Sem frete cadastrado para a região')
        ->and(FreightCalculator::calculate(null, Money::zero(), [freightRule('b', ['SP'], 3_000)], 'SP', Money::ofCents(1))->snapshot())
        ->toBe(['amount_cents' => 3_000, 'source' => 'Regra b', 'rule_id' => 'b']);
});

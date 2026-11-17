<?php

declare(strict_types=1);

use App\Domain\Ordering\DocumentTotals;
use App\Domain\Ordering\LineAmounts;
use App\Domain\Pricing\DiscountPolicy;
use App\Domain\Pricing\DiscountViolation as V;
use App\Domain\Shared\Money;
use App\Domain\Shared\Percentage;
use App\Domain\Shared\Quantity;

it('aceita desconto dentro do limite do perfil e da margem mínima', function (): void {
    $evaluation = (new DiscountPolicy)->evaluate(
        Money::ofCents(10_000), Percentage::parse('5'), Percentage::parse('5'), null, Money::ofCents(7_000), Percentage::parse('20'),
    );

    expect($evaluation->requiresCommercialApproval())->toBeFalse()
        ->and($evaluation->netUnitPrice->cents)->toBe(9_500)
        ->and($evaluation->margin?->basisPoints)->toBe(2632);
});

it('exige aprovação comercial acima do limite, abaixo do mínimo, do custo ou da margem', function (): void {
    $evaluation = (new DiscountPolicy)->evaluate(
        Money::ofCents(10_000), Percentage::parse('35'), Percentage::parse('5'), Money::ofCents(8_000), Money::ofCents(7_000), Percentage::parse('20'),
    );

    expect($evaluation->violations)->toBe([V::ExceedsRoleLimit, V::BelowMinimumPrice, V::BelowCost, V::BelowMinimumMargin])
        ->and($evaluation->requiresCommercialApproval())->toBeTrue()
        ->and(V::BelowCost->label())->toBe('Preço abaixo do custo');
});

it('dispensa margem quando o custo não é conhecido', function (): void {
    $evaluation = (new DiscountPolicy)->evaluate(Money::ofCents(1000), Percentage::zero(), Percentage::zero(), null, null, Percentage::parse('30'));

    expect($evaluation->margin)->toBeNull()->and($evaluation->violations)->toBe([]);
});

it('calcula a linha pelo preço líquido unitário arredondado', function (): void {
    // R$ 10,05 com 7,5% → 9,30 por unidade; 3 unidades → 27,90
    $line = LineAmounts::calculate(Money::ofCents(1005), Percentage::parse('7,5'), Quantity::of(3));

    expect($line->unitNetPrice->cents)->toBe(930)
        ->and($line->gross->cents)->toBe(3015)
        ->and($line->total->cents)->toBe(2790)
        ->and($line->discountAmount()->cents)->toBe(225);
});

it('soma os totais do documento sem rateio implícito', function (): void {
    $lines = [
        LineAmounts::calculate(Money::ofCents(1005), Percentage::parse('7,5'), Quantity::of(3)),
        LineAmounts::calculate(Money::ofCents(250), Percentage::zero(), Quantity::of('2.5')),
    ];
    $totals = DocumentTotals::of($lines, Money::ofCents(1500));

    expect([$totals->subtotal->cents, $totals->discount->cents, $totals->freight->cents, $totals->total->cents])
        ->toBe([3640, 225, 1500, 4915]);
});

<?php

declare(strict_types=1);

use App\Domain\Pricing\DiscountPolicy;
use App\Domain\Pricing\DiscountViolation;
use App\Domain\Quotes\CommercialReview;
use App\Domain\Quotes\CommercialReviewLine;
use App\Domain\Quotes\DiscountForNetPrice;
use App\Domain\Shared\Money;
use App\Domain\Shared\Percentage;

/*
| Avaliação comercial da versão (RN-041, RN-032, RN-033) e o auxiliar de
| desconto pelo preço líquido desejado.
*/

function reviewLine(int $number, int $priceCents, int $discountBps, int $authorLimitBps, ?int $minCents = null, ?int $costCents = null, int $minMarginBps = 0): CommercialReviewLine
{
    $evaluation = (new DiscountPolicy)->evaluate(
        Money::ofCents($priceCents),
        Percentage::ofBasisPoints($discountBps),
        Percentage::ofBasisPoints($authorLimitBps),
        $minCents !== null ? Money::ofCents($minCents) : null,
        $costCents !== null ? Money::ofCents($costCents) : null,
        Percentage::ofBasisPoints($minMarginBps),
    );

    return new CommercialReviewLine($number, "sku-{$number}", $evaluation, $costCents !== null ? Money::ofCents($costCents) : null, $minCents !== null ? Money::ofCents($minCents) : null, Percentage::ofBasisPoints($minMarginBps));
}

it('não exige aprovação dentro da política', function (): void {
    $review = new CommercialReview(Percentage::ofBasisPoints(500), [reviewLine(1, 10_000, 500, 500), reviewLine(2, 5_000, 0, 500, costCents: 3_000, minMarginBps: 2_000)]);

    expect($review->requiresApproval())->toBeFalse()
        ->and($review->violations())->toBe([])
        ->and($review->maxDiscount()->basisPoints)->toBe(500);
});

it('reúne as violações de todas as linhas e o maior desconto', function (): void {
    $review = new CommercialReview(Percentage::ofBasisPoints(500), [
        reviewLine(1, 10_000, 1_200, 500),
        reviewLine(2, 10_000, 300, 500, minCents: 9_800),
        reviewLine(3, 10_000, 0, 500, costCents: 10_500),
        reviewLine(4, 10_000, 200, 500, costCents: 8_000, minMarginBps: 2_000),
    ]);

    expect($review->requiresApproval())->toBeTrue()
        ->and($review->violations())->toBe([DiscountViolation::ExceedsRoleLimit, DiscountViolation::BelowMinimumPrice, DiscountViolation::BelowMinimumMargin, DiscountViolation::BelowCost])
        ->and($review->maxDiscount()->basisPoints)->toBe(1_200)
        ->and($review->lines[2]->toArray()['violations'])->toBe(['below_cost', 'below_minimum_margin'])
        ->and($review->lines[0]->toArray())->toMatchArray(['discount_bps' => 1_200, 'unit_net_cents' => 8_800, 'unit_cost_cents' => null, 'margin_bps' => null]);
});

it('aceita como aprovador só quem tem limite igual ou maior que o maior desconto', function (): void {
    $review = new CommercialReview(Percentage::ofBasisPoints(500), [reviewLine(1, 10_000, 1_500, 500)]);

    expect($review->coveredBy(Percentage::ofBasisPoints(1_499)))->toBeFalse()
        ->and($review->coveredBy(Percentage::ofBasisPoints(1_500)))->toBeTrue()
        ->and($review->selfApprovable(true))->toBeFalse();

    $belowCost = new CommercialReview(Percentage::ofBasisPoints(1_500), [reviewLine(1, 10_000, 1_000, 1_500, costCents: 9_500)]);

    expect($belowCost->requiresApproval())->toBeTrue()
        ->and($belowCost->selfApprovable(true))->toBeTrue()
        ->and($belowCost->selfApprovable(false))->toBeFalse();
});

it('calcula o desconto que leva ao preço líquido desejado', function (int $price, int $net, int $bps): void {
    $discount = DiscountForNetPrice::of(Money::ofCents($price), Money::ofCents($net));

    expect($discount->basisPoints)->toBe($bps);
})->with([
    'exato' => [10_000, 9_000, 1_000],
    'sem desconto' => [10_000, 10_000, 0],
    'menor desconto que chega ao líquido' => [3_333, 3_000, 998],
    'menor desconto em preço baixo' => [199, 150, 2_438],
    'mais próximo sem exato' => [1_000_000, 999_940, 1],
    'empate sem exato fica com o menor' => [1_000_000, 999_950, 0],
]);

it('recusa preço líquido acima da tabela ou não positivo', function (int $net): void {
    expect(fn () => DiscountForNetPrice::of(Money::ofCents(1_000), Money::ofCents($net)))->toThrow(InvalidArgumentException::class);
})->with([[1_001], [0]]);

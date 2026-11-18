<?php

declare(strict_types=1);

use App\Domain\Shared\Money;
use App\Domain\Shared\Percentage;
use App\Domain\Shared\Quantity;

it('converte texto em centavos sem ponto flutuante', function (string $input, int $cents): void {
    expect(Money::parse($input)->cents)->toBe($cents);
})->with([
    ['1234.56', 123456],
    ['1.234,56', 123456],
    ['0,1', 10],
    ['10', 1000],
    ['-5,05', -505],
]);

it('rejeita valores monetários com mais de duas casas ou malformados', function (string $input): void {
    Money::parse($input);
})->with(['1,234', 'abc', '1.2.3', '12,'])->throws(InvalidArgumentException::class);

it('multiplica por quantidade fracionária arredondando HALF_UP ao centavo', function (): void {
    // R$ 0,33 × 2,5 = 0,825 → 0,83
    expect(Money::ofCents(33)->times(Quantity::of('2.5'))->cents)->toBe(83)
        // R$ 0,33 × 1,5 = 0,495 → 0,50
        ->and(Money::ofCents(33)->times(Quantity::of('1.5'))->cents)->toBe(50)
        ->and(Money::ofCents(1999)->times(Quantity::of(12))->cents)->toBe(23988);
});

it('aplica desconto percentual com arredondamento explícito', function (): void {
    // R$ 10,05 com 7,5% = 9,29625 → 9,30
    expect(Money::ofCents(1005)->discountedBy(Percentage::parse('7,5'))->cents)->toBe(930)
        ->and(Money::ofCents(1000)->discountedBy(Percentage::zero())->cents)->toBe(1000)
        ->and(Money::ofCents(1000)->discountedBy(Percentage::ofBasisPoints(10_000))->cents)->toBe(0);
});

it('calcula razão em pontos-base e trata base zero', function (): void {
    expect(Money::ofCents(1000)->ratioOf(Money::ofCents(250))?->basisPoints)->toBe(2500)
        ->and(Money::ofCents(300)->ratioOf(Money::ofCents(100))?->basisPoints)->toBe(3333)
        ->and(Money::zero()->ratioOf(Money::ofCents(1)))->toBeNull()
        ->and(Money::ofCents(100)->ratioOf(Money::ofCents(-20))?->basisPoints)->toBe(-2000);
});

it('divide em parcelas que somam exatamente o total', function (): void {
    $parts = Money::ofCents(10_000)->split(3);

    expect(array_map(static fn (Money $m): int => $m->cents, $parts))->toBe([3334, 3333, 3333])
        ->and(Money::sum($parts)->cents)->toBe(10_000)
        ->and(array_map(static fn (Money $m): int => $m->cents, Money::ofCents(-5)->split(2)))->toBe([-3, -2]);
});

it('rejeita divisão em zero partes', function (): void {
    Money::ofCents(100)->split(0);
})->throws(InvalidArgumentException::class);

it('formata em reais e compara valores', function (): void {
    expect(Money::ofCents(123456789)->format())->toBe('R$ 1.234.567,89')
        ->and(Money::ofCents(-5)->format())->toBe('-R$ 0,05')
        ->and(Money::ofCents(123456)->toDecimalString())->toBe('1234.56')
        ->and(Money::ofCents(10)->greaterThan(Money::ofCents(9)))->toBeTrue()
        ->and(Money::ofCents(10)->lessThan(Money::ofCents(9)))->toBeFalse()
        ->and(Money::ofCents(10)->equals(Money::ofCents(10)))->toBeTrue()
        ->and(Money::max(Money::ofCents(3), Money::ofCents(7))->cents)->toBe(7)
        ->and(Money::ofCents(-1)->isNegative())->toBeTrue()
        ->and(Money::zero()->isZero())->toBeTrue()
        ->and(Money::ofCents(5)->negated()->cents)->toBe(-5)
        ->and(Money::ofCents(5)->isPositive())->toBeTrue()
        ->and(Money::ofCents(5)->minus(Money::ofCents(7))->cents)->toBe(-2)
        ->and(Money::ofCents(5)->greaterThanOrEqual(Money::ofCents(5)))->toBeTrue();
});

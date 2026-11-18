<?php

declare(strict_types=1);

use App\Domain\Shared\Percentage;
use App\Domain\Shared\Quantity;
use Brick\Math\BigDecimal;

it('converte percentuais em pontos-base', function (string $input, int $bps): void {
    expect(Percentage::parse($input)->basisPoints)->toBe($bps);
})->with([['5', 500], ['12,5', 1250], ['0.01', 1], ['100', 10_000]]);

it('rejeita percentuais fora do intervalo ou malformados', function (string $input): void {
    Percentage::parse($input);
})->with(['101', '-1', '1,234', 'x'])->throws(InvalidArgumentException::class);

it('formata percentuais', function (): void {
    expect(Percentage::ofBasisPoints(1250)->format())->toBe('12,50%')
        ->and(Percentage::ofBasisPoints(500)->format())->toBe('5%')
        ->and(Percentage::ofBasisPoints(-2000, allowOutOfRange: true)->format())->toBe('-20%')
        ->and(Percentage::ofBasisPoints(300)->greaterThan(Percentage::ofBasisPoints(200)))->toBeTrue()
        ->and(Percentage::zero()->isZero())->toBeTrue();
});

it('aceita quantidades em texto ou inteiro com até 3 casas', function (): void {
    expect(Quantity::of('2,5')->toDatabase())->toBe('2.500')
        ->and(Quantity::of(12)->toDatabase())->toBe('12.000')
        ->and(Quantity::of('0.125')->decimalPlaces())->toBe(3)
        ->and(Quantity::of('3.100')->decimalPlaces())->toBe(1)
        ->and(Quantity::of('1234.5')->format())->toBe('1.234,5')
        ->and(Quantity::of('7')->format())->toBe('7');
});

it('rejeita quantidades com mais de 3 casas ou inválidas', function (string $input): void {
    Quantity::of($input);
})->with(['1.0001', '1e3', 'abc', ''])->throws(InvalidArgumentException::class);

it('converte unidades de forma exata e recusa conversões não representáveis', function (): void {
    expect(Quantity::of(3)->convertedBy(BigDecimal::of('12'))->toDatabase())->toBe('36.000')
        ->and(Quantity::of('1.5')->convertedBy(BigDecimal::of('0.5'))->toDatabase())->toBe('0.750');

    Quantity::of('1.001')->convertedBy(BigDecimal::of('0.001'));
})->throws(InvalidArgumentException::class);

it('verifica múltiplos e compara quantidades', function (): void {
    expect(Quantity::of(24)->isMultipleOf(Quantity::of(12)))->toBeTrue()
        ->and(Quantity::of(13)->isMultipleOf(Quantity::of(12)))->toBeFalse()
        ->and(Quantity::of('1.5')->isMultipleOf(Quantity::of('0.5')))->toBeTrue()
        ->and(Quantity::of(5)->isMultipleOf(Quantity::zero()))->toBeFalse()
        ->and(Quantity::min(Quantity::of(2), Quantity::of(1))->toDatabase())->toBe('1.000')
        ->and(Quantity::of(1)->minus(Quantity::of(2))->isNegative())->toBeTrue()
        ->and(Quantity::of(2)->equals(Quantity::of('2.000')))->toBeTrue();
});

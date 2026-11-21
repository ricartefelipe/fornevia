<?php

declare(strict_types=1);

use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Models\Buyers\BuyerCompany;
use App\Support\Http\UntrustedId;
use Brick\Math\BigDecimal;

/*
| Entrada de quantidades em pt-BR e limites de magnitude: valores além das
| colunas (bigint em centavos, numeric(18,3)) são recusados como regra de
| negócio, sem estouro numérico.
*/

it('interpreta quantidades digitadas em português', function (string $input, string $expected): void {
    expect(Quantity::parseLocalized($input)->toDatabase())->toBe($expected);
})->with([
    'milhar com ponto' => ['1.000', '1000.000'],
    'milhar e decimal' => ['1.250,5', '1250.500'],
    'milhões' => ['2.000.000', '2000000.000'],
    'vírgula decimal' => ['2,5', '2.500'],
    'três casas com vírgula' => ['0,125', '0.125'],
    'inteiro' => ['1000', '1000.000'],
    'ponto com uma casa' => ['2.5', '2.500'],
    'ponto com duas casas' => ['12.75', '12.750'],
    'espaços nas pontas' => ['  48 ', '48.000'],
    'espaço não separável' => ["1\u{00A0}000", '1000.000'],
    'zero com ponto e três casas' => ['0.500', '0.500'],
    'zero com ponto e casa única' => ['0.5', '0.500'],
    'milhar com centena' => ['100.000', '100000.000'],
]);

it('recusa quantidades em formato ambíguo ou inválido', function (string $input): void {
    expect(static fn () => Quantity::parseLocalized($input))->toThrow(InvalidArgumentException::class);
})->with([
    'vazio' => [''],
    'texto' => ['dez'],
    'negativo' => ['-1'],
    'milhar mal agrupado' => ['10.00.000'],
    'dois separadores decimais' => ['1,2,3'],
    'quatro casas' => ['1,2345'],
    'ponto com três casas e vírgula' => ['1.2345,6'],
    'milhar com zero à esquerda' => ['01.000'],
    'zero com quatro casas' => ['0.1234'],
]);

it('recusa quantidades acima de numeric(18,3)', function (): void {
    expect(Quantity::of('999999999999999.999')->toDatabase())->toBe('999999999999999.999')
        ->and(static fn () => Quantity::of('1000000000000000'))->toThrow(AmountOutOfRange::class)
        ->and(static fn () => Quantity::parseLocalized('1.000.000.000.000.000'))->toThrow(AmountOutOfRange::class)
        ->and(static fn () => Quantity::fromDecimal(BigDecimal::of('1000000000000000')))->toThrow(AmountOutOfRange::class)
        ->and(static fn () => Quantity::of('999999999999999')->plus(Quantity::of(1)))->toThrow(AmountOutOfRange::class)
        ->and(static fn () => Quantity::of('500000000000000')->convertedBy(BigDecimal::of('2')))->toThrow(AmountOutOfRange::class);
});

it('recusa valores monetários acima do limite suportado', function (): void {
    expect(Money::ofCents(Money::MAX_CENTS)->cents)->toBe(Money::MAX_CENTS)
        ->and(static fn () => Money::ofCents(Money::MAX_CENTS + 1))->toThrow(AmountOutOfRange::class)
        ->and(static fn () => Money::ofCents(-Money::MAX_CENTS - 1))->toThrow(AmountOutOfRange::class)
        ->and(static fn () => Money::parse('99999999999999999,99'))->toThrow(AmountOutOfRange::class)
        ->and(static fn () => Money::parse('99999999999999999999999'))->toThrow(AmountOutOfRange::class)
        ->and(static fn () => Money::ofCents(Money::MAX_CENTS)->plus(Money::ofCents(1)))->toThrow(AmountOutOfRange::class)
        ->and(static fn () => Money::ofCents(1_000_000)->times(Quantity::of('999999999999999')))->toThrow(AmountOutOfRange::class);
});

it('expõe o código estável da violação de intervalo', function (): void {
    expect((new AmountOutOfRange('fora'))->errorCode())->toBe('amount_out_of_range');
});

it('retorna o menor de dois valores', function (): void {
    expect(Money::min(Money::ofCents(150), Money::ofCents(90))->cents)->toBe(90)
        ->and(Money::min(Money::ofCents(-5), Money::ofCents(0))->cents)->toBe(-5)
        ->and(Money::min(Money::ofCents(7), Money::ofCents(7))->cents)->toBe(7);
});

it('normaliza identificadores recebidos para a forma minúscula', function (): void {
    expect(UntrustedId::uuid('0195F3A4-7B2C-7D10-9E8F-1A2B3C4D5E6F', BuyerCompany::class))
        ->toBe('0195f3a4-7b2c-7d10-9e8f-1a2b3c4d5e6f');
});

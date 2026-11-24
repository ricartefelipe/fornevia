<?php

declare(strict_types=1);

use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\Cnpj;
use App\Domain\Shared\Money;
use App\Domain\Shared\Percentage;
use App\Domain\Shared\Quantity;

/*
| Fronteiras dos objetos de valor: limites exatos, igualdade, zero, sinal,
| espaços na entrada e o dígito verificador do CNPJ conferido contra uma
| implementação de referência escrita aqui, independente da de produção.
*/

/** Dígito verificador do CNPJ pela regra da Receita Federal (módulo 11). */
function referenceCnpjDigit(string $base): string
{
    $sum = 0;
    $weight = strlen($base) - 7;

    foreach (str_split($base) as $digit) {
        $sum += (int) $digit * $weight;
        $weight = $weight === 2 ? 9 : $weight - 1;
    }

    $rest = $sum % 11;

    return (string) ($rest < 2 ? 0 : 11 - $rest);
}

it('limita o valor monetário nos dois sentidos, inclusive no extremo exato', function (): void {
    expect(Money::ofCents(Money::MAX_CENTS)->cents)->toBe(Money::MAX_CENTS)
        ->and(Money::ofCents(-Money::MAX_CENTS)->cents)->toBe(-Money::MAX_CENTS)
        ->and(fn () => Money::ofCents(Money::MAX_CENTS + 1))->toThrow(AmountOutOfRange::class)
        ->and(fn () => Money::ofCents(-Money::MAX_CENTS - 1))->toThrow(AmountOutOfRange::class);
});

it('compara valores iguais sem considerá-los maiores ou menores', function (): void {
    $a = Money::ofCents(500);
    $b = Money::ofCents(500);

    expect($a->greaterThan($b))->toBeFalse()->and($a->lessThan($b))->toBeFalse()
        ->and(Money::min($a, $b))->toBe($a)->and(Money::max($a, $b))->toBe($a)
        ->and(Money::ofCents(0)->isNegative())->toBeFalse()->and(Money::ofCents(-1)->isNegative())->toBeTrue()
        ->and(Money::ofCents(0)->isPositive())->toBeFalse()->and(Money::ofCents(1)->isPositive())->toBeTrue()
        ->and(Money::parse(' 10,50 ')->cents)->toBe(1050);
});

it('divide em uma parte e preserva o sinal de valores negativos', function (): void {
    expect(array_map(static fn (Money $m): int => $m->cents, Money::ofCents(7)->split(1)))->toBe([7])
        ->and(array_map(static fn (Money $m): int => $m->cents, Money::ofCents(-1)->split(1)))->toBe([-1])
        ->and(array_map(static fn (Money $m): int => $m->cents, Money::ofCents(-5)->split(2)))->toBe([-3, -2])
        ->and(fn () => Money::ofCents(7)->split(0))->toThrow(InvalidArgumentException::class);
});

it('formata zero sem sinal e centavos negativos com sinal', function (): void {
    expect(Money::ofCents(0)->format())->toBe('R$ 0,00')
        ->and(Money::ofCents(-1)->format())->toBe('-R$ 0,01')
        ->and(Money::ofCents(1)->format())->toBe('R$ 0,01');
});

it('limita o percentual de 0% a 100% e formata nas fronteiras', function (): void {
    expect(Percentage::ofBasisPoints(0)->basisPoints)->toBe(0)
        ->and(Percentage::ofBasisPoints(10_000)->basisPoints)->toBe(10_000)
        ->and(fn () => Percentage::ofBasisPoints(-1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Percentage::ofBasisPoints(10_001))->toThrow(InvalidArgumentException::class)
        ->and(Percentage::parse(' 12,5 ')->basisPoints)->toBe(1250)
        ->and(Percentage::ofBasisPoints(500)->lessThan(Percentage::ofBasisPoints(500)))->toBeFalse()
        ->and(Percentage::zero()->format())->toBe('0%')
        ->and(Percentage::ofBasisPoints(-1, allowOutOfRange: true)->format())->toBe('-0,01%')
        ->and(Percentage::ofBasisPoints(1)->format())->toBe('0,01%')
        ->and(Percentage::ofBasisPoints(9_900)->format())->toBe('99%');
});

it('lê quantidades com espaços, separadores e três casas exatas', function (): void {
    expect(Quantity::of(' 2,5 ')->toDatabase())->toBe('2.500')
        ->and(Quantity::of('1.123')->toDatabase())->toBe('1.123')
        ->and(fn () => Quantity::of('1.1234'))->toThrow(InvalidArgumentException::class)
        ->and(Quantity::parseLocalized('1 000')->toDatabase())->toBe('1000.000')
        ->and(Quantity::parseLocalized("1\u{00A0}000")->toDatabase())->toBe('1000.000')
        ->and(Quantity::parseLocalized("\t2,5\n")->toDatabase())->toBe('2.500')
        ->and(Quantity::of('2')->convertedBy(Brick\Math\BigDecimal::of('0.125'))->toDatabase())->toBe('0.250');
});

it('compara e formata quantidades iguais e negativas', function (): void {
    $a = Quantity::of('3');
    $b = Quantity::of('3');

    expect($a->greaterThan($b))->toBeFalse()->and($a->lessThan($b))->toBeFalse()
        ->and(Quantity::min($a, $b))->toBe($a)
        ->and(Quantity::of('-1234.5')->format())->toBe('-1.234,5')
        ->and(Quantity::of('-2')->format())->toBe('-2')
        ->and(Quantity::of('1234')->format())->toBe('1.234');
});

it('confere os dígitos do CNPJ como a implementação de referência', function (): void {
    $seed = 12345;

    for ($n = 0; $n < 300; $n++) {
        $seed = ($seed * 1103515245 + 12345) % 2147483648;
        $base = str_pad((string) ($seed % 100_000_000), 8, '0', STR_PAD_LEFT).str_pad((string) (($seed >> 8) % 10_000), 4, '0', STR_PAD_LEFT);
        $first = referenceCnpjDigit($base);
        $valid = $base.$first.referenceCnpjDigit($base.$first);

        if (preg_match('/^(\d)\1{13}$/D', $valid) === 1) {
            continue;
        }

        expect(Cnpj::isValid($valid))->toBeTrue($valid);

        foreach ([12, 13] as $position) {
            $wrong = $valid;
            $wrong[$position] = (string) (((int) $valid[$position] + 1) % 10);
            expect(Cnpj::isValid($wrong))->toBeFalse($wrong);
        }
    }

    expect(Cnpj::fromString('11.222.333/0001-81')->digits)->toBe('11222333000181')
        ->and(Cnpj::format('11222333000181'))->toBe('11.222.333/0001-81');
});

<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Valor monetário em BRL, representado em centavos inteiros (ADR-004).
 *
 * Multiplicações por quantidades e percentuais usam BigDecimal e
 * arredondamento HALF_UP explícito para o centavo. Nunca usa float.
 */
final readonly class Money
{
    private function __construct(public int $cents) {}

    public static function ofCents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Converte texto decimal ("1234.56" ou "1.234,56") em centavos, sem float.
     * Aceita no máximo duas casas decimais.
     */
    public static function parse(string $value): self
    {
        $normalized = trim($value);

        if (preg_match('/^-?\d{1,3}(\.\d{3})*,\d{1,2}$|^-?\d+,\d{1,2}$/D', $normalized) === 1) {
            $normalized = str_replace(['.', ','], ['', '.'], $normalized);
        }

        if (preg_match('/^-?\d+(\.\d{1,2})?$/D', $normalized) !== 1) {
            throw new InvalidArgumentException("Valor monetário inválido: {$value}");
        }

        return new self(BigDecimal::of($normalized)->multipliedBy(100)->toScale(0)->toInt());
    }

    public function plus(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function minus(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function negated(): self
    {
        return new self(-$this->cents);
    }

    /**
     * Preço unitário × quantidade, arredondado HALF_UP ao centavo.
     */
    public function times(Quantity $quantity): self
    {
        return new self(
            BigDecimal::of($this->cents)
                ->multipliedBy($quantity->value)
                ->toScale(0, RoundingMode::HalfUp)
                ->toInt(),
        );
    }

    /**
     * Valor com desconto percentual aplicado, arredondado HALF_UP ao centavo.
     */
    public function discountedBy(Percentage $discount): self
    {
        return new self(
            BigDecimal::of($this->cents)
                ->multipliedBy(BigDecimal::of(10_000 - $discount->basisPoints))
                ->dividedBy(10_000, 0, RoundingMode::HalfUp)
                ->toInt(),
        );
    }

    /**
     * Percentual deste valor representado por outro (ex.: margem), em bps
     * arredondados HALF_UP. Retorna null quando a base é zero.
     */
    public function ratioOf(self $part): ?Percentage
    {
        if ($this->cents === 0) {
            return null;
        }

        return Percentage::ofBasisPoints(
            BigDecimal::of($part->cents)
                ->multipliedBy(10_000)
                ->dividedBy($this->cents, 0, RoundingMode::HalfUp)
                ->toInt(),
            allowOutOfRange: true,
        );
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function greaterThan(self $other): bool
    {
        return $this->cents > $other->cents;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->cents >= $other->cents;
    }

    public function lessThan(self $other): bool
    {
        return $this->cents < $other->cents;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    public static function max(self $a, self $b): self
    {
        return $a->cents >= $b->cents ? $a : $b;
    }

    /**
     * @param  iterable<self>  $values
     */
    public static function sum(iterable $values): self
    {
        $total = 0;

        foreach ($values as $value) {
            $total += $value->cents;
        }

        return new self($total);
    }

    /**
     * Divide o valor em N partes que somam exatamente o total; os centavos
     * restantes vão para as primeiras partes (parcelas, rateios).
     *
     * @return list<self>
     */
    public function split(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Quantidade de partes inválida.');
        }

        $sign = $this->cents < 0 ? -1 : 1;
        $absolute = abs($this->cents);
        $base = intdiv($absolute, $parts);
        $remainder = $absolute % $parts;
        $result = [];

        for ($i = 0; $i < $parts; $i++) {
            $result[] = new self($sign * ($base + ($i < $remainder ? 1 : 0)));
        }

        return $result;
    }

    public function toDecimalString(): string
    {
        return (string) BigDecimal::ofUnscaledValue($this->cents, 2);
    }

    /**
     * Formato brasileiro: "R$ 1.234,56" (negativos com sinal antes do símbolo).
     */
    public function format(): string
    {
        $absolute = abs($this->cents);
        $units = number_format(intdiv($absolute, 100), 0, ',', '.');
        $fraction = str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return ($this->cents < 0 ? '-' : '').'R$ '.$units.','.$fraction;
    }
}

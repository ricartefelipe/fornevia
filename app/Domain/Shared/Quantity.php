<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;

/**
 * Quantidade com até 3 casas decimais (numeric(18,3)), em BigDecimal.
 * Não aceita float: valores vêm como string ou inteiro.
 */
final readonly class Quantity
{
    public const int SCALE = 3;

    /** numeric(18,3): até 15 dígitos inteiros. */
    public const int MAX_INTEGER_DIGITS = 15;

    /** Milhares agrupados por ponto: primeiro grupo de 1 a 3 dígitos sem zero à esquerda. */
    public const string THOUSANDS = '/^[1-9]\d{0,2}(\.\d{3})+(,\d+)?$/D';

    /** Ponto decimal: 1 ou 2 casas, ou qualquer número de casas após zero inicial. */
    public const string DOT_DECIMAL = '/^(\d+\.\d{1,2}|0\.\d+)$/D';

    private function __construct(public BigDecimal $value) {}

    public static function of(string|int $value): self
    {
        if (is_string($value)) {
            $value = trim($value);

            if (preg_match('/^-?\d+([.,]\d+)?$/D', $value) !== 1) {
                throw new InvalidArgumentException("Quantidade inválida: {$value}");
            }

            $value = str_replace(',', '.', $value);
        }

        try {
            $decimal = BigDecimal::of($value)->strippedOfTrailingZeros();
        } catch (MathException) {
            throw new InvalidArgumentException("Quantidade inválida: {$value}");
        }

        if ($decimal->getScale() > self::SCALE) {
            throw new InvalidArgumentException('Quantidade com mais de 3 casas decimais.');
        }

        return self::bounded($decimal->toScale(self::SCALE));
    }

    /**
     * Entrada digitada em português: vírgula decimal e ponto como separador
     * de milhar ("1.000" = mil; "1.250,5" = 1250,5; "2,5" = 2,5). Um ponto
     * seguido de 1 ou 2 dígitos é decimal ("2.5"), assim como ponto após um
     * zero inicial ("0.500" = 0,5), que não pode ser agrupamento de milhar.
     */
    public static function parseLocalized(string $input): self
    {
        $value = str_replace([' ', "\u{00A0}"], '', trim($input));

        $normalized = match (true) {
            preg_match(self::THOUSANDS, $value) === 1 => str_replace(['.', ','], ['', '.'], $value),
            preg_match('/^\d+(,\d+)?$/D', $value) === 1 => str_replace(',', '.', $value),
            preg_match(self::DOT_DECIMAL, $value) === 1 => $value,
            default => throw new InvalidArgumentException("Quantidade inválida: {$input}"),
        };

        return self::of($normalized);
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero()->toScale(self::SCALE));
    }

    public static function fromDecimal(BigDecimal $value): self
    {
        if ($value->strippedOfTrailingZeros()->getScale() > self::SCALE) {
            throw new InvalidArgumentException('Quantidade com mais de 3 casas decimais.');
        }

        return self::bounded($value->toScale(self::SCALE));
    }

    private static function bounded(BigDecimal $value): self
    {
        if ($value->abs()->isGreaterThanOrEqualTo(BigDecimal::of('1'.str_repeat('0', self::MAX_INTEGER_DIGITS)))) {
            throw new AmountOutOfRange('Quantidade fora do intervalo suportado.');
        }

        return new self($value);
    }

    public function plus(self $other): self
    {
        return self::bounded($this->value->plus($other->value));
    }

    public function minus(self $other): self
    {
        return self::bounded($this->value->minus($other->value));
    }

    /**
     * Multiplicação por fator de conversão: o resultado precisa caber em 3
     * casas decimais de forma exata; caso contrário a configuração é inválida.
     */
    public function convertedBy(BigDecimal $factor): self
    {
        $result = $this->value->multipliedBy($factor)->strippedOfTrailingZeros();

        if ($result->getScale() > self::SCALE) {
            throw new InvalidArgumentException('Conversão de unidade não representável com 3 casas decimais.');
        }

        return self::bounded($result->toScale(self::SCALE));
    }

    public function isMultipleOf(self $step): bool
    {
        if ($step->value->isZero()) {
            return false;
        }

        return $this->value->remainder($step->value)->isZero();
    }

    public function decimalPlaces(): int
    {
        return max(0, $this->value->strippedOfTrailingZeros()->getScale());
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isPositive(): bool
    {
        return $this->value->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function compareTo(self $other): int
    {
        return $this->value->compareTo($other->value);
    }

    public function greaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public static function min(self $a, self $b): self
    {
        return $a->compareTo($b) <= 0 ? $a : $b;
    }

    /**
     * Valor para persistência em numeric(18,3).
     */
    public function toDatabase(): string
    {
        return (string) $this->value;
    }

    public function format(): string
    {
        $plain = (string) $this->value->strippedOfTrailingZeros()->toScale(max(0, $this->decimalPlaces()));
        [$units, $fraction] = array_pad(explode('.', $plain, 2), 2, '');
        $negative = str_starts_with($units, '-');
        $units = number_format((int) ltrim($units, '-'), 0, ',', '.');

        return ($negative ? '-' : '').$units.($fraction !== '' ? ','.$fraction : '');
    }
}

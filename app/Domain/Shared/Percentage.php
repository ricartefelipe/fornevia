<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;

/**
 * Percentual em pontos-base inteiros (1 bp = 0,01%; 10.000 bp = 100%).
 */
final readonly class Percentage
{
    private function __construct(public int $basisPoints) {}

    public static function ofBasisPoints(int $basisPoints, bool $allowOutOfRange = false): self
    {
        if (! $allowOutOfRange && ($basisPoints < 0 || $basisPoints > 10_000)) {
            throw new InvalidArgumentException('Percentual deve estar entre 0% e 100%.');
        }

        return new self($basisPoints);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Converte "12,5" ou "12.5" (percentual) em pontos-base, sem float.
     */
    public static function parse(string $percent): self
    {
        $normalized = str_replace(',', '.', trim($percent));

        if (preg_match('/^\d{1,3}(\.\d{1,2})?$/D', $normalized) !== 1) {
            throw new InvalidArgumentException("Percentual inválido: {$percent}");
        }

        [$units, $fraction] = array_pad(explode('.', $normalized, 2), 2, '0');

        return self::ofBasisPoints((int) $units * 100 + (int) str_pad($fraction, 2, '0'));
    }

    public function isZero(): bool
    {
        return $this->basisPoints === 0;
    }

    public function greaterThan(self $other): bool
    {
        return $this->basisPoints > $other->basisPoints;
    }

    public function lessThan(self $other): bool
    {
        return $this->basisPoints < $other->basisPoints;
    }

    public function format(): string
    {
        $sign = $this->basisPoints < 0 ? '-' : '';
        $absolute = abs($this->basisPoints);
        $fraction = $absolute % 100;

        return $sign.intdiv($absolute, 100).($fraction !== 0 ? ','.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT) : '').'%';
    }
}

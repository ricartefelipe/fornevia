<?php

declare(strict_types=1);

namespace App\Support\Database\Casts;

use Brick\Math\BigDecimal;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * numeric ↔ BigDecimal com escala fixa (ex.: fatores de conversão 18,6).
 *
 * @implements CastsAttributes<BigDecimal, BigDecimal|string|int>
 */
final class AsDecimal implements CastsAttributes
{
    public function __construct(private readonly int $scale = 6) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?BigDecimal
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("Valor decimal inválido em {$key}.");
        }

        return BigDecimal::of($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof BigDecimal && ! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("Decimal deve ser BigDecimal, string ou inteiro em {$key}.");
        }

        $decimal = BigDecimal::of($value);

        if ($decimal->strippedOfTrailingZeros()->getScale() > $this->scale) {
            throw new InvalidArgumentException("Mais de {$this->scale} casas decimais em {$key}.");
        }

        return (string) $decimal->toScale($this->scale);
    }
}

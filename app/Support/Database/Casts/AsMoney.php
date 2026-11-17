<?php

declare(strict_types=1);

namespace App\Support\Database\Casts;

use App\Domain\Shared\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * bigint (centavos) ↔ Money.
 *
 * @implements CastsAttributes<Money, Money|int>
 */
final class AsMoney implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value)) {
            throw new InvalidArgumentException("Valor monetário inválido em {$key}.");
        }

        return Money::ofCents($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return match (true) {
            $value === null => null,
            $value instanceof Money => $value->cents,
            is_int($value) => $value,
            default => throw new InvalidArgumentException("Valor monetário deve ser Money ou centavos inteiros em {$key}."),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Database\Casts;

use App\Domain\Shared\Quantity;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * numeric(18,3) ↔ Quantity. O PDO entrega numeric como string; nunca há
 * conversão para float.
 *
 * @implements CastsAttributes<Quantity, Quantity|string|int>
 */
final class AsQuantity implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Quantity
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("Valor inválido para quantidade em {$key}.");
        }

        return Quantity::of($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Quantity) {
            return $value->toDatabase();
        }

        if (is_string($value) || is_int($value)) {
            return Quantity::of($value)->toDatabase();
        }

        throw new InvalidArgumentException("Quantidade deve ser Quantity, string ou inteiro em {$key}.");
    }
}

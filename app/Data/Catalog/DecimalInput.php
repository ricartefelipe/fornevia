<?php

declare(strict_types=1);

namespace App\Data\Catalog;

use App\Domain\Shared\Quantity;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Conversão de números digitados no formato brasileiro, sem float.
 *
 * Aceita apenas dígitos e, opcionalmente, vírgula como separador decimal
 * (ex.: "12", "0,5"). O ponto não é aceito para evitar a ambiguidade entre
 * "1.000" (mil) e "1.000" (um com três casas).
 */
final class DecimalInput
{
    public static function decimal(string $value, int $maxScale, int $maxIntegerDigits = 12): BigDecimal
    {
        $normalized = trim($value);

        if (preg_match('/^\d{1,'.$maxIntegerDigits.'}(,\d{1,'.$maxScale.'})?$/D', $normalized) !== 1) {
            throw new InvalidArgumentException("Número inválido: {$value}");
        }

        return BigDecimal::of(str_replace(',', '.', $normalized));
    }

    public static function quantity(string $value): Quantity
    {
        return Quantity::fromDecimal(self::decimal($value, Quantity::SCALE, 15));
    }

    /**
     * Representação no mesmo formato aceito na entrada (vírgula decimal,
     * sem zeros à direita).
     */
    public static function format(BigDecimal $value): string
    {
        $stripped = $value->strippedOfTrailingZeros();

        if ($stripped->getScale() < 0) {
            $stripped = $stripped->toScale(0);
        }

        return str_replace('.', ',', (string) $stripped);
    }
}

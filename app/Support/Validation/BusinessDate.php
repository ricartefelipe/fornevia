<?php

declare(strict_types=1);

namespace App\Support\Validation;

/**
 * Datas comerciais digitadas (vigências, simulações): formato ISO e
 * intervalo plausível. Evita anos como 0000, que o validador de formato
 * aceita mas o PostgreSQL recusa.
 */
final class BusinessDate
{
    public const string EARLIEST = '2000-01-01';

    public const string LATEST = '2099-12-31';

    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['date_format:Y-m-d', 'after_or_equal:'.self::EARLIEST, 'before_or_equal:'.self::LATEST];
    }
}

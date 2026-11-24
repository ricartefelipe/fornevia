<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use Carbon\CarbonImmutable;

/**
 * Data e hora ISO 8601 com fuso explícito (RFC 3339), como no contrato da
 * API: "2026-11-24T10:30:00-03:00" ou "...Z", com segundos e frações
 * opcionais. Textos relativos ("now", "yesterday") e datas inexistentes
 * são recusados.
 */
final class Iso8601
{
    private const string PATTERN = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(Z|[+-](\d{2}):(\d{2}))$/D';

    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value, $m) !== 1) {
            return null;
        }

        [$year, $month, $day, $hour, $minute] = array_map('intval', [$m[1], $m[2], $m[3], $m[4], $m[5]]);
        $second = (int) ($m[6] !== '' ? $m[6] : 0);
        $offsetHour = isset($m[8]) ? (int) $m[8] : 0;
        $offsetMinute = isset($m[9]) ? (int) $m[9] : 0;

        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59 || $offsetHour > 14 || $offsetMinute > 59) {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }
}

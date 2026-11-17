<?php

declare(strict_types=1);

namespace App\Support\Clock;

use Carbon\CarbonImmutable;

final class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.business_timezone', 'America/Sao_Paulo'))->startOfDay();
    }
}

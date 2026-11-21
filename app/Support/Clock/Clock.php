<?php

declare(strict_types=1);

namespace App\Support\Clock;

use Carbon\CarbonImmutable;

/**
 * Relógio injetável nas regras de negócio (vigências, validade de
 * cotações, vencimentos). Substituível nos testes por FrozenClock.
 */
interface Clock
{
    public function now(): CarbonImmutable;

    public function today(): CarbonImmutable;
}

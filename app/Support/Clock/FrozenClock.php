<?php

declare(strict_types=1);

namespace App\Support\Clock;

use Carbon\CarbonImmutable;

/**
 * Relógio fixo para testes e simulações determinísticas.
 */
final class FrozenClock implements Clock
{
    public function __construct(private CarbonImmutable $now) {}

    public static function at(string $instant): self
    {
        return new self(CarbonImmutable::parse($instant, 'UTC'));
    }

    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    public function today(): CarbonImmutable
    {
        return $this->now->setTimezone((string) config('app.business_timezone', 'America/Sao_Paulo'))->startOfDay();
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->add($interval);
    }
}

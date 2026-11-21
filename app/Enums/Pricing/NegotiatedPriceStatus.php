<?php

declare(strict_types=1);

namespace App\Enums\Pricing;

enum NegotiatedPriceStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativa',
            self::Revoked => 'Revogada',
        };
    }
}

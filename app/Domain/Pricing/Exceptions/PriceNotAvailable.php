<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

final class PriceNotAvailable extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'price_not_available';
    }
}

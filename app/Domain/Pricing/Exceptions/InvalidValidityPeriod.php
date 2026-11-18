<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Vigência com término anterior ao início.
 */
final class InvalidValidityPeriod extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_validity_period';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Preço unitário precisa ser maior que zero.
 */
final class InvalidUnitPrice extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_unit_price';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Margem mínima fora do intervalo aceito (-100% a 100%).
 */
final class InvalidMinimumMargin extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_minimum_margin';
    }
}

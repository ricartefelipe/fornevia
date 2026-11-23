<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Dados de devolução inválidos.
 */
final class InvalidReturn extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_return';
    }
}

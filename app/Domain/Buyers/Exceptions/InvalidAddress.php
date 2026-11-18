<?php

declare(strict_types=1);

namespace App\Domain\Buyers\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Endereço com CEP ou UF inválidos.
 */
final class InvalidAddress extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_address';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Dados ou situação da cotação inválidos para a operação.
 */
final class InvalidQuote extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_quote';
    }
}

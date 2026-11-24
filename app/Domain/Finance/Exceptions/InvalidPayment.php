<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Dados de pagamento inválidos.
 */
final class InvalidPayment extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_payment';
    }
}

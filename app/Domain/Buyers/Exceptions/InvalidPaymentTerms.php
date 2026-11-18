<?php

declare(strict_types=1);

namespace App\Domain\Buyers\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Condições de pagamento inativas ou condição padrão fora da lista permitida.
 */
final class InvalidPaymentTerms extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_payment_terms';
    }
}

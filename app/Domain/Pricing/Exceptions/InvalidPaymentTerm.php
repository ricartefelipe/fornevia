<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Condição de pagamento inválida (código, nome, parcelas ou modalidade). O campo afetado vem em details['field'].
 */
final class InvalidPaymentTerm extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_payment_term';
    }
}

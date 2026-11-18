<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Já existe condição de pagamento com o mesmo código no tenant.
 */
final class PaymentTermCodeTaken extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'payment_term_code_taken';
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Credit\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Operação que exige conta de crédito aberta para a empresa compradora.
 */
final class CreditAccountNotOpen extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'credit_account_not_open';
    }
}

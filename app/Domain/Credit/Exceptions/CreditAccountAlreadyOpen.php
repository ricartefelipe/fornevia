<?php

declare(strict_types=1);

namespace App\Domain\Credit\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * A empresa compradora já tem conta de crédito comercial (uma por empresa).
 */
final class CreditAccountAlreadyOpen extends ConflictingState
{
    public function errorCode(): string
    {
        return 'credit_account_already_open';
    }
}

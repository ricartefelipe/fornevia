<?php

declare(strict_types=1);

namespace App\Actions\Credit\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * Revogação de exceção já revogada ou com vigência encerrada.
 */
final class CreditExceptionNotActive extends ConflictingState
{
    public function errorCode(): string
    {
        return 'credit_exception_not_active';
    }
}

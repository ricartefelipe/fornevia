<?php

declare(strict_types=1);

namespace App\Domain\Credit\Exceptions;

use App\Domain\Shared\ConflictingState;

final class CreditNotApproved extends ConflictingState
{
    public function errorCode(): string
    {
        return 'credit_not_approved';
    }
}

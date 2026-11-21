<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Exceptions;

use App\Domain\Shared\ConflictingState;

final class BudgetExceeded extends ConflictingState
{
    public function errorCode(): string
    {
        return 'budget_exceeded';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Shared\ConflictingState;

final class InsufficientStock extends ConflictingState
{
    public function errorCode(): string
    {
        return 'insufficient_stock';
    }
}

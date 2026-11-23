<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * A devolução não está em situação que permita a operação.
 */
final class ReturnNotAllowed extends ConflictingState
{
    public function errorCode(): string
    {
        return 'return_not_allowed';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Buyers\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * Já existe grupo de clientes com este código no distribuidor.
 */
final class CustomerGroupCodeTaken extends ConflictingState
{
    public function errorCode(): string
    {
        return 'customer_group_code_taken';
    }
}

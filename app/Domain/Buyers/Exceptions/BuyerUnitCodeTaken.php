<?php

declare(strict_types=1);

namespace App\Domain\Buyers\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * Já existe unidade com este código na empresa compradora.
 */
final class BuyerUnitCodeTaken extends ConflictingState
{
    public function errorCode(): string
    {
        return 'buyer_unit_code_taken';
    }
}

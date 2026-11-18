<?php

declare(strict_types=1);

namespace App\Domain\Buyers\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * Já existe centro de custo com este código na empresa compradora.
 */
final class CostCenterCodeTaken extends ConflictingState
{
    public function errorCode(): string
    {
        return 'cost_center_code_taken';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * A organização precisa manter ao menos um proprietário ativo.
 */
final class LastOwnerRequired extends ConflictingState
{
    public function errorCode(): string
    {
        return 'last_owner_required';
    }
}

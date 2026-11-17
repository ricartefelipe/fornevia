<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * O usuário já possui acesso ativo neste contexto.
 */
final class AlreadyMember extends ConflictingState
{
    public function errorCode(): string
    {
        return 'already_member';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Convite inexistente, expirado, revogado ou já utilizado.
 */
final class InvalidInvitation extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_invitation';
    }
}

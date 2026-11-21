<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * O papel não corresponde ao tipo de contexto.
 */
final class RoleKindMismatch extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'role_kind_mismatch';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Contexto inexistente, revogado ou indisponível.
 */
final class ContextUnavailable extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'context_unavailable';
    }
}

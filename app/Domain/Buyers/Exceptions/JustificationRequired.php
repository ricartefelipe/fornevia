<?php

declare(strict_types=1);

namespace App\Domain\Buyers\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Justificativa ausente ou fora dos limites de tamanho.
 */
final class JustificationRequired extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'justification_required';
    }
}

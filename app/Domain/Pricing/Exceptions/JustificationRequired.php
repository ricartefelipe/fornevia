<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Operação que exige justificativa registrada na auditoria.
 */
final class JustificationRequired extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'justification_required';
    }
}

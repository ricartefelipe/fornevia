<?php

declare(strict_types=1);

namespace App\Domain\Platform\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Plano inexistente ou indisponível para contratação.
 */
final class PlanUnavailable extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'plan_unavailable';
    }
}

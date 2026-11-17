<?php

declare(strict_types=1);

namespace App\Domain\Platform\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Limite do plano atingido.
 */
final class PlanLimitReached extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'plan_limit_reached';
    }
}

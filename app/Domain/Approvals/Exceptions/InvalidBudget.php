<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Dados inválidos no cadastro de orçamento (mês, valor ou centro de custo).
 */
final class InvalidBudget extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_budget';
    }
}

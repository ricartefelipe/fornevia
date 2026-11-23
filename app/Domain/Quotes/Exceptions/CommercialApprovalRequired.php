<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * A versão viola a política comercial e precisa de justificativa e aprovação (RN-041).
 */
final class CommercialApprovalRequired extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'commercial_approval_required';
    }
}

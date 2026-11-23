<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Quem decide não pode aprovar esta versão (permissão, limite de desconto ou autoria).
 */
final class CommercialApprovalNotAllowed extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'commercial_approval_not_allowed';
    }
}

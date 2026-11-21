<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

final class InvalidApprovalRule extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_approval_rule';
    }
}

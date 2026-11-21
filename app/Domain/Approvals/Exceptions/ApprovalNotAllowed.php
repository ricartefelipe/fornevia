<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

final class ApprovalNotAllowed extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'approval_not_allowed';
    }
}

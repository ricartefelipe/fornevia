<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * O convite foi enviado para outro e-mail.
 */
final class InvitationEmailMismatch extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invitation_email_mismatch';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Platform\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * A assinatura do Fornevia não permite esta operação.
 */
final class SubscriptionInactive extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'subscription_inactive';
    }
}

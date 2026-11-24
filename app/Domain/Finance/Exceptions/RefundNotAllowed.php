<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * O reembolso passa do valor elegível ou o pagamento não permite reembolso.
 */
final class RefundNotAllowed extends ConflictingState
{
    public function errorCode(): string
    {
        return 'refund_not_allowed';
    }
}

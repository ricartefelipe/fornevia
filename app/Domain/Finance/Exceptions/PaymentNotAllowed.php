<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * O pagamento não está em situação que permita a operação.
 */
final class PaymentNotAllowed extends ConflictingState
{
    public function errorCode(): string
    {
        return 'payment_not_allowed';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Buyers\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * A unidade informada pertence a outra empresa compradora.
 */
final class BuyerUnitMismatch extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'buyer_unit_mismatch';
    }
}

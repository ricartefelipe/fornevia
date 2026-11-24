<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Regra ou condição de frete inválida.
 */
final class InvalidFreightRule extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_freight_rule';
    }
}

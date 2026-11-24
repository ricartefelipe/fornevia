<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Dados de remessa inválidos.
 */
final class InvalidShipment extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_shipment';
    }
}

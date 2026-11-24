<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * A remessa não está em situação que permita a operação.
 */
final class ShipmentNotAllowed extends ConflictingState
{
    public function errorCode(): string
    {
        return 'shipment_not_allowed';
    }
}

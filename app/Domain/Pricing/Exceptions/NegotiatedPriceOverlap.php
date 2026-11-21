<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * Já existe condição negociada ativa com vigência sobreposta para a mesma empresa e SKU.
 */
final class NegotiatedPriceOverlap extends ConflictingState
{
    public function errorCode(): string
    {
        return 'negotiated_price_overlap';
    }
}

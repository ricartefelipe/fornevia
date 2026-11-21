<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * A condição negociada não está mais ativa.
 */
final class NegotiatedPriceNotActive extends ConflictingState
{
    public function errorCode(): string
    {
        return 'negotiated_price_not_active';
    }
}

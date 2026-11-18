<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * Mudança de situação não permitida a partir da situação atual da tabela.
 */
final class InvalidPriceListTransition extends ConflictingState
{
    public function errorCode(): string
    {
        return 'invalid_price_list_transition';
    }
}

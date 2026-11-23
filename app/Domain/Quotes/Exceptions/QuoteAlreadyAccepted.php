<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * A cotação já foi aceita e gerou um pedido (RN-042).
 */
final class QuoteAlreadyAccepted extends ConflictingState
{
    public function errorCode(): string
    {
        return 'quote_already_accepted';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * A validade da proposta terminou.
 */
final class QuoteExpired extends ConflictingState
{
    public function errorCode(): string
    {
        return 'quote_expired';
    }
}

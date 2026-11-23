<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * A cotação não está em situação que permita a operação.
 */
final class QuoteNotOpen extends ConflictingState
{
    public function errorCode(): string
    {
        return 'quote_not_open';
    }
}

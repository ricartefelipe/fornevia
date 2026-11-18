<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * Os valores do pedido mudaram desde a última exibição.
 */
final class PricesChanged extends ConflictingState
{
    public function errorCode(): string
    {
        return 'prices_changed';
    }
}

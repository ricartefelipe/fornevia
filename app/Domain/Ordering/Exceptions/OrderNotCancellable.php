<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * O pedido ou item não pode ser cancelado na situação atual.
 */
final class OrderNotCancellable extends ConflictingState
{
    public function errorCode(): string
    {
        return 'order_not_cancellable';
    }
}

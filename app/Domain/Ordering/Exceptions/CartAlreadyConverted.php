<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * Este carrinho já gerou um pedido.
 */
final class CartAlreadyConverted extends ConflictingState
{
    public function errorCode(): string
    {
        return 'cart_already_converted';
    }
}

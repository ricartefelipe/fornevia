<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * O carrinho tem itens que impedem a confirmação.
 */
final class CartNotReady extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'cart_not_ready';
    }
}

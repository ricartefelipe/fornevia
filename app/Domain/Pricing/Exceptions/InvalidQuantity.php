<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Quantidade informada para cálculo de preço precisa ser maior que zero.
 */
final class InvalidQuantity extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_quantity';
    }
}

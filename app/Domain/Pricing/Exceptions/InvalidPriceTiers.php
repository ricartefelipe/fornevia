<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Faixas por volume inválidas: mínimos positivos e estritamente crescentes, preços positivos.
 */
final class InvalidPriceTiers extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_price_tiers';
    }
}

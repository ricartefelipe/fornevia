<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Escopo e alvo incoerentes: grupo ou empresa ausente, de outro escopo ou inexistente no tenant.
 */
final class InvalidPriceListTarget extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_price_list_target';
    }
}

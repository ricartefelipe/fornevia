<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Já existe tabela de preço com o mesmo código no tenant.
 */
final class PriceListCodeTaken extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'price_list_code_taken';
    }
}

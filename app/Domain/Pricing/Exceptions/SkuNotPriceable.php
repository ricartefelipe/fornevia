<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * SKU arquivado não recebe novos preços.
 */
final class SkuNotPriceable extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'sku_not_priceable';
    }
}

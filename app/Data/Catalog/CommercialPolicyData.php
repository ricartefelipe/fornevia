<?php

declare(strict_types=1);

namespace App\Data\Catalog;

use App\Domain\Shared\Money;
use App\Domain\Shared\Percentage;

/**
 * Política comercial interna do SKU. Valores nulos significam "sem mínimo".
 */
final readonly class CommercialPolicyData
{
    public function __construct(
        public ?Money $minUnitPrice = null,
        public ?Percentage $minMargin = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->minUnitPrice === null && $this->minMargin === null;
    }
}

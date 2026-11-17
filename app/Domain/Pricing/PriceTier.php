<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;

/**
 * Faixa por volume: a partir de minQuantity, o preço unitário vale para
 * todas as unidades da linha (ADR-010).
 */
final readonly class PriceTier
{
    public function __construct(
        public Quantity $minQuantity,
        public Money $unitPrice,
    ) {}
}

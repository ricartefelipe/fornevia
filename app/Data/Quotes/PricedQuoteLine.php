<?php

declare(strict_types=1);

namespace App\Data\Quotes;

use App\Domain\Ordering\LineAmounts;
use App\Domain\Pricing\ResolvedPrice;
use App\Domain\Shared\Quantity;
use App\Models\Catalog\Sku;

/**
 * Linha da proposta precificada no servidor: SKU, quantidades, preço de
 * referência resolvido e valores calculados (LineAmounts).
 */
final readonly class PricedQuoteLine
{
    public function __construct(
        public int $lineNumber,
        public Sku $sku,
        public Quantity $baseQuantity,
        public ResolvedPrice $price,
        public LineAmounts $amounts,
    ) {}
}

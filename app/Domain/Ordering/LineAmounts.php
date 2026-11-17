<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

use App\Domain\Shared\Money;
use App\Domain\Shared\Percentage;
use App\Domain\Shared\Quantity;

/**
 * Valores de uma linha de documento comercial (ADR-004):
 * - preço líquido unitário = preço unitário com desconto, HALF_UP ao centavo;
 * - total = preço líquido unitário × quantidade, HALF_UP ao centavo;
 * - desconto da linha = bruto − total.
 */
final readonly class LineAmounts
{
    private function __construct(
        public Quantity $quantity,
        public Money $unitPrice,
        public Percentage $discount,
        public Money $unitNetPrice,
        public Money $gross,
        public Money $total,
    ) {}

    public static function calculate(Money $unitPrice, Percentage $discount, Quantity $quantity): self
    {
        $unitNet = $unitPrice->discountedBy($discount);

        return new self(
            $quantity,
            $unitPrice,
            $discount,
            $unitNet,
            $unitPrice->times($quantity),
            $unitNet->times($quantity),
        );
    }

    public function discountAmount(): Money
    {
        return $this->gross->minus($this->total);
    }
}

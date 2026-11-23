<?php

declare(strict_types=1);

namespace App\Domain\Quotes;

use App\Domain\Pricing\DiscountEvaluation;
use App\Domain\Pricing\DiscountViolation;
use App\Domain\Shared\Money;
use App\Domain\Shared\Percentage;

/**
 * Avaliação comercial de uma linha da proposta (RN-032, RN-033): desconto,
 * líquido, custo e margem, mínimos e violações. Dado interno (ADR-020).
 */
final readonly class CommercialReviewLine
{
    public function __construct(
        public int $lineNumber,
        public string $skuId,
        public DiscountEvaluation $evaluation,
        public ?Money $unitCost,
        public ?Money $minUnitPrice,
        public Percentage $minMargin,
    ) {}

    /**
     * @return array{line_number: int, sku_id: string, discount_bps: int, unit_net_cents: int, unit_cost_cents: int|null, margin_bps: int|null, min_unit_price_cents: int|null, min_margin_bps: int, violations: list<string>}
     */
    public function toArray(): array
    {
        return [
            'line_number' => $this->lineNumber,
            'sku_id' => $this->skuId,
            'discount_bps' => $this->evaluation->discount->basisPoints,
            'unit_net_cents' => $this->evaluation->netUnitPrice->cents,
            'unit_cost_cents' => $this->unitCost?->cents,
            'margin_bps' => $this->evaluation->margin?->basisPoints,
            'min_unit_price_cents' => $this->minUnitPrice?->cents,
            'min_margin_bps' => $this->minMargin->basisPoints,
            'violations' => array_map(static fn (DiscountViolation $v): string => $v->value, $this->evaluation->violations),
        ];
    }
}

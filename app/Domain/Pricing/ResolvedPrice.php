<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Shared\Money;

/**
 * Preço resolvido para um SKU, quantidade e data, com a origem registrada
 * para o snapshot do documento comercial.
 */
final readonly class ResolvedPrice
{
    public function __construct(
        public Money $unitPrice,
        public PriceSource $source,
        public string $referenceId,
        public ?PriceTier $tier,
        public ?Money $minUnitPrice,
        public ?int $version,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'source' => $this->source->value,
            'reference_id' => $this->referenceId,
            'unit_price_cents' => $this->unitPrice->cents,
            'tier_min_quantity' => $this->tier?->minQuantity->toDatabase(),
            'min_unit_price_cents' => $this->minUnitPrice?->cents,
            'version' => $this->version,
        ];
    }
}

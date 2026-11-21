<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Domain\Shared\Quantity;

/**
 * Saldo de um SKU em um depósito, na unidade base do produto: físico,
 * reservado e disponível (físico menos reservado).
 */
final readonly class StockBalance
{
    public function __construct(
        public string $warehouseId,
        public string $skuId,
        public Quantity $onHand,
        public Quantity $reserved,
    ) {}

    public static function empty(string $warehouseId, string $skuId): self
    {
        return new self($warehouseId, $skuId, Quantity::zero(), Quantity::zero());
    }

    public function available(): Quantity
    {
        $available = $this->onHand->minus($this->reserved);

        return $available->isNegative() ? Quantity::zero() : $available;
    }
}

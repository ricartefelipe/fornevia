<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Quantity;

final readonly class WarehouseStock
{
    public function __construct(
        public string $warehouseId,
        public int $priority,
        public Quantity $onHand,
        public Quantity $reserved,
    ) {}

    public function available(): Quantity
    {
        $available = $this->onHand->minus($this->reserved);

        return $available->isNegative() ? Quantity::zero() : $available;
    }
}

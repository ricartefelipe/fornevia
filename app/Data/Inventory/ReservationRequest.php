<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Domain\Shared\Quantity;

/**
 * Pedido de reserva para um item: quantidade na unidade base do SKU.
 */
final readonly class ReservationRequest
{
    public function __construct(
        public string $orderItemId,
        public string $skuId,
        public string $skuCode,
        public Quantity $baseQuantity,
        public bool $allowBackorder,
    ) {}
}

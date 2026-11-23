<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Domain\Shared\Quantity;
use App\Enums\Inventory\ReservationHolder;
use Carbon\CarbonImmutable;

/**
 * Pedido de reserva para um item: quantidade na unidade base do SKU, o
 * titular (item de pedido ou de cotação) e, para cotações, o prazo da
 * reserva.
 */
final readonly class ReservationRequest
{
    public function __construct(
        public string $holderId,
        public string $skuId,
        public string $skuCode,
        public Quantity $baseQuantity,
        public bool $allowBackorder,
        public ReservationHolder $holder = ReservationHolder::OrderItem,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}

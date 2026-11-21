<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Domain\Shared\Quantity;

final readonly class ReservationOutcome
{
    /**
     * @param  list<string>  $reservationIds
     */
    public function __construct(
        public string $orderItemId,
        public Quantity $reserved,
        public Quantity $backordered,
        public array $reservationIds,
    ) {}
}

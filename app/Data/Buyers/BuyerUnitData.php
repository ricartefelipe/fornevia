<?php

declare(strict_types=1);

namespace App\Data\Buyers;

final readonly class BuyerUnitData
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $isActive = true,
    ) {}
}

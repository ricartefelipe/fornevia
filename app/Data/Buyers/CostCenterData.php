<?php

declare(strict_types=1);

namespace App\Data\Buyers;

final readonly class CostCenterData
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $buyerUnitId = null,
        public bool $isActive = true,
    ) {}
}

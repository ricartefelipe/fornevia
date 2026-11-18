<?php

declare(strict_types=1);

namespace App\Data\Catalog;

final readonly class UnitOfMeasureData
{
    public function __construct(
        public string $code,
        public string $name,
        public int $decimals,
    ) {}
}

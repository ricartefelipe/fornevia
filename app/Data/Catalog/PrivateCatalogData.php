<?php

declare(strict_types=1);

namespace App\Data\Catalog;

final readonly class PrivateCatalogData
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description = null,
        public bool $isActive = true,
    ) {}
}

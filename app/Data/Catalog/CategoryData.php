<?php

declare(strict_types=1);

namespace App\Data\Catalog;

final readonly class CategoryData
{
    public function __construct(
        public string $name,
        public ?string $parentId = null,
        public int $sortOrder = 0,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Catalog;

use App\Enums\Catalog\ProductVisibility;

/**
 * Dados cadastrais do produto. A situação é alterada apenas por
 * ChangeProductStatus.
 */
final readonly class ProductData
{
    public function __construct(
        public string $name,
        public string $baseUnitId,
        public ?string $slug = null,
        public ?string $brand = null,
        public ?string $description = null,
        public ?string $ncm = null,
        public ?string $categoryId = null,
        public ProductVisibility $visibility = ProductVisibility::Public,
        public bool $allowBackorder = false,
        public ?int $backorderLeadDays = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Catalog;

use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Catalog\SkuStatus;
use Brick\Math\BigDecimal;

/**
 * Dados do SKU. Quantidades na unidade de venda; o fator de conversão
 * indica quantas unidades base cabem em uma unidade de venda.
 *
 * $cost e $commercialPolicy nulos significam "não alterar" e só podem ser
 * informados por quem tem catalog.view_costs.
 */
final readonly class SkuData
{
    /**
     * @param  array<string, string>  $attributes
     */
    public function __construct(
        public string $code,
        public string $salesUnitId,
        public BigDecimal $conversionFactor,
        public Quantity $minOrderQuantity,
        public Quantity $orderMultiple,
        public ?string $gtin = null,
        public ?string $variantLabel = null,
        public array $attributes = [],
        public ?string $packageLabel = null,
        public ?int $weightGrams = null,
        public SkuStatus $status = SkuStatus::Active,
        public ?Money $cost = null,
        public ?CommercialPolicyData $commercialPolicy = null,
    ) {}

    public function touchesRestrictedData(): bool
    {
        return $this->cost !== null || $this->commercialPolicy !== null;
    }
}

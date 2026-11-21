<?php

declare(strict_types=1);

namespace App\Data\Ordering;

use App\Domain\Catalog\PurchaseRuleViolation;
use App\Domain\Ordering\LineAmounts;
use App\Domain\Pricing\ResolvedPrice;
use App\Domain\Shared\Quantity;
use Brick\Math\BigDecimal;

/**
 * Linha do carrinho precificada no servidor. problems vazio = linha apta.
 */
final readonly class CartLineQuote
{
    /**
     * @param  list<PurchaseRuleViolation>  $violations
     * @param  list<string>  $problems
     */
    public function __construct(
        public string $cartItemId,
        public string $skuId,
        public string $skuCode,
        public string $productName,
        public ?string $variantLabel,
        public string $unitCode,
        public ?string $packageLabel,
        public ?BigDecimal $conversionFactor,
        public Quantity $quantity,
        public ?Quantity $baseQuantity,
        public array $violations,
        public ?ResolvedPrice $price,
        public ?LineAmounts $amounts,
        public string $availability,
        public bool $allowBackorder,
        public array $problems,
    ) {}

    public function isReady(): bool
    {
        return $this->problems === [];
    }
}

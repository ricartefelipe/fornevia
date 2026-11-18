<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Shared\Money;
use App\Domain\Shared\Percentage;

final readonly class DiscountEvaluation
{
    /**
     * @param  list<DiscountViolation>  $violations
     */
    public function __construct(
        public Money $netUnitPrice,
        public Percentage $discount,
        public ?Percentage $margin,
        public array $violations,
    ) {}

    public function requiresCommercialApproval(): bool
    {
        return $this->violations !== [];
    }
}

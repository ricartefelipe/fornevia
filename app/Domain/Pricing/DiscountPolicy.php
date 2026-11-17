<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Shared\Money;
use App\Domain\Shared\Percentage;

/**
 * Avalia um desconto sobre o preço resolvido.
 *
 * Margem = (preço líquido − custo) ÷ preço líquido, em pontos-base.
 * Violação não impede a proposta: exige aprovação comercial de perfil cujo
 * limite cubra o desconto (RN-032, RN-033).
 */
final class DiscountPolicy
{
    public function evaluate(
        Money $unitPrice,
        Percentage $discount,
        Percentage $roleLimit,
        ?Money $minUnitPrice,
        ?Money $unitCost,
        Percentage $minimumMargin,
    ): DiscountEvaluation {
        $net = $unitPrice->discountedBy($discount);
        $margin = $unitCost !== null ? $net->ratioOf($net->minus($unitCost)) : null;
        $violations = [];

        if ($discount->greaterThan($roleLimit)) {
            $violations[] = DiscountViolation::ExceedsRoleLimit;
        }

        if ($minUnitPrice !== null && $net->lessThan($minUnitPrice)) {
            $violations[] = DiscountViolation::BelowMinimumPrice;
        }

        if ($unitCost !== null && $net->lessThan($unitCost)) {
            $violations[] = DiscountViolation::BelowCost;
        }

        if ($margin !== null && $margin->lessThan($minimumMargin)) {
            $violations[] = DiscountViolation::BelowMinimumMargin;
        }

        return new DiscountEvaluation($net, $discount, $margin, $violations);
    }
}

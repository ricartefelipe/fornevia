<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

enum DiscountViolation: string
{
    case ExceedsRoleLimit = 'exceeds_role_limit';
    case BelowMinimumPrice = 'below_minimum_price';
    case BelowMinimumMargin = 'below_minimum_margin';
    case BelowCost = 'below_cost';

    public function label(): string
    {
        return match ($this) {
            self::ExceedsRoleLimit => 'Desconto acima do limite do perfil',
            self::BelowMinimumPrice => 'Preço abaixo do mínimo da tabela',
            self::BelowMinimumMargin => 'Margem abaixo da mínima',
            self::BelowCost => 'Preço abaixo do custo',
        };
    }
}

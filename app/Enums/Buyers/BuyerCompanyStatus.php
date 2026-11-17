<?php

declare(strict_types=1);

namespace App\Enums\Buyers;

enum BuyerCompanyStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
    case Inactive = 'inactive';

    public function canPurchase(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativa',
            self::Blocked => 'Bloqueada',
            self::Inactive => 'Inativa',
        };
    }
}

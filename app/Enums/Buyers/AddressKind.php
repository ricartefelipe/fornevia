<?php

declare(strict_types=1);

namespace App\Enums\Buyers;

enum AddressKind: string
{
    case Billing = 'billing';
    case Shipping = 'shipping';

    public function label(): string
    {
        return match ($this) {
            self::Billing => 'Cobrança',
            self::Shipping => 'Entrega',
        };
    }
}

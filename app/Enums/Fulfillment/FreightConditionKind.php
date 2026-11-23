<?php

declare(strict_types=1);

namespace App\Enums\Fulfillment;

enum FreightConditionKind: string
{
    case Free = 'free';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Frete isento',
            self::Fixed => 'Valor fixo por pedido',
        };
    }
}

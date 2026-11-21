<?php

declare(strict_types=1);

namespace App\Enums\Pricing;

enum PriceListScope: string
{
    case Default = 'default';
    case Group = 'group';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Default => 'Padrão',
            self::Group => 'Grupo de clientes',
            self::Customer => 'Empresa compradora',
        };
    }
}

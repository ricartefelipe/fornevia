<?php

declare(strict_types=1);

namespace App\Enums\Catalog;

enum ProductVisibility: string
{
    case Public = 'public';
    case Restricted = 'restricted';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Público',
            self::Restricted => 'Restrito a catálogos privados',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums\Pricing;

enum PriceListStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Active => 'Ativa',
            self::Archived => 'Arquivada',
        };
    }
}

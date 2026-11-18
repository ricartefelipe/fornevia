<?php

declare(strict_types=1);

namespace App\Enums\Ordering;

enum CartStatus: string
{
    case Open = 'open';
    case Converted = 'converted';
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberto',
            self::Converted => 'Convertido em pedido',
            self::Abandoned => 'Abandonado',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums\Inventory;

enum ReservationStatus: string
{
    case Active = 'active';
    case Consumed = 'consumed';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativa',
            self::Consumed => 'Consumida',
            self::Released => 'Liberada',
        };
    }
}

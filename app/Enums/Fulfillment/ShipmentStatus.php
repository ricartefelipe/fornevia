<?php

declare(strict_types=1);

namespace App\Enums\Fulfillment;

enum ShipmentStatus: string
{
    case Preparing = 'preparing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Preparing => 'Em separação',
            self::Shipped => 'Expedida',
            self::Delivered => 'Entregue',
            self::Cancelled => 'Cancelada',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums\Fulfillment;

enum ShipmentEventKind: string
{
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Exception = 'exception';

    public function label(): string
    {
        return match ($this) {
            self::InTransit => 'Em trânsito',
            self::OutForDelivery => 'Saiu para entrega',
            self::Delivered => 'Entregue',
            self::Exception => 'Ocorrência',
        };
    }
}

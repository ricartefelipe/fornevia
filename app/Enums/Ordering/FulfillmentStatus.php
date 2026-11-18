<?php

declare(strict_types=1);

namespace App\Enums\Ordering;

enum FulfillmentStatus: string
{
    case Pending = 'pending';
    case PartiallyShipped = 'partially_shipped';
    case Shipped = 'shipped';
    case PartiallyDelivered = 'partially_delivered';
    case Delivered = 'delivered';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando expedição',
            self::PartiallyShipped => 'Expedido parcialmente',
            self::Shipped => 'Expedido',
            self::PartiallyDelivered => 'Entregue parcialmente',
            self::Delivered => 'Entregue',
        };
    }
}

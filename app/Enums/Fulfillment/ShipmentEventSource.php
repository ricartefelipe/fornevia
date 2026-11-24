<?php

declare(strict_types=1);

namespace App\Enums\Fulfillment;

enum ShipmentEventSource: string
{
    case Staff = 'staff';
    case Carrier = 'carrier';
}

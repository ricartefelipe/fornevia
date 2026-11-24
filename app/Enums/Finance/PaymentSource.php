<?php

declare(strict_types=1);

namespace App\Enums\Finance;

enum PaymentSource: string
{
    case Manual = 'manual';
    case Gateway = 'gateway';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Registro manual',
            self::Gateway => 'Gateway',
        };
    }
}

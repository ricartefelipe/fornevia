<?php

declare(strict_types=1);

namespace App\Enums\Finance;

enum InstallmentStatus: string
{
    case Open = 'open';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Em aberto',
            self::Paid => 'Quitada',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums\Credit;

enum CreditAccountStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativa',
            self::Blocked => 'Bloqueada',
        };
    }
}

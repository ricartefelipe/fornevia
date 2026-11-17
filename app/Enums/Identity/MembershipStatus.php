<?php

declare(strict_types=1);

namespace App\Enums\Identity;

enum MembershipStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativo',
            self::Revoked => 'Revogado',
        };
    }
}

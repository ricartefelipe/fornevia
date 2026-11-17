<?php

declare(strict_types=1);

namespace App\Enums\Identity;

use App\Support\Tenancy\ContextKind;

enum MembershipKind: string
{
    case Staff = 'staff';
    case Buyer = 'buyer';

    public function contextKind(): ContextKind
    {
        return match ($this) {
            self::Staff => ContextKind::Staff,
            self::Buyer => ContextKind::Buyer,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Equipe do distribuidor',
            self::Buyer => 'Empresa compradora',
        };
    }
}

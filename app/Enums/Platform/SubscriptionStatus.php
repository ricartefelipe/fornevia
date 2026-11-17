<?php

declare(strict_types=1);

namespace App\Enums\Platform;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Em avaliação',
            self::Active => 'Ativa',
            self::PastDue => 'Pagamento pendente',
            self::Cancelled => 'Cancelada',
        };
    }
}

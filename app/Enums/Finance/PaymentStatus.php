<?php

declare(strict_types=1);

namespace App\Enums\Finance;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando pagamento',
            self::Confirmed => 'Confirmado',
            self::Failed => 'Falhou',
            self::Cancelled => 'Cancelado',
        };
    }
}

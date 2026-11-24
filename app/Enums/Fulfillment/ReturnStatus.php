<?php

declare(strict_types=1);

namespace App\Enums\Fulfillment;

enum ReturnStatus: string
{
    case Requested = 'requested';
    case Authorized = 'authorized';
    case Rejected = 'rejected';
    case Received = 'received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Aguardando análise',
            self::Authorized => 'Autorizada, aguardando envio',
            self::Rejected => 'Recusada',
            self::Received => 'Recebida, em inspeção',
            self::Closed => 'Concluída',
            self::Cancelled => 'Cancelada',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Authorized, self::Received], true);
    }
}

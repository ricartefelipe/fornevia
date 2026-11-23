<?php

declare(strict_types=1);

namespace App\Enums\Quotes;

/**
 * Situação da cotação (ADR-025). Aceita, recusada e cancelada são terminais.
 */
enum QuoteStatus: string
{
    case Requested = 'requested';
    case Proposed = 'proposed';
    case Expired = 'expired';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Aguardando proposta',
            self::Proposed => 'Proposta enviada',
            self::Expired => 'Vencida',
            self::Accepted => 'Aceita',
            self::Rejected => 'Recusada',
            self::Cancelled => 'Cancelada',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Accepted, self::Rejected, self::Cancelled], true);
    }
}

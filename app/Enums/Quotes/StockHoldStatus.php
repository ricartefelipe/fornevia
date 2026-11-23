<?php

declare(strict_types=1);

namespace App\Enums\Quotes;

/**
 * Situação da reserva de estoque de uma versão (RN-043).
 */
enum StockHoldStatus: string
{
    case None = 'none';
    case Active = 'active';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sem reserva',
            self::Active => 'Reserva ativa',
            self::Ended => 'Reserva encerrada',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums\Quotes;

/**
 * Tipo de solicitação do comprador.
 */
enum QuoteRequestKind: string
{
    case Initial = 'initial';
    case Counteroffer = 'counteroffer';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Solicitação',
            self::Counteroffer => 'Pedido de revisão',
        };
    }
}

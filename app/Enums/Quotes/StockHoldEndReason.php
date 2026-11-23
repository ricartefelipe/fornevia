<?php

declare(strict_types=1);

namespace App\Enums\Quotes;

/**
 * Motivo do fim da reserva de estoque de uma versão.
 */
enum StockHoldEndReason: string
{
    case Expired = 'expired';
    case Superseded = 'superseded';
    case Countered = 'countered';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Cancelled = 'cancelled';
    case Transferred = 'transferred';
    case OrderRejected = 'order_rejected';
    case OrderCancelled = 'order_cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Expired => 'Prazo encerrado',
            self::Superseded => 'Nova versão enviada',
            self::Countered => 'Contraproposta do comprador',
            self::Rejected => 'Proposta recusada',
            self::Withdrawn => 'Proposta retirada',
            self::Cancelled => 'Cotação encerrada',
            self::Transferred => 'Transferida ao pedido',
            self::OrderRejected => 'Pedido rejeitado na aprovação interna',
            self::OrderCancelled => 'Pedido cancelado antes da confirmação',
        };
    }
}

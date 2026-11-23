<?php

declare(strict_types=1);

namespace App\Enums\Inventory;

/**
 * Titular de uma reserva de estoque: item de pedido ou item de versão de
 * cotação (reserva com prazo, RN-043). Cada reserva tem exatamente um.
 */
enum ReservationHolder: string
{
    case OrderItem = 'order_item';
    case QuoteItem = 'quote_item';

    /**
     * Coluna de stock_reservations que guarda o titular.
     */
    public function column(): string
    {
        return match ($this) {
            self::OrderItem => 'order_item_id',
            self::QuoteItem => 'quote_item_id',
        };
    }
}

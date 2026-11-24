<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment;

use App\Domain\Shared\Quantity;
use App\Enums\Ordering\FulfillmentStatus;

/**
 * Situação de atendimento do pedido a partir das quantidades por item
 * (RN-090), na unidade de venda. Itens totalmente cancelados não contam.
 */
final class FulfillmentProgress
{
    /**
     * @param  iterable<array{quantity: Quantity, cancelled: Quantity, shipped: Quantity, delivered: Quantity}>  $items
     */
    public static function of(iterable $items): FulfillmentStatus
    {
        $effective = Quantity::zero();
        $shipped = Quantity::zero();
        $delivered = Quantity::zero();

        foreach ($items as $item) {
            $effective = $effective->plus($item['quantity']->minus($item['cancelled']));
            $shipped = $shipped->plus($item['shipped']);
            $delivered = $delivered->plus($item['delivered']);
        }

        return match (true) {
            ! $effective->isPositive() || ! $shipped->isPositive() => FulfillmentStatus::Pending,
            $delivered->greaterThanOrEqual($effective) => FulfillmentStatus::Delivered,
            $delivered->isPositive() => FulfillmentStatus::PartiallyDelivered,
            $shipped->greaterThanOrEqual($effective) => FulfillmentStatus::Shipped,
            default => FulfillmentStatus::PartiallyShipped,
        };
    }
}

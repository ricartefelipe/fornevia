<?php

declare(strict_types=1);

namespace App\Services\Fulfillment;

use App\Domain\Fulfillment\FulfillmentProgress;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;

/**
 * Atualiza a situação de atendimento do pedido a partir dos itens
 * (FulfillmentProgress), depois de expedição, entrega ou cancelamento.
 */
final class OrderFulfillment
{
    public function refresh(Order $order): void
    {
        $items = OrderItem::query()->where('order_id', $order->id)->get();
        $status = FulfillmentProgress::of($items->map(static fn (OrderItem $item): array => [
            'quantity' => $item->quantity,
            'cancelled' => $item->quantity_cancelled,
            'shipped' => $item->quantity_shipped,
            'delivered' => $item->quantity_delivered,
        ])->all());

        if ($order->fulfillment_status !== $status) {
            $order->forceFill(['fulfillment_status' => $status])->save();
        }
    }
}

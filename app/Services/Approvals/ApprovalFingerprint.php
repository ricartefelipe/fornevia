<?php

declare(strict_types=1);

namespace App\Services\Approvals;

use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;

/**
 * Impressão do que foi aprovado (RN-054): itens, quantidades, preços,
 * condição de pagamento, endereço de entrega, unidade e centro de custo.
 * Mudança em qualquer um deles invalida as aprovações registradas.
 */
final class ApprovalFingerprint
{
    public static function of(Order $order): string
    {
        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('line_number')->get()
            ->map(static fn (OrderItem $item): array => [
                $item->sku_id,
                $item->quantity->toDatabase(),
                $item->unit_net_price_cents->cents,
            ])->all();

        return hash('sha256', json_encode([
            'items' => $items,
            'total' => $order->total_cents->cents,
            'payment_term' => $order->payment_term['code'] ?? null,
            'shipping' => $order->shipping_address['id'] ?? null,
            'unit' => $order->buyer_unit_id,
            'cost_center' => $order->cost_center_id,
        ], JSON_THROW_ON_ERROR));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Fulfillment;

use App\Models\Fulfillment\ReturnItem;
use App\Models\Fulfillment\ReturnRequest;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use Illuminate\Database\Eloquent\Collection;

/**
 * Locks da devolução na ordem do ADR-006: pedido → devolução → itens do
 * pedido. Também libera a parte em devolução aberta dos itens.
 */
final class ReturnLocks
{
    /**
     * @return array{Order, ReturnRequest}
     */
    public function lock(ReturnRequest $request): array
    {
        $order = Order::query()->where('buyer_company_id', $request->buyer_company_id)->lockForUpdate()->findOrFail($request->order_id);
        $locked = ReturnRequest::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($request->id);

        return [$order, $locked];
    }

    /**
     * @return array{Collection<int, ReturnItem>, Collection<string, OrderItem>}
     */
    public function items(ReturnRequest $request): array
    {
        $lines = ReturnItem::query()->where('return_request_id', $request->id)->orderBy('order_item_id')->get();
        $items = OrderItem::query()->whereKey($lines->pluck('order_item_id')->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return [$lines, $items];
    }

    /**
     * Encerra a parte aberta da devolução nos itens do pedido.
     *
     * @param  Collection<int, ReturnItem>  $lines
     * @param  Collection<string, OrderItem>  $items
     */
    public function releaseOpen(Collection $lines, Collection $items): void
    {
        foreach ($lines as $line) {
            $item = $items->get($line->order_item_id);
            $item?->forceFill(['quantity_return_open' => $item->quantity_return_open->minus($line->quantity)])->save();
        }
    }
}

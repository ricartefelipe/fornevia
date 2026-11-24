<div>
    <x-ui.page-header :title="'Separação do pedido '.$order->number" :description="$order->buyerCompany?->trade_name.' · '.$order->fulfillment_status->label()">
        <x-slot:actions>
            <a href="{{ route('orders.show', $order->id) }}" class="fv-btn-secondary">Ver o pedido</a>
            <a href="{{ route('fulfillment.index') }}" class="fv-btn-secondary">Voltar</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)<x-ui.alert type="success">{{ $message }}</x-ui.alert>@endif
        @if ($failure)<x-ui.alert type="error">{{ $failure }}</x-ui.alert>@endif
    </div>

    <p class="mb-4 text-sm text-slate-700">Entrega: {{ $order->shipping_address['street'] ?? '' }}, {{ $order->shipping_address['number'] ?? '' }} · {{ $order->shipping_address['city'] ?? '' }}/{{ $order->shipping_address['state'] ?? '' }}</p>

    <form wire:submit="createShipment" class="fv-card mb-6 p-5" novalidate>
        <h2 class="text-lg font-semibold">Abrir remessa</h2>
        @if ($canManage)
            <div class="mt-3 max-w-sm">
                <x-ui.select label="Depósito de saída" name="warehouseId" :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name])->all()" wire:model.live="warehouseId" required />
            </div>
        @endif
        <div class="mt-4 overflow-x-auto">
            <table class="fv-table">
                <caption class="sr-only">Itens do pedido e quantidades a separar</caption>
                <thead>
                    <tr>
                        <th scope="col">Item</th>
                        <th scope="col" class="text-right">Pedido</th>
                        <th scope="col" class="text-right">Cancelado</th>
                        <th scope="col" class="text-right">Expedido</th>
                        <th scope="col" class="text-right">Em separação</th>
                        <th scope="col" class="text-right">Falta separar</th>
                        <th scope="col" class="text-right">Reservado no depósito</th>
                        @if ($canManage)<th scope="col">Separar agora</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        @php($inWarehouse = $reserved->get($item->id.'|'.$warehouseId) ?? \App\Domain\Shared\Quantity::zero())
                        <tr wire:key="item-{{ $item->id }}">
                            <td><span class="font-medium">{{ $item->sku_code }}</span> <span class="block text-xs text-slate-600">{{ $item->product_name }}</span>
                                @if ($item->quantity_backordered->isPositive())<span class="block text-xs text-amber-700">Sob encomenda: {{ $item->backorderedInSalesUnit()->format() }} {{ $item->unit_code }}</span>@endif
                            </td>
                            <td class="text-right">{{ $item->quantity->format() }} {{ $item->unit_code }}</td>
                            <td class="text-right">{{ $item->quantity_cancelled->format() }}</td>
                            <td class="text-right">{{ $item->quantity_shipped->format() }}</td>
                            <td class="text-right">{{ $item->quantity_picking->format() }}</td>
                            <td class="text-right font-medium">{{ $item->unallocated()->format() }}</td>
                            <td class="text-right">{{ $inWarehouse->format() }}</td>
                            @if ($canManage)
                                <td class="w-36">
                                    @if ($item->unallocated()->isPositive())
                                        <x-ui.input :label="'Quantidade de '.$item->sku_code" name="quantities.{{ $item->id }}" inputmode="decimal" maxlength="21" wire:model="quantities.{{ $item->id }}" />
                                    @else
                                        <span class="text-xs text-slate-500">Nada a separar</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($canManage)
            <div class="mt-4 flex flex-wrap gap-3">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="createShipment">Abrir remessa</x-ui.button>
                @if ($hasBackorder)
                    <x-ui.button type="button" variant="secondary" wire:click="reserveBackorder" wire:loading.attr="disabled">Reservar o que está sob encomenda</x-ui.button>
                @endif
            </div>
        @endif
    </form>

    <section class="fv-card p-5" aria-labelledby="remessas-do-pedido">
        <h2 id="remessas-do-pedido" class="text-lg font-semibold">Remessas do pedido</h2>
        <ul class="mt-3 divide-y divide-slate-200 text-sm">
            @forelse ($shipments as $shipment)
                <li wire:key="remessa-{{ $shipment->id }}" class="flex items-center justify-between py-2">
                    <span><span class="font-medium">{{ $shipment->number }}</span> · {{ $shipment->status->label() }} · {{ $shipment->warehouse->name }}</span>
                    <a href="{{ route('fulfillment.shipments.show', $shipment->id) }}" class="text-brand-700 underline">Abrir</a>
                </li>
            @empty
                <li class="py-2 text-slate-600">Nenhuma remessa ainda.</li>
            @endforelse
        </ul>
    </section>
</div>

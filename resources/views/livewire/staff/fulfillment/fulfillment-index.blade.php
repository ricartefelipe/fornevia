<div>
    <x-ui.page-header title="Expedição" description="Separação, conferência, expedição e entrega dos pedidos confirmados." />

    <nav aria-label="Filas da expedição" class="mb-4 flex flex-wrap gap-2">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['fv-btn-primary' => $tab === $key, 'fv-btn-secondary' => $tab !== $key]) aria-current="{{ $tab === $key ? 'page' : 'false' }}">{{ $label }}</button>
        @endforeach
    </nav>

    <section class="fv-card overflow-x-auto" aria-label="{{ $tabs[$tab] }}">
        @if ($orders !== null)
            @if ($orders->isEmpty())
                <div class="p-6"><x-ui.empty-state title="Nada a separar" description="Pedidos confirmados com itens a separar aparecem aqui." /></div>
            @else
                <table class="fv-table">
                    <caption class="sr-only">Pedidos com itens a separar, dos confirmados há mais tempo</caption>
                    <thead><tr><th scope="col">Pedido</th><th scope="col">Empresa</th><th scope="col">Confirmado em</th><th scope="col">Atendimento</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                    <tbody>
                        @foreach ($orders as $order)
                            <tr wire:key="pedido-{{ $order->id }}">
                                <td class="font-medium">{{ $order->number }}</td>
                                <td>{{ $order->buyerCompany?->trade_name }}</td>
                                <td>{{ $order->confirmed_at?->setTimezone($timezone)->format('d/m/Y H:i') }}</td>
                                <td>{{ $order->fulfillment_status->label() }}</td>
                                <td class="text-right"><a href="{{ route('fulfillment.orders.show', $order->id) }}" class="fv-btn-secondary">Separar</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="p-4">{{ $orders->links() }}</div>
            @endif
        @else
            @if ($shipments->isEmpty())
                <div class="p-6"><x-ui.empty-state title="Nenhuma remessa" description="As remessas desta fila aparecem aqui." /></div>
            @else
                <table class="fv-table">
                    <caption class="sr-only">Remessas</caption>
                    <thead><tr><th scope="col">Remessa</th><th scope="col">Pedido</th><th scope="col">Empresa</th><th scope="col">Depósito</th><th scope="col">Transportadora</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                    <tbody>
                        @foreach ($shipments as $shipment)
                            <tr wire:key="remessa-{{ $shipment->id }}">
                                <td class="font-medium">{{ $shipment->number }}</td>
                                <td>{{ $shipment->order->number }}</td>
                                <td>{{ $shipment->order->buyerCompany?->trade_name }}</td>
                                <td>{{ $shipment->warehouse->name }}</td>
                                <td>{{ $shipment->carrier ?? '—' }}{{ $shipment->tracking_code ? ' · '.$shipment->tracking_code : '' }}</td>
                                <td class="text-right"><a href="{{ route('fulfillment.shipments.show', $shipment->id) }}" class="fv-btn-secondary">Abrir</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="p-4">{{ $shipments->links() }}</div>
            @endif
        @endif
    </section>
</div>

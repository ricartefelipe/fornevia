@php($at = static fn ($instant): string => $instant ? $instant->setTimezone($timezone)->format('d/m/Y H:i') : '—')
<div>
    <x-ui.page-header :title="'Remessa '.$shipment->number" :description="'Pedido '.$shipment->order->number.' · '.$shipment->order->buyerCompany?->trade_name.' · '.$shipment->status->label()">
        <x-slot:actions>
            <a href="{{ route('fulfillment.orders.show', $shipment->order_id) }}" class="fv-btn-secondary">Separação do pedido</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)<x-ui.alert type="success">{{ $message }}</x-ui.alert>@endif
        @if ($failure)<x-ui.alert type="error">{{ $failure }}</x-ui.alert>@endif
    </div>

    <dl class="fv-card mb-6 grid gap-3 p-5 text-sm sm:grid-cols-4">
        <div><dt class="text-slate-500">Depósito</dt><dd>{{ $shipment->warehouse->name }}</dd></div>
        <div><dt class="text-slate-500">Conferida em</dt><dd>{{ $at($shipment->checked_at) }}</dd></div>
        <div><dt class="text-slate-500">Expedida em</dt><dd>{{ $at($shipment->shipped_at) }}</dd></div>
        <div><dt class="text-slate-500">Entregue em</dt><dd>{{ $at($shipment->delivered_at) }}</dd></div>
        <div><dt class="text-slate-500">Transportadora</dt><dd>{{ $shipment->carrier ?? '—' }}</dd></div>
        <div><dt class="text-slate-500">Rastreamento</dt><dd>{{ $shipment->tracking_code ?? '—' }}</dd></div>
        @if ($shipment->cancel_reason)<div class="sm:col-span-2"><dt class="text-slate-500">Motivo do cancelamento</dt><dd>{{ $shipment->cancel_reason }}</dd></div>@endif
    </dl>

    @php($preparing = $shipment->status === \App\Enums\Fulfillment\ShipmentStatus::Preparing)
    <form wire:submit="check" class="fv-card mb-6 p-5" novalidate>
        <h2 class="text-lg font-semibold">Itens{{ $preparing && $canManage ? ' e conferência' : '' }}</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="fv-table">
                <caption class="sr-only">Itens da remessa</caption>
                <thead><tr><th scope="col">Item</th><th scope="col" class="text-right">Separado</th><th scope="col">Conferido</th></tr></thead>
                <tbody>
                    @foreach ($shipment->items as $item)
                        <tr wire:key="item-{{ $item->id }}">
                            <td><span class="font-medium">{{ $item->orderItem->sku_code }}</span> <span class="block text-xs text-slate-600">{{ $item->orderItem->product_name }}</span></td>
                            <td class="text-right">{{ $item->quantity->format() }} {{ $item->orderItem->unit_code }}</td>
                            <td class="w-40">
                                @if ($preparing && $canManage)
                                    <x-ui.input :label="'Conferido de '.$item->orderItem->sku_code" name="checked.{{ $item->id }}" inputmode="decimal" maxlength="21" wire:model="checked.{{ $item->id }}" />
                                @else
                                    {{ $item->quantity_checked?->format() ?? '—' }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($preparing && $canManage)
            <x-ui.button type="submit" class="mt-4" variant="secondary" wire:loading.attr="disabled" wire:target="check">Registrar conferência</x-ui.button>
        @endif
    </form>

    @if ($preparing && $canManage)
        <div class="mb-6 grid gap-6 lg:grid-cols-2">
            <form wire:submit="ship" class="fv-card p-5" novalidate>
                <h2 class="text-lg font-semibold">Expedir</h2>
                @if (! $shipment->checked_at)
                    <p class="mt-2 text-sm text-slate-700">Registre a conferência antes de expedir.</p>
                @endif
                <div class="mt-3 grid gap-3">
                    <x-ui.input label="Transportadora" name="carrier" maxlength="80" wire:model="carrier" required />
                    <x-ui.input label="Código de rastreamento (opcional)" name="trackingCode" maxlength="80" wire:model="trackingCode" />
                </div>
                <x-ui.button type="submit" class="mt-4" wire:loading.attr="disabled" wire:target="ship">Expedir a remessa</x-ui.button>
            </form>
            <form wire:submit="cancel" class="fv-card p-5" novalidate>
                <h2 class="text-lg font-semibold">Cancelar a separação</h2>
                <div class="mt-3"><x-ui.input label="Motivo" name="cancelReason" maxlength="500" wire:model="cancelReason" required /></div>
                <x-ui.button type="submit" class="mt-4" variant="secondary" wire:loading.attr="disabled" wire:target="cancel">Cancelar a separação</x-ui.button>
            </form>
        </div>
    @endif

    @if (in_array($shipment->status, [\App\Enums\Fulfillment\ShipmentStatus::Shipped, \App\Enums\Fulfillment\ShipmentStatus::Delivered], true) && $canManage)
        <form wire:submit="recordEvent" class="fv-card mb-6 p-5" novalidate>
            <h2 class="text-lg font-semibold">Registrar evento de transporte</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <x-ui.select label="Evento" name="eventKind" :options="$kinds" wire:model="eventKind" required />
                <x-ui.input label="Quando (opcional)" name="eventAt" type="datetime-local" wire:model="eventAt" />
                <x-ui.input label="Descrição (obrigatória para ocorrência)" name="eventDescription" maxlength="500" wire:model="eventDescription" />
            </div>
            <x-ui.button type="submit" class="mt-4" variant="secondary" wire:loading.attr="disabled" wire:target="recordEvent">Registrar</x-ui.button>
        </form>
    @endif

    <section class="fv-card p-5" aria-labelledby="remessa-historico">
        <h2 id="remessa-historico" class="text-lg font-semibold">Eventos de transporte</h2>
        <ol class="mt-3 space-y-2 text-sm">
            @forelse ($shipment->events as $event)
                <li wire:key="evento-{{ $event->id }}">
                    <span class="font-medium">{{ $event->kind->label() }}</span> · {{ $at($event->occurred_at) }}
                    @unless ($event->applied) <span class="text-xs text-slate-500">(sem mudança de situação)</span> @endunless
                    @if ($event->description)<span class="block text-slate-700">{{ $event->description }}</span>@endif
                </li>
            @empty
                <li class="text-slate-600">Nenhum evento registrado.</li>
            @endforelse
        </ol>
    </section>
</div>

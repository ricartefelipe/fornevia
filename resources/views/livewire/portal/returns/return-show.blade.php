<div>
    <x-ui.page-header :title="'Devolução '.$return->number" :description="'Pedido '.$return->order->number.' · '.$return->status->label()">
        <x-slot:actions>
            <a href="{{ route('portal.orders.show', $return->order_id) }}" class="fv-btn-secondary">Ver o pedido</a>
            <a href="{{ route('portal.returns') }}" class="fv-btn-secondary">Voltar</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)<x-ui.alert type="success">{{ $message }}</x-ui.alert>@endif
        @if ($failure)<x-ui.alert type="error">{{ $failure }}</x-ui.alert>@endif
    </div>

    @switch ($return->status)
        @case (\App\Enums\Fulfillment\ReturnStatus::Requested)
            <x-ui.alert type="info" class="mb-4">O distribuidor está analisando o pedido de devolução. Aguarde a autorização antes de enviar os itens.</x-ui.alert>
            @break
        @case (\App\Enums\Fulfillment\ReturnStatus::Authorized)
            <x-ui.alert type="success" class="mb-4">Devolução autorizada. Envie os itens ao distribuidor{{ $return->decision_comment ? ': '.$return->decision_comment : '.' }}</x-ui.alert>
            @break
        @case (\App\Enums\Fulfillment\ReturnStatus::Rejected)
            <x-ui.alert type="warning" class="mb-4">Devolução recusada: {{ $return->decision_comment }}</x-ui.alert>
            @break
        @case (\App\Enums\Fulfillment\ReturnStatus::Received)
            <x-ui.alert type="info" class="mb-4">Os itens chegaram e estão em inspeção.</x-ui.alert>
            @break
        @case (\App\Enums\Fulfillment\ReturnStatus::Closed)
            <x-ui.alert type="success" class="mb-4">Devolução concluída. Valor a devolver: {{ $return->refund_cents->format() }}.</x-ui.alert>
            @break
    @endswitch

    <section class="fv-card mb-6 overflow-x-auto p-5" aria-labelledby="devolucao-itens">
        <h2 id="devolucao-itens" class="text-lg font-semibold">Itens</h2>
        <p class="mt-1 text-sm text-slate-700">Motivo: {{ $return->reason_code->label() }} · {{ $return->reason }}</p>
        <table class="fv-table mt-3">
            <caption class="sr-only">Itens da devolução</caption>
            <thead><tr><th scope="col">Item</th><th scope="col" class="text-right">Pedido</th><th scope="col" class="text-right">Recebido</th><th scope="col" class="text-right">Aprovado</th><th scope="col">Inspeção</th></tr></thead>
            <tbody>
                @foreach ($return->items as $item)
                    <tr wire:key="item-{{ $item->id }}">
                        <td><span class="font-medium">{{ $item->orderItem->product_name }}</span> <span class="block text-xs text-slate-600">{{ $item->orderItem->sku_code }}</span></td>
                        <td class="text-right">{{ $item->quantity->format() }} {{ $item->orderItem->unit_code }}</td>
                        <td class="text-right">{{ $item->quantity_received->format() }}</td>
                        <td class="text-right">{{ $item->quantity_approved->format() }}</td>
                        <td class="text-sm">{{ $item->inspection_note ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    @if ($canCancel)
        <x-ui.button variant="secondary" wire:click="cancel" wire:confirm="Desistir desta devolução?" wire:loading.attr="disabled">Desistir da devolução</x-ui.button>
    @endif
</div>

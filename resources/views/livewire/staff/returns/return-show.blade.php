@php($at = static fn ($instant): string => $instant ? $instant->setTimezone($timezone)->format('d/m/Y H:i') : '—')
@php($status = $return->status)
<div>
    <x-ui.page-header :title="'Devolução '.$return->number" :description="'Pedido '.$return->order->number.' · '.$return->order->buyerCompany?->trade_name.' · '.$status->label()">
        <x-slot:actions>
            <a href="{{ route('orders.show', $return->order_id) }}" class="fv-btn-secondary">Ver o pedido</a>
            <a href="{{ route('returns.index') }}" class="fv-btn-secondary">Voltar</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)<x-ui.alert type="success">{{ $message }}</x-ui.alert>@endif
        @if ($failure)<x-ui.alert type="error">{{ $failure }}</x-ui.alert>@endif
    </div>

    <dl class="fv-card mb-6 grid gap-3 p-5 text-sm sm:grid-cols-3">
        <div><dt class="text-slate-500">Motivo</dt><dd>{{ $return->reason_code->label() }}</dd></div>
        <div><dt class="text-slate-500">Pedida em</dt><dd>{{ $at($return->created_at) }}</dd></div>
        <div><dt class="text-slate-500">Depósito</dt><dd>{{ $return->warehouse?->name ?? '—' }}{{ $return->warehouse ? ($return->inspection_required ? ' · com inspeção' : ' · sem inspeção') : '' }}</dd></div>
        <div class="sm:col-span-3"><dt class="text-slate-500">Descrição do comprador</dt><dd class="whitespace-pre-line">{{ $return->reason }}</dd></div>
        @if ($return->decision_comment)<div class="sm:col-span-3"><dt class="text-slate-500">Comentário da análise</dt><dd>{{ $return->decision_comment }}</dd></div>@endif
        @if ($status === \App\Enums\Fulfillment\ReturnStatus::Closed)<div><dt class="text-slate-500">Valor a devolver</dt><dd class="font-semibold">{{ $return->refund_cents->format() }}</dd></div>@endif
    </dl>

    <form wire:submit="{{ $status === \App\Enums\Fulfillment\ReturnStatus::Received ? 'inspect' : 'receive' }}" class="fv-card mb-6 p-5" novalidate>
        <h2 class="text-lg font-semibold">Itens</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="fv-table">
                <caption class="sr-only">Itens da devolução</caption>
                <thead><tr><th scope="col">Item</th><th scope="col" class="text-right">Pedido</th><th scope="col">Recebido</th><th scope="col">Aprovado</th><th scope="col" class="text-right">Reprovado</th></tr></thead>
                <tbody>
                    @foreach ($return->items as $item)
                        <tr wire:key="item-{{ $item->id }}">
                            <td><span class="font-medium">{{ $item->orderItem->sku_code }}</span> <span class="block text-xs text-slate-600">{{ $item->orderItem->product_name }}</span></td>
                            <td class="text-right">{{ $item->quantity->format() }} {{ $item->orderItem->unit_code }}</td>
                            <td class="w-36">
                                @if ($status === \App\Enums\Fulfillment\ReturnStatus::Authorized && $canManage)
                                    <x-ui.input :label="'Recebido de '.$item->orderItem->sku_code" name="received.{{ $item->id }}" inputmode="decimal" maxlength="21" wire:model="received.{{ $item->id }}" />
                                @else
                                    {{ $item->quantity_received->format() }}
                                @endif
                            </td>
                            <td class="w-48">
                                @if ($status === \App\Enums\Fulfillment\ReturnStatus::Received && $canManage)
                                    <x-ui.input :label="'Aprovado de '.$item->orderItem->sku_code" name="approved.{{ $item->id }}" inputmode="decimal" maxlength="21" wire:model="approved.{{ $item->id }}" />
                                    <x-ui.input :label="'Observação da inspeção de '.$item->orderItem->sku_code" name="notes.{{ $item->id }}" maxlength="500" wire:model="notes.{{ $item->id }}" />
                                @else
                                    {{ $item->quantity_approved->format() }}
                                    @if ($item->inspection_note)<span class="block text-xs text-slate-600">{{ $item->inspection_note }}</span>@endif
                                @endif
                            </td>
                            <td class="text-right">{{ $item->quantity_rejected->format() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($canManage && $status === \App\Enums\Fulfillment\ReturnStatus::Authorized)
            <x-ui.button type="submit" class="mt-4" wire:loading.attr="disabled">Registrar recebimento</x-ui.button>
        @elseif ($canManage && $status === \App\Enums\Fulfillment\ReturnStatus::Received)
            <x-ui.button type="submit" class="mt-4" wire:loading.attr="disabled">Concluir a inspeção</x-ui.button>
        @endif
    </form>

    @if ($canManage && $status === \App\Enums\Fulfillment\ReturnStatus::Requested)
        <section class="fv-card p-5" aria-labelledby="devolucao-analise">
            <h2 id="devolucao-analise" class="text-lg font-semibold">Análise</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <x-ui.select label="Depósito que recebe" name="warehouseId" :options="$warehouses" wire:model="warehouseId" />
                <label class="flex items-center gap-2 self-end text-sm text-slate-700">
                    <input type="checkbox" wire:model="inspectionRequired" class="rounded border-slate-300"> Exigir inspeção antes de voltar ao estoque
                </label>
                <div class="sm:col-span-2"><x-ui.textarea label="Comentário ao comprador (obrigatório para recusar)" name="comment" maxlength="1000" wire:model="comment" /></div>
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
                <x-ui.button type="button" wire:click="approve" wire:loading.attr="disabled">Autorizar</x-ui.button>
                <x-ui.button type="button" variant="secondary" wire:click="reject" wire:loading.attr="disabled">Recusar</x-ui.button>
            </div>
        </section>
    @endif
</div>

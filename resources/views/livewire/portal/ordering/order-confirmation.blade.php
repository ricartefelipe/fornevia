@php($timezone = (string) config('app.business_timezone'))
<div>
    <x-ui.page-header :title="$title" :description="$description">
        <x-slot:actions>
            <a href="{{ route('portal.orders.show', $order->id) }}" class="fv-btn-primary">Ver detalhes do pedido</a>
            <a href="{{ route('portal.catalog') }}" class="fv-btn-secondary">Voltar ao catálogo</a>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($pendingApproval)
        <x-ui.alert type="info" class="mb-6">
            <p class="font-semibold">Aguardando aprovação interna.</p>
            <p class="mt-1">
                Estoque e crédito ainda não foram reservados: a aprovação interna não garante estoque nem crédito no distribuidor.
                Depois da aprovação, o pedido é confirmado com os preços, o estoque e o crédito do momento; você acompanha a situação em Pedidos.
            </p>
        </x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="fv-card p-5 lg:col-span-2" aria-labelledby="confirmacao-itens">
            <h2 id="confirmacao-itens" class="text-lg font-semibold">Itens</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="fv-table">
                    <caption class="sr-only">Itens do pedido {{ $order->number }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">Item</th>
                            <th scope="col" class="text-right">Quantidade</th>
                            <th scope="col" class="text-right">Total</th>
                            <th scope="col">Atendimento</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($order->items as $item)
                            <tr wire:key="item-{{ $item->id }}">
                                <td>
                                    <p class="font-medium text-slate-900">{{ $item->product_name }}</p>
                                    <p class="font-mono text-xs text-slate-600">{{ $item->sku_code }}</p>
                                </td>
                                <td class="text-right">{{ $item->quantity->format() }} {{ $item->unit_code }}</td>
                                <td class="text-right">{{ $item->line_total_cents->format() }}</td>
                                <td class="text-sm">
                                    @if (! $confirmed)
                                        <x-ui.badge color="amber">{{ $pendingApproval ? 'Aguardando aprovação' : 'Sem reserva' }}</x-ui.badge>
                                    @elseif ($item->quantity_backordered->isPositive())
                                        <x-ui.badge color="amber">Sob encomenda: {{ $item->quantity_backordered->format() }}</x-ui.badge>
                                    @else
                                        <x-ui.badge color="green">Reservado</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @if ($approvalLevels !== [])
            <section class="fv-card p-5 lg:col-span-2" aria-labelledby="confirmacao-aprovacao">
                <h2 id="confirmacao-aprovacao" class="text-lg font-semibold">Aprovação interna</h2>
                @if ($order->approval_rule_name)
                    <p class="mt-1 text-sm text-slate-600">Regra aplicada: {{ $order->approval_rule_name }}.</p>
                @endif
                @include('livewire.portal.ordering.partials.approval-levels', ['levels' => $approvalLevels, 'levelsLabel' => 'Níveis de aprovação do pedido '.$order->number])
            </section>
        @endif

        <aside class="space-y-6 lg:col-start-3 lg:row-span-2 lg:row-start-1" aria-label="Resumo do pedido">
            <section class="fv-card p-5" aria-labelledby="confirmacao-resumo">
                <h2 id="confirmacao-resumo" class="text-lg font-semibold">Resumo</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-600">Número</dt><dd class="font-medium">{{ $order->number }}</dd></div>
                    @if ($order->purchase_order_reference)
                        <div class="flex justify-between gap-3"><dt class="text-slate-600">Sua ordem de compra</dt><dd>{{ $order->purchase_order_reference }}</dd></div>
                    @endif
                    <div class="flex justify-between gap-3"><dt class="text-slate-600">Pagamento</dt><dd>{{ $order->payment_term['name'] ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="font-semibold text-slate-900">Total</dt><dd class="font-semibold">{{ $order->total_cents->format() }}</dd></div>
                </dl>
            </section>

            <section class="fv-card p-5" aria-labelledby="confirmacao-entrega">
                <h2 id="confirmacao-entrega" class="text-lg font-semibold">Entrega</h2>
                <div class="mt-2">@include('livewire.portal.ordering.partials.address', ['address' => $order->shipping_address])</div>
            </section>
        </aside>
    </div>
</div>

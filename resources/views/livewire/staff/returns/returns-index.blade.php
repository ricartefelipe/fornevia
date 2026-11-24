<div>
    <x-ui.page-header title="Devoluções" description="Pedidos de devolução das empresas compradoras: análise, recebimento e inspeção." />

    <nav aria-label="Filas de devolução" class="mb-4 flex flex-wrap gap-2">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['fv-btn-primary' => $tab === $key, 'fv-btn-secondary' => $tab !== $key]) aria-current="{{ $tab === $key ? 'page' : 'false' }}">{{ $label }}</button>
        @endforeach
    </nav>

    <section class="fv-card overflow-x-auto" aria-label="{{ $tabs[$tab] }}">
        @if ($returns->isEmpty())
            <div class="p-6"><x-ui.empty-state title="Nenhuma devolução" description="As devoluções desta fila aparecem aqui." /></div>
        @else
            <table class="fv-table">
                <caption class="sr-only">Devoluções</caption>
                <thead><tr><th scope="col">Devolução</th><th scope="col">Pedido</th><th scope="col">Empresa</th><th scope="col">Motivo</th><th scope="col">Pedida em</th><th scope="col">Situação</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody>
                    @foreach ($returns as $return)
                        <tr wire:key="devolucao-{{ $return->id }}">
                            <td class="font-medium">{{ $return->number }}</td>
                            <td>{{ $return->order->number }}</td>
                            <td>{{ $return->order->buyerCompany?->trade_name }}</td>
                            <td>{{ $return->reason_code->label() }}</td>
                            <td>{{ $return->created_at->setTimezone($timezone)->format('d/m/Y H:i') }}</td>
                            <td>{{ $return->status->label() }}</td>
                            <td class="text-right"><a href="{{ route('returns.show', $return->id) }}" class="fv-btn-secondary">Abrir</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="p-4">{{ $returns->links() }}</div>
        @endif
    </section>
</div>

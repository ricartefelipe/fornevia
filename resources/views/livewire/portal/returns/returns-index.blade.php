<div>
    <x-ui.page-header title="Devoluções" description="Peça a devolução pela tela do pedido entregue e acompanhe aqui a análise, o recebimento e a inspeção." />

    <section class="fv-card overflow-x-auto">
        @if ($returns->isEmpty())
            <div class="p-6"><x-ui.empty-state title="Nenhuma devolução" description="As devoluções pedidas aparecem aqui." /></div>
        @else
            <table class="fv-table">
                <caption class="sr-only">Devoluções</caption>
                <thead><tr><th scope="col">Devolução</th><th scope="col">Pedido</th><th scope="col">Pedida em</th><th scope="col">Situação</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody>
                    @foreach ($returns as $return)
                        <tr wire:key="devolucao-{{ $return->id }}">
                            <td class="font-medium">{{ $return->number }}</td>
                            <td>{{ $return->order->number }}</td>
                            <td>{{ $return->created_at->setTimezone($timezone)->format('d/m/Y H:i') }}</td>
                            <td>{{ $return->status->label() }}</td>
                            <td class="text-right"><a href="{{ route('portal.returns.show', $return->id) }}" class="fv-btn-secondary">Abrir</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="p-4">{{ $returns->links() }}</div>
        @endif
    </section>
</div>

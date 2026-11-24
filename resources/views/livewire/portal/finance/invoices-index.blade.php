<div>
    <x-ui.page-header title="Faturas" description="Faturas comerciais dos pedidos expedidos, com as parcelas e o pagamento pelo gateway do distribuidor." />

    <section class="fv-card overflow-x-auto">
        @if ($invoices->isEmpty())
            <div class="p-6"><x-ui.empty-state title="Nenhuma fatura" description="As faturas aparecem aqui depois da expedição." /></div>
        @else
            <table class="fv-table">
                <caption class="sr-only">Faturas</caption>
                <thead><tr><th scope="col">Fatura</th><th scope="col">Pedido</th><th scope="col">Emissão</th><th scope="col">Próximo vencimento</th><th scope="col" class="text-right">Total</th><th scope="col">Situação</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody>
                    @foreach ($invoices as $invoice)
                        @php($next = $invoice->installments->firstWhere('status', \App\Enums\Finance\InstallmentStatus::Open))
                        <tr wire:key="fatura-{{ $invoice->id }}">
                            <td class="font-medium">{{ $invoice->number }}</td>
                            <td>{{ $invoice->order->number }}</td>
                            <td>{{ $invoice->issue_date->format('d/m/Y') }}</td>
                            <td>{{ $next?->due_date->format('d/m/Y') ?? '—' }}</td>
                            <td class="text-right">{{ $invoice->total_cents->format() }}</td>
                            <td>{{ $invoice->status->label() }}</td>
                            <td class="text-right"><a href="{{ route('portal.invoices.show', $invoice->id) }}" class="fv-btn-secondary">Abrir</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="p-4">{{ $invoices->links() }}</div>
        @endif
    </section>
</div>

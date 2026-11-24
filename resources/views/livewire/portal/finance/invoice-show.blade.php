<div>
    <x-ui.page-header :title="'Fatura '.$invoice->number" :description="'Pedido '.$invoice->order->number.' · '.$invoice->status->label().' · documento comercial, não é nota fiscal'">
        <x-slot:actions>
            <a href="{{ route('portal.orders.show', $invoice->order_id) }}" class="fv-btn-secondary">Ver o pedido</a>
            <a href="{{ route('portal.invoices') }}" class="fv-btn-secondary">Voltar</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)<x-ui.alert type="info">{{ $message }}</x-ui.alert>@endif
        @if ($failure)<x-ui.alert type="error">{{ $failure }}</x-ui.alert>@endif
    </div>

    <section class="fv-card mb-6 overflow-x-auto p-5" aria-labelledby="fatura-itens">
        <h2 id="fatura-itens" class="text-lg font-semibold">Itens</h2>
        <table class="fv-table mt-3">
            <caption class="sr-only">Itens faturados</caption>
            <thead><tr><th scope="col">Item</th><th scope="col" class="text-right">Quantidade</th><th scope="col" class="text-right">Valor</th></tr></thead>
            <tbody>
                @foreach ($invoice->items as $item)
                    <tr wire:key="item-{{ $item->id }}"><td>{{ $item->orderItem->product_name }} <span class="block text-xs text-slate-600">{{ $item->orderItem->sku_code }}</span></td><td class="text-right">{{ $item->quantity->format() }} {{ $item->orderItem->unit_code }}</td><td class="text-right">{{ $item->amount_cents->format() }}</td></tr>
                @endforeach
                @if ($invoice->freight_cents->isPositive())<tr><td>Frete do distribuidor</td><td></td><td class="text-right">{{ $invoice->freight_cents->format() }}</td></tr>@endif
            </tbody>
        </table>
        <p class="mt-3 text-right font-semibold">Total {{ $invoice->total_cents->format() }}</p>
    </section>

    @php($waiting = $invoice->installments->contains(fn ($i) => $i->payments->contains(fn ($p) => $p->status === \App\Enums\Finance\PaymentStatus::Pending && $p->checkout_url === null && $p->failure_reason === null)))
    <section class="fv-card overflow-x-auto p-5" aria-labelledby="fatura-parcelas" @if ($waiting) wire:poll.3s @endif>
        <h2 id="fatura-parcelas" class="text-lg font-semibold">Parcelas</h2>
        @if ($canPay)
            <div class="mt-3 max-w-xs"><x-ui.select label="Pagar com" name="method" :options="$methods" wire:model="method" /></div>
        @endif
        <table class="fv-table mt-3">
            <caption class="sr-only">Parcelas</caption>
            <thead><tr><th scope="col">Parcela</th><th scope="col">Vencimento</th><th scope="col" class="text-right">Em aberto</th><th scope="col">Situação</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
            <tbody>
                @foreach ($invoice->installments as $installment)
                    @php($pending = $installment->payments->firstWhere('status', \App\Enums\Finance\PaymentStatus::Pending))
                    <tr wire:key="parcela-{{ $installment->id }}">
                        <td>{{ $installment->sequence }}</td>
                        <td>{{ $installment->due_date->format('d/m/Y') }}</td>
                        <td class="text-right">{{ \App\Services\Finance\Receivables::open($installment)->format() }}</td>
                        <td>{{ $installment->status->label() }}
                            @if ($pending && $pending->checkout_url === null)
                                <span class="block text-xs text-slate-600">{{ $pending->failure_reason ?? 'Gerando a cobrança no gateway…' }}</span>
                            @endif
                        </td>
                        <td class="space-x-1 text-right">
                            @if ($pending && $pending->checkout_url)
                                <a href="{{ $pending->checkout_url }}" class="fv-btn-primary" @if (str_starts_with($pending->checkout_url, 'http')) target="_blank" rel="noopener noreferrer" @endif>Abrir o pagamento ({{ $pending->method->label() }})</a>
                            @endif
                            @if ($canPay && $installment->status === \App\Enums\Finance\InstallmentStatus::Open)
                                <x-ui.button :variant="$pending ? 'secondary' : 'primary'" wire:click="pay('{{ $installment->id }}')" wire:loading.attr="disabled">{{ $pending ? 'Gerar com a forma escolhida' : 'Pagar' }}</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>

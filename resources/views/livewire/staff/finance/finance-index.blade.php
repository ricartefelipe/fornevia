@php($at = static fn ($instant): string => $instant ? \Carbon\CarbonImmutable::parse($instant)->setTimezone($timezone)->format('d/m/Y H:i') : '—')
<div>
    <x-ui.page-header title="Financeiro" description="Faturas comerciais (não fiscais), parcelas, pagamentos e conciliação com o gateway.">
        <x-slot:actions>
            @if ($canManage)<a href="{{ route('finance.gateway') }}" class="fv-btn-secondary">Gateway de pagamento</a>@endif
        </x-slot:actions>
    </x-ui.page-header>

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)<x-ui.alert type="success">{{ $message }}</x-ui.alert>@endif
        @if ($failure)<x-ui.alert type="error">{{ $failure }}</x-ui.alert>@endif
    </div>

    <nav aria-label="Filas do financeiro" class="mb-4 flex flex-wrap gap-2">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['fv-btn-primary' => $tab === $key, 'fv-btn-secondary' => $tab !== $key]) aria-current="{{ $tab === $key ? 'page' : 'false' }}">{{ $label }}</button>
        @endforeach
    </nav>

    <section class="fv-card overflow-x-auto" aria-label="{{ $tabs[$tab] }}">
        @if ($rows->isEmpty())
            <div class="p-6"><x-ui.empty-state title="Nada nesta fila" description="Os registros desta fila aparecem aqui." /></div>
        @else
            <table class="fv-table">
                <caption class="sr-only">{{ $tabs[$tab] }}</caption>
                @if ($tab === 'faturar')
                    <thead><tr><th scope="col">Pedido</th><th scope="col">Empresa</th><th scope="col">Situação financeira</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                    <tbody>
                        @foreach ($rows as $order)
                            <tr wire:key="pedido-{{ $order->id }}">
                                <td class="font-medium">{{ $order->number }}</td>
                                <td>{{ $order->buyerCompany?->trade_name }}</td>
                                <td>{{ $order->financial_status->label() }}</td>
                                <td class="text-right">@if ($canManage)<x-ui.button wire:click="issue('{{ $order->id }}')" wire:loading.attr="disabled">Faturar o expedido</x-ui.button>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                @elseif ($tab === 'aberto' || $tab === 'vencidas')
                    <thead><tr><th scope="col">Fatura</th><th scope="col">Parcela</th><th scope="col">Empresa</th><th scope="col">Vencimento</th><th scope="col" class="text-right">Em aberto</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                    <tbody>
                        @foreach ($rows as $installment)
                            <tr wire:key="parcela-{{ $installment->id }}">
                                <td class="font-medium">{{ $installment->invoice->number }}</td>
                                <td>{{ $installment->sequence }}</td>
                                <td>{{ $installment->invoice->order->buyerCompany?->trade_name }}</td>
                                <td @class(['font-semibold text-red-700' => $installment->due_date->toDateString() < $today])>{{ $installment->due_date->format('d/m/Y') }}</td>
                                <td class="text-right">{{ \App\Services\Finance\Receivables::open($installment)->format() }}</td>
                                <td class="text-right"><a href="{{ route('finance.invoices.show', $installment->invoice_id) }}" class="fv-btn-secondary">Abrir</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                @elseif ($tab === 'recebidos')
                    <thead><tr><th scope="col">Fatura</th><th scope="col">Empresa</th><th scope="col">Forma</th><th scope="col">Confirmado em</th><th scope="col" class="text-right">Valor</th><th scope="col" class="text-right">Taxa</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $payment)
                            <tr wire:key="pagamento-{{ $payment->id }}">
                                <td class="font-medium"><a href="{{ route('finance.invoices.show', $payment->installment->invoice_id) }}" class="underline">{{ $payment->installment->invoice->number }}</a></td>
                                <td>{{ $payment->installment->invoice->order->buyerCompany?->trade_name }}</td>
                                <td>{{ $payment->method->label() }}</td>
                                <td>{{ $at($payment->confirmed_at) }}</td>
                                <td class="text-right">{{ $payment->amount_cents->format() }}</td>
                                <td class="text-right">{{ $payment->fee_cents->format() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                @else
                    <thead><tr><th scope="col">Recebido em</th><th scope="col">Gateway</th><th scope="col">Evento</th><th scope="col">Cobrança</th><th scope="col">Situação</th><th scope="col">Erro</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $event)
                            <tr wire:key="evento-{{ $event->id }}">
                                <td>{{ $at($event->received_at) }}</td>
                                <td>{{ \App\Enums\Finance\PaymentGatewayKind::from($event->gateway)->label() }}</td>
                                <td>{{ $event->event_type }}</td>
                                <td>{{ $event->charge_id ?? '—' }}</td>
                                <td>{{ ['pending' => 'Na fila', 'processed' => 'Aplicado', 'ignored' => 'Sem efeito', 'failed' => 'Com falha'][$event->status] ?? $event->status }}</td>
                                <td class="text-xs">{{ $event->error ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                @endif
            </table>
            <div class="p-4">{{ $rows->links() }}</div>
        @endif
    </section>
</div>

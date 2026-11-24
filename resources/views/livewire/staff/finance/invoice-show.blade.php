@php($at = static fn ($instant): string => $instant ? \Carbon\CarbonImmutable::parse($instant)->setTimezone($timezone)->format('d/m/Y H:i') : '—')
<div>
    <x-ui.page-header :title="'Fatura '.$invoice->number" :description="'Pedido '.$invoice->order->number.' · '.$invoice->order->buyerCompany?->trade_name.' · '.$invoice->status->label()">
        <x-slot:actions>
            <a href="{{ route('orders.show', $invoice->order_id) }}" class="fv-btn-secondary">Ver o pedido</a>
            <a href="{{ route('finance.index') }}" class="fv-btn-secondary">Voltar</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)<x-ui.alert type="success">{{ $message }}</x-ui.alert>@endif
        @if ($failure)<x-ui.alert type="error">{{ $failure }}</x-ui.alert>@endif
    </div>

    <dl class="fv-card mb-6 grid gap-3 p-5 text-sm sm:grid-cols-5">
        <div><dt class="text-slate-500">Emissão</dt><dd>{{ $invoice->issue_date->format('d/m/Y') }}</dd></div>
        <div><dt class="text-slate-500">Itens</dt><dd>{{ $invoice->items_cents->format() }}</dd></div>
        <div><dt class="text-slate-500">Frete</dt><dd>{{ $invoice->freight_cents->format() }}</dd></div>
        <div><dt class="text-slate-500">Total</dt><dd class="font-semibold">{{ $invoice->total_cents->format() }}</dd></div>
        <div><dt class="text-slate-500">Pago · creditado · ajustes</dt><dd>{{ $invoice->paid_cents->format() }} · {{ $invoice->credited_cents->format() }} · {{ $invoice->adjusted_cents->format() }}</dd></div>
    </dl>

    <section class="fv-card mb-6 overflow-x-auto p-5" aria-labelledby="fatura-parcelas">
        <h2 id="fatura-parcelas" class="text-lg font-semibold">Parcelas</h2>
        <table class="fv-table mt-3">
            <caption class="sr-only">Parcelas da fatura</caption>
            <thead><tr><th scope="col">Parcela</th><th scope="col">Vencimento</th><th scope="col" class="text-right">Valor</th><th scope="col" class="text-right">Em aberto</th><th scope="col">Situação</th>@if ($canManage)<th scope="col"><span class="sr-only">Ações</span></th>@endif</tr></thead>
            <tbody>
                @foreach ($invoice->installments as $installment)
                    <tr wire:key="parcela-{{ $installment->id }}">
                        <td>{{ $installment->sequence }}</td>
                        <td>{{ $installment->due_date->format('d/m/Y') }}</td>
                        <td class="text-right">{{ $installment->amount_cents->plus($installment->adjusted_cents)->format() }}</td>
                        <td class="text-right font-medium">{{ \App\Services\Finance\Receivables::open($installment)->format() }}</td>
                        <td>{{ $installment->status->label() }}</td>
                        @if ($canManage)
                            <td class="space-x-1 text-right">
                                @if ($installment->status === \App\Enums\Finance\InstallmentStatus::Open)
                                    <x-ui.button variant="secondary" wire:click="select('{{ $installment->id }}', 'payment')">Pagamento</x-ui.button>
                                    <x-ui.button variant="secondary" wire:click="select('{{ $installment->id }}', 'charge')">Cobrança</x-ui.button>
                                    <x-ui.button variant="secondary" wire:click="select('{{ $installment->id }}', 'credit')">Crédito</x-ui.button>
                                    <x-ui.button variant="secondary" wire:click="select('{{ $installment->id }}', 'adjust')">Ajuste</x-ui.button>
                                @endif
                            </td>
                        @endif
                    </tr>
                    @foreach ($installment->payments as $payment)
                        <tr wire:key="pagamento-{{ $payment->id }}" class="text-sm text-slate-700">
                            <td></td>
                            <td colspan="2">{{ $payment->method->label() }} · {{ $payment->source->label() }}{{ $payment->gateway ? ' ('.$payment->gateway->label().')' : '' }} · {{ $payment->status->label() }}
                                @if ($payment->checkout_url && $payment->status === \App\Enums\Finance\PaymentStatus::Pending)<a href="{{ $payment->checkout_url }}" class="ml-1 underline" target="_blank" rel="noopener noreferrer">link de pagamento</a>@endif
                                @if ($payment->failure_reason)<span class="block text-xs text-amber-700">{{ $payment->failure_reason }}</span>@endif
                            </td>
                            <td class="text-right">{{ $payment->amount_cents->format() }}</td>
                            <td colspan="1">{{ $payment->refunded_cents->isPositive() ? 'Reembolsado '.$payment->refunded_cents->format() : '' }}</td>
                            @if ($canManage)
                                <td class="text-right">@if ($payment->status === \App\Enums\Finance\PaymentStatus::Confirmed)<x-ui.button variant="secondary" wire:click="startRefund('{{ $payment->id }}')">Reembolsar</x-ui.button>@endif</td>
                            @endif
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </section>

    @if ($canManage && $installmentId !== '')
        <form wire:submit="submit" class="fv-card mb-6 grid gap-3 p-5 sm:grid-cols-3" novalidate>
            <h2 class="text-lg font-semibold sm:col-span-3">{{ ['payment' => 'Registrar pagamento', 'charge' => 'Gerar cobrança no gateway', 'credit' => 'Conceder crédito', 'adjust' => 'Ajustar a parcela'][$operation] }}</h2>
            @if ($operation === 'payment' || $operation === 'charge')
                <x-ui.select label="Forma" name="method" :options="$operation === 'charge' ? $chargeMethods : $methods" wire:model="method" />
            @endif
            @if ($operation !== 'charge')
                <x-ui.input :label="$operation === 'adjust' ? 'Valor (negativo reduz)' : 'Valor'" name="amount" inputmode="decimal" maxlength="20" wire:model="amount" required />
            @endif
            @if ($operation === 'credit' || $operation === 'adjust')
                <x-ui.input label="Motivo" name="reason" maxlength="500" wire:model="reason" required />
            @endif
            <div class="flex gap-3 sm:col-span-3">
                <x-ui.button type="submit" wire:loading.attr="disabled">Confirmar</x-ui.button>
                <x-ui.button type="button" variant="secondary" wire:click="$set('installmentId', '')">Cancelar</x-ui.button>
            </div>
        </form>
    @endif

    @if ($canManage && $paymentId !== '')
        <form wire:submit="refund" class="fv-card mb-6 grid gap-3 p-5 sm:grid-cols-3" novalidate>
            <h2 class="text-lg font-semibold sm:col-span-3">Reembolsar pagamento</h2>
            <x-ui.input label="Valor" name="amount" inputmode="decimal" maxlength="20" wire:model="amount" required />
            <x-ui.input label="Motivo" name="reason" maxlength="500" wire:model="reason" required />
            <div class="flex gap-3 sm:col-span-3">
                <x-ui.button type="submit" wire:loading.attr="disabled">Reembolsar</x-ui.button>
                <x-ui.button type="button" variant="secondary" wire:click="$set('paymentId', '')">Cancelar</x-ui.button>
            </div>
        </form>
    @endif

    <section class="fv-card p-5" aria-labelledby="fatura-lancamentos">
        <h2 id="fatura-lancamentos" class="text-lg font-semibold">Lançamentos</h2>
        <ol class="mt-3 space-y-1 text-sm">
            @foreach ($entries as $entry)
                <li wire:key="lancamento-{{ $entry->id }}">{{ $at($entry->created_at) }} · {{ $entryLabels[$entry->type] ?? $entry->type }} · {{ \App\Domain\Shared\Money::ofCents((int) $entry->amount_cents)->format() }}{{ $entry->reason ? ' · '.$entry->reason : '' }}</li>
            @endforeach
        </ol>
    </section>
</div>

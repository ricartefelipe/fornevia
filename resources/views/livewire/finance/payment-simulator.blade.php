<div>
    <x-ui.page-header title="Simulador de pagamento" description="Ambiente de testes: nenhum valor é cobrado de verdade.">
        <x-slot:actions><a href="{{ $back }}" class="fv-btn-secondary">Voltar à fatura</a></x-slot:actions>
    </x-ui.page-header>

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)<x-ui.alert type="success">{{ $message }}</x-ui.alert>@endif
        @if ($failure)<x-ui.alert type="error">{{ $failure }}</x-ui.alert>@endif
    </div>

    <section class="fv-card max-w-lg p-5">
        <p>Fatura {{ $payment->installment->invoice->number }}, parcela {{ $payment->installment->sequence }}</p>
        <p class="mt-1 text-2xl font-semibold">{{ $payment->amount_cents->format() }}</p>
        <p class="mt-1 text-sm text-slate-700">{{ $payment->method->label() }} · {{ $payment->status->label() }}</p>
        @if ($payment->status === \App\Enums\Finance\PaymentStatus::Pending)
            <div class="mt-4 flex gap-3">
                <x-ui.button wire:click="simulate(true)" wire:loading.attr="disabled">Simular pagamento</x-ui.button>
                <x-ui.button variant="secondary" wire:click="simulate(false)" wire:loading.attr="disabled">Simular recusa</x-ui.button>
            </div>
        @endif
    </section>
</div>

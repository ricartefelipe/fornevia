<div>
    <x-ui.page-header title="Gateway de pagamento" description="Onde os compradores pagam as faturas. Cartão é pago na página do gateway: o Fornevia não recebe nem guarda dados de cartão.">
        <x-slot:actions><a href="{{ route('finance.index') }}" class="fv-btn-secondary">Voltar</a></x-slot:actions>
    </x-ui.page-header>

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)<x-ui.alert type="success">{{ $message }}</x-ui.alert>@endif
        @if ($issuedToken)
            <x-ui.alert type="warning">
                <p class="font-semibold">Token do webhook (copie agora; ele não será mostrado de novo):</p>
                <p class="mt-1 break-all font-mono">{{ $issuedToken }}</p>
                <p class="mt-1">Cadastre no Asaas o endereço <span class="break-all font-mono">{{ $webhookUrl }}</span> com este token.</p>
            </x-ui.alert>
        @endif
    </div>

    <form wire:submit="save" class="fv-card grid max-w-xl gap-4 p-5" novalidate>
        <x-ui.select label="Gateway" name="gateway" :options="$kinds" wire:model.live="gateway" />
        @if ($gateway === 'asaas')
            <x-ui.input label="Chave de API do Asaas" name="apiKey" type="password" autocomplete="off" maxlength="300" wire:model="apiKey" :hint="$hasKey ? 'Já cadastrada. Preencha só para trocar.' : 'Use a chave do ambiente sandbox para testes.'" />
            <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model="newToken" class="rounded border-slate-300"> Gerar novo token do webhook{{ $hasToken ? ' (o atual deixa de valer)' : '' }}</label>
        @else
            <p class="text-sm text-slate-700">O simulador cria cobranças locais que podem ser pagas ou recusadas pela página de simulação, para testes e demonstração.</p>
        @endif
        <div><x-ui.button type="submit" wire:loading.attr="disabled">Salvar</x-ui.button></div>
    </form>
</div>

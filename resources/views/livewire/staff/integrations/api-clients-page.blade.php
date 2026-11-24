@php($timezone = (string) config('app.business_timezone'))
<div>
    <x-ui.page-header title="Integrações" description="Sistemas externos (ERP, WMS, BI) acessam a API REST com um token por cliente, limitado às permissões concedidas aqui." />
    @include('livewire.staff.integrations._tabs')

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)<x-ui.alert type="success">{{ $message }}</x-ui.alert>@endif
        @error('form')<x-ui.alert type="error">{{ $message }}</x-ui.alert>@enderror
        @if ($issuedToken)
            <x-ui.alert type="warning">
                <p class="font-semibold">Token de acesso (copie agora; ele não será mostrado de novo):</p>
                <p class="mt-1 break-all font-mono">{{ $issuedToken }}</p>
                <p class="mt-1">Envie no cabeçalho <span class="font-mono">Authorization: Bearer &lt;token&gt;</span> para <span class="break-all font-mono">{{ $apiUrl }}</span>.</p>
            </x-ui.alert>
        @endif
    </div>

    <form wire:submit="create" class="fv-card mb-6 grid max-w-2xl gap-4 p-5" novalidate aria-labelledby="api-client-form-title">
        <h2 id="api-client-form-title" class="text-base font-semibold text-slate-900">Novo cliente da API</h2>
        <x-ui.input label="Nome do sistema" name="name" maxlength="80" wire:model="name" />
        <fieldset>
            <legend class="text-sm font-medium text-slate-700">Permissões</legend>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                @foreach ($abilityOptions as $value => $label)
                    <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" value="{{ $value }}" wire:model="permissions" class="rounded border-slate-300"> {{ $label }}</label>
                @endforeach
            </div>
            @error('permissions')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
        </fieldset>
        <x-ui.input label="Validade do token (dias)" name="validDays" type="number" min="1" max="365" wire:model="validDays" hint="De 1 a 365 dias; vale também para novos tokens emitidos abaixo." />
        <div><x-ui.button type="submit" wire:loading.attr="disabled">Criar cliente e emitir token</x-ui.button></div>
    </form>

    <section aria-labelledby="api-clients-title">
        <h2 id="api-clients-title" class="mb-3 text-base font-semibold text-slate-900">Clientes cadastrados</h2>
        @forelse ($clients as $client)
            <article class="fv-card mb-3 p-4" wire:key="client-{{ $client->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-slate-900">{{ $client->name }}
                            @if ($client->revoked_at)<x-ui.badge color="red">Revogado</x-ui.badge>@else<x-ui.badge color="green">Ativo</x-ui.badge>@endif
                        </h3>
                        <p class="text-sm text-slate-600">{{ collect($client->permissions)->map(fn ($p) => $abilityOptions[$p] ?? $p)->implode(', ') }}</p>
                        <p class="text-xs text-slate-500">Último uso: {{ $client->last_used_at?->setTimezone($timezone)->format('d/m/Y H:i') ?? 'nunca' }}</p>
                    </div>
                    @unless ($client->revoked_at)
                        <div class="flex gap-2">
                            <x-ui.button variant="secondary" wire:click="newToken('{{ $client->id }}')">Emitir novo token</x-ui.button>
                            <x-ui.button variant="danger" wire:click="revoke('{{ $client->id }}')" wire:confirm="Revogar o cliente {{ $client->name }}? Os tokens dele deixam de valer na hora.">Revogar cliente</x-ui.button>
                        </div>
                    @endunless
                </div>
                @if ($client->tokens->isNotEmpty())
                    <ul class="mt-3 divide-y divide-slate-100 text-sm">
                        @foreach ($client->tokens as $token)
                            <li class="flex items-center justify-between py-2" wire:key="token-{{ $token->id }}">
                                <span>Token nº {{ $token->id }} · válido até {{ $token->expires_at?->setTimezone($timezone)->format('d/m/Y') ?? '—' }} · último uso {{ $token->last_used_at?->setTimezone($timezone)->format('d/m/Y H:i') ?? 'nunca' }}</span>
                                <x-ui.button variant="secondary" wire:click="revokeToken({{ $token->id }})" wire:confirm="Revogar o token nº {{ $token->id }}?">Revogar token</x-ui.button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </article>
        @empty
            <x-ui.empty-state title="Nenhum cliente da API" description="Cadastre o primeiro sistema externo para emitir um token." />
        @endforelse
    </section>
</div>

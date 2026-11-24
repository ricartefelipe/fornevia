@php($timezone = (string) config('app.business_timezone'))
<div>
    <x-ui.page-header title="Integrações" description="Eventos do Fornevia enviados por HTTPS a sistemas externos, assinados com HMAC-SHA256 e reenviados com espera crescente em caso de falha." />
    @include('livewire.staff.integrations._tabs')

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)<x-ui.alert type="success">{{ $message }}</x-ui.alert>@endif
        @error('form')<x-ui.alert type="error">{{ $message }}</x-ui.alert>@enderror
        @if ($issuedSecret)
            <x-ui.alert type="warning">
                <p class="font-semibold">Segredo de assinatura (copie agora; ele não será mostrado de novo):</p>
                <p class="mt-1 break-all font-mono">{{ $issuedSecret }}</p>
                <p class="mt-1">Confira o cabeçalho <span class="font-mono">X-Fornevia-Signature</span> (t=carimbo,v1=HMAC-SHA256 de "carimbo.corpo") e recuse carimbos com mais de 5 minutos.</p>
            </x-ui.alert>
        @endif
    </div>

    <form wire:submit="create" class="fv-card mb-6 grid max-w-2xl gap-4 p-5" novalidate aria-labelledby="webhook-form-title">
        <h2 id="webhook-form-title" class="text-base font-semibold text-slate-900">Novo endpoint</h2>
        <x-ui.input label="Endereço (HTTPS)" name="url" type="url" maxlength="500" wire:model="url" placeholder="https://erp.exemplo.com.br/webhooks/fornevia" />
        <fieldset>
            <legend class="text-sm font-medium text-slate-700">Eventos</legend>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                @foreach ($eventOptions as $value => $label)
                    <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" value="{{ $value }}" wire:model="events" class="rounded border-slate-300"> {{ $label }} <span class="font-mono text-xs text-slate-500">{{ $value }}</span></label>
                @endforeach
            </div>
            @error('events')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
        </fieldset>
        <div><x-ui.button type="submit" wire:loading.attr="disabled">Cadastrar endpoint</x-ui.button></div>
    </form>

    <section class="mb-6" aria-labelledby="webhook-endpoints-title">
        <h2 id="webhook-endpoints-title" class="mb-3 text-base font-semibold text-slate-900">Endpoints</h2>
        @forelse ($endpoints as $endpoint)
            <article class="fv-card mb-3 p-4" wire:key="endpoint-{{ $endpoint->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="break-all font-mono text-sm text-slate-900">{{ $endpoint->url }}</h3>
                        <p class="mt-1">
                            @if ($endpoint->is_active)<x-ui.badge color="green">Ativo</x-ui.badge>@else<x-ui.badge color="red">Inativo</x-ui.badge>@endif
                            @if ($endpoint->consecutive_failures > 0)<x-ui.badge color="amber">{{ $endpoint->consecutive_failures }} falha(s) seguida(s)</x-ui.badge>@endif
                        </p>
                        @if ($endpoint->disabled_reason)<p class="mt-1 text-sm text-red-700">{{ $endpoint->disabled_reason }}</p>@endif
                        <p class="mt-1 text-sm text-slate-600">{{ collect($endpoint->events)->map(fn ($e) => $eventOptions[$e] ?? $e)->implode(', ') }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button variant="secondary" wire:click="ping('{{ $endpoint->id }}')">Enviar teste</x-ui.button>
                        <x-ui.button variant="secondary" wire:click="toggle('{{ $endpoint->id }}')">{{ $endpoint->is_active ? 'Desativar' : 'Ativar' }}</x-ui.button>
                        <x-ui.button variant="secondary" wire:click="rotate('{{ $endpoint->id }}')" wire:confirm="Trocar o segredo? O sistema de destino precisa do novo para conferir as assinaturas.">Trocar segredo</x-ui.button>
                        <x-ui.button variant="danger" wire:click="delete('{{ $endpoint->id }}')" wire:confirm="Excluir o endpoint e o histórico de entregas dele?">Excluir</x-ui.button>
                    </div>
                </div>
            </article>
        @empty
            <x-ui.empty-state title="Nenhum endpoint" description="Cadastre um endereço para receber os eventos escolhidos." />
        @endforelse
    </section>

    <section aria-labelledby="webhook-deliveries-title">
        <h2 id="webhook-deliveries-title" class="mb-3 text-base font-semibold text-slate-900">Entregas recentes</h2>
        @if ($deliveries->isEmpty())
            <p class="text-sm text-slate-600">Nenhuma entrega ainda.</p>
        @else
            <div class="fv-card overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <caption class="sr-only">Últimas {{ $deliveries->count() }} entregas</caption>
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr><th scope="col" class="px-3 py-2">Quando</th><th scope="col" class="px-3 py-2">Evento</th><th scope="col" class="px-3 py-2">Endpoint</th><th scope="col" class="px-3 py-2">Situação</th><th scope="col" class="px-3 py-2">Tentativas</th><th scope="col" class="px-3 py-2">Resposta</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($deliveries as $delivery)
                            <tr wire:key="delivery-{{ $delivery->id }}">
                                <td class="px-3 py-2 whitespace-nowrap">{{ $delivery->created_at->setTimezone($timezone)->format('d/m/Y H:i:s') }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $delivery->event_type }}</td>
                                <td class="max-w-xs truncate px-3 py-2 font-mono text-xs" title="{{ $delivery->endpoint->url }}">{{ $delivery->endpoint->url }}</td>
                                <td class="px-3 py-2">
                                    @switch($delivery->status)
                                        @case('delivered')<x-ui.badge color="green">Entregue</x-ui.badge>@break
                                        @case('failed')<x-ui.badge color="red">Falhou</x-ui.badge>@break
                                        @default<x-ui.badge color="amber">Pendente</x-ui.badge>
                                    @endswitch
                                </td>
                                <td class="px-3 py-2">{{ $delivery->attempts }}</td>
                                <td class="px-3 py-2">{{ $delivery->response_status ?? '—' }}@if ($delivery->last_error) <span class="text-xs text-slate-500">· {{ $delivery->last_error }}</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>

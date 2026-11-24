@php($timezone = (string) config('app.business_timezone'))
<div>
    <x-ui.page-header title="Auditoria" description="Registro somente leitura das operações sensíveis do distribuidor: quem fez, quando, de onde, em qual registro e com qual motivo." />

    <section class="fv-card mb-4 p-4" aria-labelledby="audit-filters-title">
        <h2 id="audit-filters-title" class="sr-only">Filtros</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5" role="search">
            <x-ui.select label="Ação" name="action" wire:model.live="action" placeholder="Todas" :options="$actionOptions" />
            <x-ui.select label="Autor" name="actor" wire:model.live="actor" placeholder="Todos" :options="$actorOptions" />
            <x-ui.input label="Registro afetado (id)" name="subject" maxlength="36" autocomplete="off" wire:model.live.debounce.400ms="subject" />
            <x-ui.input label="De" name="dateFrom" type="date" wire:model.live="dateFrom" min="2000-01-01" max="2099-12-31" />
            <x-ui.input label="Até" name="dateUntil" type="date" wire:model.live="dateUntil" min="2000-01-01" max="2099-12-31" />
        </div>
        @if ($filtered)
            <div class="mt-3"><x-ui.button variant="secondary" wire:click="clearFilters">Limpar filtros</x-ui.button></div>
        @endif
    </section>

    @if ($logs->isEmpty())
        <x-ui.empty-state title="Nenhum registro" :description="$filtered ? 'Nenhum registro atende aos filtros informados.' : 'As operações auditadas aparecem aqui.'" />
    @else
        <div class="fv-card overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <caption class="sr-only">Registros de auditoria, do mais recente ao mais antigo</caption>
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr><th scope="col" class="px-3 py-2">Quando</th><th scope="col" class="px-3 py-2">Autor</th><th scope="col" class="px-3 py-2">Ação</th><th scope="col" class="px-3 py-2">Registro</th><th scope="col" class="px-3 py-2">Detalhes</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100 align-top">
                    @foreach ($logs as $log)
                        <tr wire:key="audit-{{ $log->id }}">
                            <td class="px-3 py-2 whitespace-nowrap">{{ $log->created_at->setTimezone($timezone)->format('d/m/Y H:i:s') }}</td>
                            <td class="px-3 py-2">
                                @if ($log->actor){{ $log->actor->name }}@elseif ($log->actor_api_client_id)Cliente da API @else Sistema @endif
                                @if ($log->ip)<span class="block text-xs text-slate-500">{{ $log->ip }}</span>@endif
                            </td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $log->action }}</td>
                            <td class="px-3 py-2 text-xs">
                                @if ($log->subject_type){{ $log->subject_type }}<span class="block font-mono text-slate-500">{{ $log->subject_id }}</span>@else — @endif
                            </td>
                            <td class="px-3 py-2 text-xs">
                                @if ($log->reason)<p><span class="font-medium">Motivo:</span> {{ $log->reason }}</p>@endif
                                @if ($log->data !== [])<pre class="max-w-md overflow-x-auto whitespace-pre-wrap break-all font-mono text-slate-600">{{ json_encode($log->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>@endif
                                @if ($log->correlation_id)<p class="text-slate-500">Requisição {{ $log->correlation_id }}</p>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $logs->links() }}</div>
    @endif
</div>

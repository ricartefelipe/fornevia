@php($timezone = (string) config('app.business_timezone'))
@php($statusLabels = ['validated' => ['Em prévia', 'slate'], 'queued' => ['Na fila', 'blue'], 'processing' => ['Aplicando', 'blue'], 'done' => ['Concluída', 'green'], 'rejected' => ['Recusada', 'red'], 'failed' => ['Falhou', 'red']])
@php($rowLabels = ['valid' => ['Válida', 'green'], 'invalid' => ['Inválida', 'red'], 'applied' => ['Aplicada', 'green'], 'skipped' => ['Recusada ao aplicar', 'amber']])
<div>
    <x-ui.page-header title="Integrações" description="Cadastros em lote por arquivo CSV (UTF-8, separado por ponto e vírgula ou vírgula), com prévia de cada linha antes de aplicar." />
    @include('livewire.staff.integrations._tabs')

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)<x-ui.alert type="success">{{ $message }}</x-ui.alert>@endif
        @error('confirm')<x-ui.alert type="error">{{ $message }}</x-ui.alert>@enderror
    </div>

    @if ($preview === null)
        <form wire:submit="preview" class="fv-card mb-6 grid max-w-2xl gap-4 p-5" novalidate aria-labelledby="import-form-title">
            <h2 id="import-form-title" class="text-base font-semibold text-slate-900">Nova importação</h2>
            <x-ui.select label="O que importar" name="kind" :options="$kindOptions" wire:model.live="kind" />
            <p class="text-sm text-slate-600">Colunas: <span class="font-mono">{{ implode(';', $columns[$kind] ?? []) }}</span></p>
            <x-ui.select label="Ao confirmar" name="policy" :options="$policyOptions" wire:model="policy" />
            <div>
                <label for="import-file" class="block text-sm font-medium text-slate-700">Arquivo CSV</label>
                <input id="import-file" type="file" accept=".csv,text/csv" wire:model="file" class="mt-1 block w-full text-sm" @error('file') aria-invalid="true" aria-describedby="import-file-error" @enderror>
                @error('file')<p id="import-file-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
            <div><x-ui.button type="submit" wire:loading.attr="disabled" wire:target="file,preview">Validar arquivo</x-ui.button></div>
        </form>
    @else
        <section class="fv-card mb-6 p-5" aria-labelledby="import-preview-title">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="import-preview-title" class="text-base font-semibold text-slate-900">{{ $kindOptions[$preview->kind] ?? $preview->kind }} · {{ $preview->original_name }}</h2>
                    <p class="text-sm text-slate-600">{{ $preview->rows_total }} linha(s): {{ $preview->rows_valid }} válida(s), {{ $preview->rows_invalid }} inválida(s){{ $preview->rows_applied > 0 ? ', '.$preview->rows_applied.' aplicada(s)' : '' }} · {{ $policyOptions[$preview->policy] ?? $preview->policy }}</p>
                    @if ($preview->failure)<p class="mt-1 text-sm text-red-700">{{ $preview->failure }}</p>@endif
                </div>
                <div class="flex gap-2">
                    @if ($preview->status === 'validated')
                        <x-ui.button wire:click="confirm" wire:loading.attr="disabled" :disabled="$preview->rows_valid === 0">Confirmar importação</x-ui.button>
                    @endif
                    <x-ui.button variant="secondary" wire:click="discard">{{ $preview->status === 'validated' ? 'Descartar' : 'Fechar' }}</x-ui.button>
                </div>
            </div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <caption class="sr-only">Resultado por linha (até {{ $previewRows->count() }} linhas, primeiro as com problema)</caption>
                    <thead class="bg-slate-50 text-left text-slate-600"><tr><th scope="col" class="px-3 py-2">Linha</th><th scope="col" class="px-3 py-2">Situação</th><th scope="col" class="px-3 py-2">Dados</th><th scope="col" class="px-3 py-2">Problemas</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($previewRows as $row)
                            <tr wire:key="row-{{ $row->id }}">
                                <td class="px-3 py-2">{{ $row->line }}</td>
                                <td class="px-3 py-2"><x-ui.badge :color="$rowLabels[$row->status][1] ?? 'slate'">{{ $rowLabels[$row->status][0] ?? $row->status }}</x-ui.badge></td>
                                <td class="px-3 py-2 text-xs text-slate-600">{{ collect($row->data)->filter()->implode(' · ') }}</td>
                                <td class="px-3 py-2 text-red-700">{{ implode(' ', $row->errors) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section aria-labelledby="import-history-title">
        <h2 id="import-history-title" class="mb-3 text-base font-semibold text-slate-900">Histórico</h2>
        @if ($history->isEmpty())
            <p class="text-sm text-slate-600">Nenhuma importação ainda.</p>
        @else
            <div class="fv-card overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <caption class="sr-only">Últimas importações</caption>
                    <thead class="bg-slate-50 text-left text-slate-600"><tr><th scope="col" class="px-3 py-2">Quando</th><th scope="col" class="px-3 py-2">Tipo</th><th scope="col" class="px-3 py-2">Arquivo</th><th scope="col" class="px-3 py-2">Situação</th><th scope="col" class="px-3 py-2">Linhas</th><th scope="col" class="px-3 py-2"><span class="sr-only">Ações</span></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($history as $item)
                            <tr wire:key="import-{{ $item->id }}">
                                <td class="px-3 py-2 whitespace-nowrap">{{ $item->created_at->setTimezone($timezone)->format('d/m/Y H:i') }}</td>
                                <td class="px-3 py-2">{{ $kindOptions[$item->kind] ?? $item->kind }}</td>
                                <td class="px-3 py-2">{{ $item->original_name }}</td>
                                <td class="px-3 py-2"><x-ui.badge :color="$statusLabels[$item->status][1] ?? 'slate'">{{ $statusLabels[$item->status][0] ?? $item->status }}</x-ui.badge></td>
                                <td class="px-3 py-2">{{ $item->rows_applied }} de {{ $item->rows_total }} aplicada(s)</td>
                                <td class="px-3 py-2"><button type="button" class="text-brand-700 underline" wire:click="open('{{ $item->id }}')">Ver linhas</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>

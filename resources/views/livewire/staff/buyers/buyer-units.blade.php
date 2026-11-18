<section class="fv-card" aria-labelledby="units-title">
    <div class="flex items-center justify-between gap-3 px-6 pt-5">
        <h2 id="units-title" class="text-lg font-semibold">Unidades</h2>
        @if ($canManage && ! $formOpen)
            <x-ui.button variant="secondary" wire:click="create">Adicionar unidade</x-ui.button>
        @endif
    </div>

    @if ($message)
        <div class="px-6 pt-3"><x-ui.alert type="success">{{ $message }}</x-ui.alert></div>
    @endif

    @if ($canManage && $formOpen)
        <form wire:submit="save" class="mx-6 mt-4 grid gap-3 rounded-md border border-slate-200 p-4 sm:grid-cols-3" novalidate aria-label="{{ $editingId ? 'Editar unidade' : 'Nova unidade' }}">
            <x-ui.input label="Código" name="code" id="unit-code" wire:model="code" required maxlength="20" hint="Letras, números, ponto, hífen ou sublinhado." />
            <div class="sm:col-span-2"><x-ui.input label="Nome" name="name" id="unit-name" wire:model="name" required maxlength="120" /></div>
            <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-3">
                <input type="checkbox" wire:model="isActive" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600"> Ativa
            </label>
            <div class="flex gap-2 sm:col-span-3">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Salvar unidade' : 'Cadastrar unidade' }}</x-ui.button>
                <x-ui.button variant="secondary" wire:click="cancel">Cancelar</x-ui.button>
            </div>
        </form>
    @endif

    <div class="overflow-x-auto pb-2">
        @if ($units->isEmpty())
            <p class="px-6 py-5 text-sm text-slate-600">Nenhuma unidade cadastrada.</p>
        @else
            <table class="fv-table mt-4">
                <thead><tr><th scope="col">Código</th><th scope="col">Nome</th><th scope="col">Situação</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($units as $unit)
                        <tr wire:key="un-{{ $unit->id }}">
                            <td class="font-mono">{{ $unit->code }}</td>
                            <td>{{ $unit->name }}</td>
                            <td><x-ui.badge :color="$unit->is_active ? 'green' : 'slate'">{{ $unit->is_active ? 'Ativa' : 'Inativa' }}</x-ui.badge></td>
                            <td class="text-right">
                                @if ($canManage)
                                    <x-ui.button variant="secondary" wire:click="edit('{{ $unit->id }}')">Editar<span class="sr-only"> {{ $unit->name }}</span></x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</section>

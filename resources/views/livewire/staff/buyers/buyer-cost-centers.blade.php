<section class="fv-card" aria-labelledby="cost-centers-title">
    <div class="flex items-center justify-between gap-3 px-6 pt-5">
        <h2 id="cost-centers-title" class="text-lg font-semibold">Centros de custo</h2>
        @if ($canManage && ! $formOpen)
            <x-ui.button variant="secondary" wire:click="create">Adicionar centro de custo</x-ui.button>
        @endif
    </div>

    @if ($message)
        <div class="px-6 pt-3"><x-ui.alert type="success">{{ $message }}</x-ui.alert></div>
    @endif

    @if ($canManage && $formOpen)
        <form wire:submit="save" class="mx-6 mt-4 grid gap-3 rounded-md border border-slate-200 p-4 sm:grid-cols-3" novalidate aria-label="{{ $editingId ? 'Editar centro de custo' : 'Novo centro de custo' }}">
            <x-ui.input label="Código" name="code" id="cost-center-code" wire:model="code" required maxlength="30" />
            <div class="sm:col-span-2"><x-ui.input label="Nome" name="name" id="cost-center-name" wire:model="name" required maxlength="120" /></div>
            <div class="sm:col-span-2"><x-ui.select label="Unidade" name="buyerUnitId" id="cost-center-unit" wire:model="buyerUnitId" placeholder="Nenhuma (empresa toda)" :options="$units" /></div>
            <label class="flex items-center gap-2 self-end text-sm text-slate-700">
                <input type="checkbox" wire:model="isActive" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600"> Ativo
            </label>
            <div class="flex gap-2 sm:col-span-3">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Salvar centro de custo' : 'Cadastrar centro de custo' }}</x-ui.button>
                <x-ui.button variant="secondary" wire:click="cancel">Cancelar</x-ui.button>
            </div>
        </form>
    @endif

    <div class="overflow-x-auto pb-2">
        @if ($costCenters->isEmpty())
            <p class="px-6 py-5 text-sm text-slate-600">Nenhum centro de custo cadastrado.</p>
        @else
            <table class="fv-table mt-4">
                <thead><tr><th scope="col">Código</th><th scope="col">Nome</th><th scope="col">Unidade</th><th scope="col">Situação</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($costCenters as $costCenter)
                        <tr wire:key="cc-{{ $costCenter->id }}">
                            <td class="font-mono">{{ $costCenter->code }}</td>
                            <td>{{ $costCenter->name }}</td>
                            <td>{{ $costCenter->unit?->name ?? '—' }}</td>
                            <td><x-ui.badge :color="$costCenter->is_active ? 'green' : 'slate'">{{ $costCenter->is_active ? 'Ativo' : 'Inativo' }}</x-ui.badge></td>
                            <td class="text-right">
                                @if ($canManage)
                                    <x-ui.button variant="secondary" wire:click="edit('{{ $costCenter->id }}')">Editar<span class="sr-only"> {{ $costCenter->name }}</span></x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</section>

<div>
    <x-ui.page-header title="Filiais" description="Unidades do distribuidor. O limite de filiais depende do plano contratado." />

    @if ($message)
        <x-ui.alert type="success" class="mb-4">{{ $message }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="fv-card overflow-x-auto lg:col-span-2" aria-labelledby="branches-title">
            <h2 id="branches-title" class="sr-only">Lista de filiais</h2>
            <table class="fv-table">
                <thead><tr><th scope="col">Código</th><th scope="col">Nome</th><th scope="col">Cidade</th><th scope="col">Situação</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($branches as $branch)
                        <tr wire:key="b-{{ $branch->id }}">
                            <td class="font-mono">{{ $branch->code }}</td>
                            <td>{{ $branch->name }} @if ($branch->is_headquarters)<x-ui.badge color="brand">Matriz</x-ui.badge>@endif</td>
                            <td>{{ $branch->city }}{{ $branch->state ? ' / '.$branch->state : '' }}</td>
                            <td><x-ui.badge :color="$branch->is_active ? 'green' : 'slate'">{{ $branch->is_active ? 'Ativa' : 'Inativa' }}</x-ui.badge></td>
                            <td class="text-right"><x-ui.button variant="secondary" wire:click="edit('{{ $branch->id }}')">Editar</x-ui.button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="fv-card p-6" aria-labelledby="branch-form-title">
            <h2 id="branch-form-title" class="text-lg font-semibold">{{ $editingId ? 'Editar filial' : 'Nova filial' }}</h2>
            <form wire:submit="save" class="mt-4 space-y-3" novalidate>
                <x-ui.input label="Código" name="code" wire:model="code" required />
                <x-ui.input label="Nome" name="name" wire:model="name" required />
                <x-ui.input label="CNPJ da filial" name="document" wire:model="document" inputmode="numeric" />
                <div class="grid grid-cols-3 gap-3">
                    <div class="col-span-2"><x-ui.input label="Cidade" name="city" wire:model="city" /></div>
                    <x-ui.input label="UF" name="state" wire:model="state" maxlength="2" />
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="isActive" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600"> Ativa
                </label>
                <div class="flex gap-2">
                    <x-ui.button type="submit" wire:loading.attr="disabled">Salvar</x-ui.button>
                    @if ($editingId)
                        <x-ui.button variant="secondary" wire:click="cancel">Cancelar</x-ui.button>
                    @endif
                </div>
            </form>
        </section>
    </div>
</div>

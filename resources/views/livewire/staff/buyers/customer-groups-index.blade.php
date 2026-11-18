<div>
    <nav aria-label="Trilha de navegação" class="mb-3 text-sm">
        <a href="{{ route('buyers.index') }}" class="text-brand-700 hover:underline">Empresas compradoras</a>
        <span class="text-slate-400" aria-hidden="true">/</span>
        <span class="text-slate-600" aria-current="page">Grupos de clientes</span>
    </nav>

    <x-ui.page-header title="Grupos de clientes" description="Segmentação das empresas compradoras para tabelas de preço e catálogos privados." />

    @if ($message)
        <x-ui.alert type="success" class="mb-4">{{ $message }}</x-ui.alert>
    @endif
    @if ($failure)
        <x-ui.alert type="error" class="mb-4">{{ $failure }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="fv-card overflow-x-auto {{ $canManage ? 'lg:col-span-2' : 'lg:col-span-3' }}" aria-labelledby="groups-title">
            <h2 id="groups-title" class="sr-only">Lista de grupos</h2>
            <table class="fv-table">
                <thead><tr><th scope="col">Código</th><th scope="col">Nome</th><th scope="col">Empresas</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($groups as $group)
                        <tr wire:key="cg-{{ $group->id }}">
                            <td class="font-mono">{{ $group->code }}</td>
                            <td>
                                <span class="font-medium">{{ $group->name }}</span>
                                @if ($group->description)<br><span class="text-xs text-slate-500">{{ $group->description }}</span>@endif
                            </td>
                            <td>{{ (int) $group->getAttribute('companies_count') }}</td>
                            <td class="whitespace-nowrap text-right">
                                @if ($canManage)
                                    <x-ui.button variant="secondary" wire:click="edit('{{ $group->id }}')">Editar<span class="sr-only"> {{ $group->name }}</span></x-ui.button>
                                    <x-ui.button variant="danger" wire:click="delete('{{ $group->id }}')" wire:confirm="Excluir este grupo de clientes?">Excluir<span class="sr-only"> {{ $group->name }}</span></x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-ui.empty-state title="Nenhum grupo de clientes cadastrado" description="Grupos permitem tabelas de preço e catálogos por segmento (ex.: construtoras, revendas)." /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="p-4">{{ $groups->links() }}</div>
        </section>

        @if ($canManage)
            <section class="fv-card p-6" aria-labelledby="group-form-title">
                <h2 id="group-form-title" class="text-lg font-semibold">{{ $editingId ? 'Editar grupo' : 'Novo grupo' }}</h2>
                <form wire:submit="save" class="mt-4 space-y-3" novalidate>
                    <x-ui.input label="Código" name="code" wire:model="code" required maxlength="30" hint="Único no distribuidor; gravado em maiúsculas." />
                    <x-ui.input label="Nome" name="name" wire:model="name" required maxlength="120" />
                    <x-ui.textarea label="Descrição" name="description" wire:model="description" rows="3" maxlength="1000"></x-ui.textarea>
                    <div class="flex gap-2">
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Salvar</x-ui.button>
                        @if ($editingId)
                            <x-ui.button variant="secondary" wire:click="cancel">Cancelar</x-ui.button>
                        @endif
                    </div>
                </form>
            </section>
        @endif
    </div>
</div>

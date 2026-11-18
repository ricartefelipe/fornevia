<div>
    <x-ui.page-header title="Categorias" description="Organização hierárquica do catálogo. Uma categoria não pode ficar abaixo de si mesma nem de suas subcategorias." />

    @include('livewire.staff.catalog.partials.nav')

    @if ($message)
        <x-ui.alert type="success" class="mb-4">{{ $message }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section @class(['fv-card overflow-x-auto', 'lg:col-span-2' => $canManage, 'lg:col-span-3' => ! $canManage]) aria-labelledby="categories-title">
            <h2 id="categories-title" class="sr-only">Lista de categorias</h2>
            @if ($categories->isEmpty())
                <div class="p-4"><x-ui.empty-state title="Nenhuma categoria cadastrada" description="Categorias facilitam a busca no portal do comprador." /></div>
            @else
                <table class="fv-table">
                    <thead><tr><th scope="col">Nome</th><th scope="col">Categoria superior</th><th scope="col">Produtos</th><th scope="col">Ordem</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($categories as $category)
                            <tr wire:key="category-{{ $category->id }}" @class(['bg-brand-50' => $editingId === $category->id])>
                                <td><span class="font-medium">{{ $category->name }}</span><br><span class="font-mono text-xs text-slate-500">{{ $category->slug }}</span></td>
                                <td>{{ $category->parent?->name ?? '—' }}</td>
                                <td>{{ $category->products_count }}</td>
                                <td>{{ $category->sort_order }}</td>
                                <td class="text-right">
                                    @if ($canManage)
                                        <x-ui.button variant="secondary" wire:click="edit('{{ $category->id }}')">Editar<span class="sr-only"> {{ $category->name }}</span></x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-4 py-3">{{ $categories->links() }}</div>
            @endif
        </section>

        @if ($canManage)
            <section class="fv-card p-6" aria-labelledby="category-form-title">
                <h2 id="category-form-title" class="text-lg font-semibold">{{ $editingId ? 'Editar categoria' : 'Nova categoria' }}</h2>
                <form wire:submit="save" class="mt-4 space-y-3" novalidate>
                    <x-ui.input label="Nome" name="name" wire:model="name" required maxlength="120" />
                    <x-ui.select label="Categoria superior" name="parentId" wire:model="parentId" placeholder="Nenhuma (categoria principal)"
                        :options="$parents->pluck('name', 'id')->all()" />
                    <x-ui.input label="Ordem de exibição" name="sortOrder" type="number" min="0" max="100000" wire:model="sortOrder" />
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

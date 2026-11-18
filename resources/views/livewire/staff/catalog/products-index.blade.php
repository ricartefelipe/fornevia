<div>
    <x-ui.page-header title="Catálogo" description="Produtos, SKUs, embalagens e mídias oferecidos às empresas compradoras.">
        @if ($canManage)
            <x-slot:actions>
                <a href="{{ route('catalog.products.create') }}" class="fv-btn-primary">Novo produto</a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('livewire.staff.catalog.partials.nav')

    <section class="fv-card mb-4 p-4" aria-labelledby="product-filters-title">
        <h2 id="product-filters-title" class="sr-only">Busca e filtros</h2>
        <form wire:submit="applyFilters" class="grid gap-4 sm:grid-cols-4" role="search">
            <div class="sm:col-span-2">
                <x-ui.input label="Buscar" name="search" type="search" wire:model.live.debounce.400ms="search"
                    hint="Nome, marca ou código de SKU." autocomplete="off" maxlength="100" />
            </div>
            <x-ui.select label="Categoria" name="categoryId" wire:model.live="categoryId" placeholder="Todas"
                :options="$categories->pluck('name', 'id')->all()" />
            <x-ui.select label="Situação" name="status" wire:model.live="status" placeholder="Todas">
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </x-ui.select>
            @if ($filtered)
                <div class="sm:col-span-4">
                    <x-ui.button variant="secondary" wire:click="clearFilters">Limpar filtros</x-ui.button>
                </div>
            @endif
        </form>
    </section>

    <section class="fv-card overflow-x-auto" aria-labelledby="products-title" wire:loading.attr="aria-busy">
        <h2 id="products-title" class="sr-only">Produtos</h2>
        <p class="px-4 pt-3 text-sm text-slate-600" wire:loading.delay>Carregando produtos…</p>

        @if ($products->isEmpty())
            <div class="p-4">
                @if ($filtered)
                    <x-ui.empty-state title="Nenhum produto encontrado" description="Revise a busca ou limpe os filtros." />
                @else
                    <x-ui.empty-state title="Nenhum produto cadastrado" description="Cadastre o primeiro produto para começar a montar o catálogo.">
                        @if ($canManage)
                            <a href="{{ route('catalog.products.create') }}" class="fv-btn-primary">Novo produto</a>
                        @endif
                    </x-ui.empty-state>
                @endif
            </div>
        @else
            <table class="fv-table">
                <thead>
                    <tr>
                        <th scope="col">Produto</th>
                        <th scope="col">Categoria</th>
                        <th scope="col">Unidade base</th>
                        <th scope="col">SKUs</th>
                        <th scope="col">Visibilidade</th>
                        <th scope="col">Situação</th>
                        <th scope="col"><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($products as $product)
                        <tr wire:key="product-{{ $product->id }}">
                            <td>
                                <span class="font-medium">{{ $product->name }}</span>
                                @if ($product->brand)
                                    <br><span class="text-xs text-slate-500">{{ $product->brand }}</span>
                                @endif
                            </td>
                            <td>{{ $product->category?->name ?? '—' }}</td>
                            <td class="font-mono">{{ $product->baseUnit->code }}</td>
                            <td>{{ $product->skus_count }}</td>
                            <td>{{ $product->visibility->label() }}</td>
                            <td>
                                <x-ui.badge :color="match ($product->status->value) { 'active' => 'green', 'draft' => 'amber', 'archived' => 'slate', default => 'red' }">{{ $product->status->label() }}</x-ui.badge>
                            </td>
                            <td class="text-right">
                                <a href="{{ route('catalog.products.edit', $product) }}" class="fv-btn-secondary">
                                    {{ $canManage ? 'Editar' : 'Ver' }}<span class="sr-only"> {{ $product->name }}</span>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-4 py-3">{{ $products->links() }}</div>
        @endif
    </section>
</div>

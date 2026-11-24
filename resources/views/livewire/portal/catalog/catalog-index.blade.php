<div>
    <x-ui.page-header title="Catálogo" :description="'Produtos, condições de compra e preços disponíveis para '.$page->companyName.'.'" />

    @unless ($page->companyCanPurchase)
        <x-ui.alert type="warning" class="mb-4">
            Sua empresa está com a situação "{{ $page->companyStatusLabel }}" e não pode fazer novos pedidos no momento. Os preços exibidos são informativos; fale com o seu representante.
        </x-ui.alert>
    @endunless

    <section class="fv-card mb-6 p-4" role="search" aria-labelledby="catalogo-filtros">
        <h2 id="catalogo-filtros" class="sr-only">Filtrar produtos</h2>
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <x-ui.input label="Buscar por nome, marca ou código do SKU" name="search" type="search" maxlength="100" autocomplete="off"
                    hint="A lista é atualizada enquanto você digita." wire:model.live.debounce.400ms="search" />
            </div>
            <x-ui.select label="Categoria" name="category" placeholder="Todas as categorias" wire:model.live="category">
                @foreach ($page->categories as $option)
                    <option value="{{ $option->id }}" wire:key="c-{{ $option->id }}">{{ $option->label() }}</option>
                @endforeach
            </x-ui.select>
        </div>
    </section>

    <p wire:loading.delay class="mb-3 text-sm text-slate-600" role="status">Carregando produtos…</p>

    <div wire:loading.class="opacity-60">
        <p class="sr-only" role="status" aria-live="polite">
            {{ $page->products->total() === 1 ? '1 produto encontrado.' : $page->products->total().' produtos encontrados.' }}
        </p>

        @if ($page->products->isEmpty())
            <x-ui.empty-state
                :title="$page->filters->isFiltered() ? 'Nenhum produto encontrado' : 'Catálogo vazio'"
                :description="$page->filters->isFiltered() ? 'Revise a busca ou a categoria selecionada.' : 'Ainda não há produtos disponíveis para a sua empresa.'">
                @if ($page->filters->isFiltered())
                    <x-ui.button variant="secondary" wire:click="clearFilters">Limpar filtros</x-ui.button>
                @endif
            </x-ui.empty-state>
        @else
            <ul class="grid gap-4 xl:grid-cols-2" aria-label="Produtos">
                @foreach ($page->products as $product)
                    <li wire:key="p-{{ $product->id }}" class="fv-card flex flex-col p-4">
                        <article aria-labelledby="produto-{{ $product->id }}" class="flex flex-1 flex-col">
                            <div class="flex gap-4">
                                @if ($product->thumbnail)
                                    <img src="{{ route('portal.media.download', $product->thumbnail->id) }}" alt="" width="80" height="80" loading="lazy"
                                        class="h-20 w-20 shrink-0 rounded-xl border border-slate-200 object-cover">
                                @else
                                    <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-brand-50 to-brand-100 text-brand-400 ring-1 ring-brand-100" aria-hidden="true"><x-ui.icon name="photo" class="size-8" /></div>
                                @endif
                                <div class="min-w-0">
                                    <h2 id="produto-{{ $product->id }}" class="font-semibold text-slate-900">
                                        <a href="{{ route('portal.catalog.product', $product->id) }}" class="hover:underline">{{ $product->name }}</a>
                                    </h2>
                                    <p class="text-sm text-slate-600">
                                        {{ implode(' · ', array_filter([$product->brand, $product->category])) ?: 'Sem marca ou categoria informada' }}
                                    </p>
                                </div>
                            </div>

                            <div class="mt-3 overflow-x-auto">
                                <table class="fv-table">
                                    <caption class="sr-only">Variações de {{ $product->name }}</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col">SKU</th>
                                            <th scope="col">Variação e embalagem</th>
                                            <th scope="col">Compra</th>
                                            <th scope="col" class="text-right">Preço</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($product->skus as $sku)
                                            <tr wire:key="s-{{ $sku->id }}">
                                                <td class="font-mono text-xs">{{ $sku->code }}</td>
                                                <td>{{ $sku->description() ?: '—' }}</td>
                                                <td class="text-xs text-slate-700">{{ $sku->purchaseRuleLabel() }}</td>
                                                <td class="text-right">
                                                    @if ($sku->price)
                                                        <span class="font-semibold text-slate-900">{{ $sku->priceLabel() }}</span>
                                                        <span class="text-xs text-slate-600">/ {{ $sku->unitCode }}</span>
                                                        <span class="block text-xs text-slate-500">{{ $sku->priceSourceLabel() }} · para {{ $sku->quantity->format() }} {{ $sku->unitCode }}</span>
                                                    @else
                                                        <span class="text-sm text-slate-600">{{ $sku->priceLabel() }}</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-4">
                                <a href="{{ route('portal.catalog.product', $product->id) }}" class="fv-btn-secondary">
                                    Ver detalhes<span class="sr-only"> de {{ $product->name }}</span>
                                </a>
                            </div>
                        </article>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">{{ $page->products->links() }}</div>
        @endif
    </div>
</div>

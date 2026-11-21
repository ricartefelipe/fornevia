<div>
    <x-ui.page-header title="Catálogos privados" description="Produtos restritos ficam visíveis apenas às empresas e grupos de clientes atribuídos a um catálogo privado ativo." />

    @include('livewire.staff.catalog.partials.nav')

    @if ($message)
        <x-ui.alert type="success" class="mb-4">{{ $message }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section @class(['fv-card overflow-x-auto', 'lg:col-span-2' => $canManage, 'lg:col-span-3' => ! $canManage]) aria-labelledby="private-catalogs-title">
            <h2 id="private-catalogs-title" class="sr-only">Lista de catálogos privados</h2>
            @if ($catalogs->isEmpty())
                <div class="p-4"><x-ui.empty-state title="Nenhum catálogo privado" description="Crie um catálogo para liberar produtos restritos a clientes específicos." /></div>
            @else
                <table class="fv-table">
                    <thead><tr><th scope="col">Catálogo</th><th scope="col">Produtos</th><th scope="col">Atribuições</th><th scope="col">Situação</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($catalogs as $catalog)
                            <tr wire:key="private-catalog-{{ $catalog->id }}" @class(['bg-brand-50' => $selected?->id === $catalog->id])>
                                <td><span class="font-medium">{{ $catalog->name }}</span><br><span class="font-mono text-xs text-slate-500">{{ $catalog->code }}</span></td>
                                <td>{{ $catalog->products_count }}</td>
                                <td>{{ $catalog->assignments_count }}</td>
                                <td><x-ui.badge :color="$catalog->is_active ? 'green' : 'slate'">{{ $catalog->is_active ? 'Ativo' : 'Inativo' }}</x-ui.badge></td>
                                <td class="whitespace-nowrap text-right">
                                    <x-ui.button variant="secondary" wire:click="manage('{{ $catalog->id }}')">{{ $canManage ? 'Gerenciar' : 'Ver' }}<span class="sr-only"> {{ $catalog->name }}</span></x-ui.button>
                                    @if ($canManage)
                                        <x-ui.button variant="secondary" wire:click="edit('{{ $catalog->id }}')">Editar<span class="sr-only"> {{ $catalog->name }}</span></x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-4 py-3">{{ $catalogs->links() }}</div>
            @endif
        </section>

        @if ($canManage)
            <section class="fv-card p-6" aria-labelledby="private-catalog-form-title">
                <h2 id="private-catalog-form-title" class="text-lg font-semibold">{{ $editingId ? 'Editar catálogo' : 'Novo catálogo' }}</h2>
                <form wire:submit="save" class="mt-4 space-y-3" novalidate>
                    <x-ui.input label="Código" name="code" wire:model="code" required maxlength="30" hint="Ex.: REDE-HOSPITALAR." />
                    <x-ui.input label="Nome" name="name" wire:model="name" required maxlength="120" />
                    <x-ui.textarea label="Descrição" name="description" wire:model="description" rows="3" maxlength="2000" />
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="isActive" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600"> Ativo
                    </label>
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

    @if ($selected)
        <section class="fv-card mt-6 p-6" aria-labelledby="selected-catalog-title" wire:key="selected-{{ $selected->id }}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="selected-catalog-title" class="text-lg font-semibold">{{ $selected->name }}</h2>
                <x-ui.button variant="secondary" wire:click="closeManage">Fechar</x-ui.button>
            </div>
            @unless ($selected->is_active)
                <x-ui.alert type="warning" class="mt-3">Catálogo inativo: os produtos restritos dele não ficam visíveis aos compradores até a reativação.</x-ui.alert>
            @endunless

            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <div>
                    <h3 class="text-base font-semibold">Produtos do catálogo</h3>
                    @error('products')
                        <x-ui.alert type="error" class="mt-3">{{ $message }}</x-ui.alert>
                    @enderror
                    @if ($catalogProducts->isEmpty())
                        <p class="mt-3 text-sm text-slate-600">Nenhum produto incluído.</p>
                    @else
                        <ul class="mt-3 divide-y divide-slate-100 rounded-md border border-slate-200">
                            @foreach ($catalogProducts as $product)
                                <li class="flex items-center justify-between gap-3 px-3 py-2 text-sm" wire:key="catalog-product-{{ $product->id }}">
                                    <span>
                                        <span class="font-medium">{{ $product->name }}</span>
                                        <span class="text-xs text-slate-500">· {{ $product->visibility->label() }} · {{ $product->status->label() }}</span>
                                    </span>
                                    @if ($canManage)
                                        <x-ui.button variant="secondary" wire:click="removeProduct('{{ $product->id }}')" wire:loading.attr="disabled">Remover<span class="sr-only"> {{ $product->name }}</span></x-ui.button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <div class="mt-2">{{ $catalogProducts->links() }}</div>
                    @endif

                    @if ($canManage)
                        <div class="mt-4">
                            <x-ui.input label="Incluir produto" name="productSearch" type="search" wire:model.live.debounce.400ms="productSearch"
                                hint="Busque por nome, marca ou código de SKU. Restritos aparecem primeiro." autocomplete="off" maxlength="100" />
                            <p class="mt-1 text-xs text-slate-500" wire:loading wire:target="productSearch">Buscando…</p>
                            @if ($productSearch !== '' && $productResults->isEmpty())
                                <p class="mt-2 text-sm text-slate-600">Nenhum produto encontrado fora deste catálogo.</p>
                            @endif
                            <ul class="mt-2 space-y-1">
                                @foreach ($productResults as $result)
                                    <li class="flex items-center justify-between gap-3 text-sm" wire:key="product-result-{{ $result->id }}">
                                        <span>{{ $result->name }} <span class="text-xs text-slate-500">· {{ $result->visibility->label() }}</span></span>
                                        <x-ui.button variant="secondary" wire:click="addProduct('{{ $result->id }}')" wire:loading.attr="disabled">Incluir<span class="sr-only"> {{ $result->name }}</span></x-ui.button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                <div>
                    <h3 class="text-base font-semibold">Atribuições</h3>
                    @if ($assignments->isEmpty())
                        <p class="mt-3 text-sm text-slate-600">Nenhuma empresa ou grupo atribuído.</p>
                    @else
                        <ul class="mt-3 divide-y divide-slate-100 rounded-md border border-slate-200">
                            @foreach ($assignments as $assignment)
                                <li class="flex items-center justify-between gap-3 px-3 py-2 text-sm" wire:key="assignment-{{ $assignment->id }}">
                                    @if ($assignment->buyerCompany)
                                        <span><x-ui.badge color="blue">Empresa</x-ui.badge> {{ $assignment->buyerCompany->trade_name }}</span>
                                    @else
                                        <span><x-ui.badge color="brand">Grupo</x-ui.badge> {{ $assignment->customerGroup?->name }}</span>
                                    @endif
                                    @if ($canManage)
                                        <x-ui.button variant="secondary" wire:click="unassign('{{ $assignment->id }}')" wire:confirm="Remover esta atribuição?" wire:loading.attr="disabled">Remover</x-ui.button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($canManage)
                        <div class="mt-4 space-y-4">
                            <div>
                                <x-ui.input label="Atribuir a empresa compradora" name="companySearch" type="search" wire:model.live.debounce.400ms="companySearch"
                                    hint="Busque por nome fantasia, razão social ou CNPJ." autocomplete="off" maxlength="100" />
                                @if ($companySearch !== '' && $companyResults->isEmpty())
                                    <p class="mt-2 text-sm text-slate-600">Nenhuma empresa encontrada.</p>
                                @endif
                                <ul class="mt-2 space-y-1">
                                    @foreach ($companyResults as $company)
                                        <li class="flex items-center justify-between gap-3 text-sm" wire:key="company-result-{{ $company->id }}">
                                            <span>{{ $company->trade_name }} <span class="text-xs text-slate-500">· {{ $company->legal_name }}</span></span>
                                            <x-ui.button variant="secondary" wire:click="assignCompany('{{ $company->id }}')" wire:loading.attr="disabled">Atribuir<span class="sr-only"> {{ $company->trade_name }}</span></x-ui.button>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                            <form wire:submit="assignGroup" class="flex items-end gap-2" novalidate>
                                <div class="flex-1">
                                    <x-ui.select label="Atribuir a grupo de clientes" name="groupId" wire:model="groupId" placeholder="Selecione"
                                        :options="$groups->mapWithKeys(fn ($group) => [$group->id => $group->name.' ('.$group->code.')'])->all()" />
                                </div>
                                <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled">Atribuir</x-ui.button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @endif
</div>

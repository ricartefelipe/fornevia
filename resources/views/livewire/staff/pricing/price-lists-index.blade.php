<div>
    <x-ui.page-header title="Tabelas de preço"
        description="Tabelas padrão, por grupo de clientes e por empresa compradora. Precedência: condição negociada, tabela da empresa, do grupo e padrão." />

    @include('livewire.staff.pricing.partials.nav', ['current' => 'pricing.lists'])

    @if ($message)
        <x-ui.alert type="success" class="mb-4">{{ $message }}</x-ui.alert>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-4 xl:col-span-2">
            <section class="fv-card p-4" aria-labelledby="price-list-filters">
                <h2 id="price-list-filters" class="sr-only">Filtros</h2>
                <div class="grid gap-3 sm:grid-cols-4">
                    <div class="sm:col-span-2">
                        <x-ui.input label="Buscar por código ou nome" name="search" type="search" autocomplete="off"
                            wire:model.live.debounce.400ms="search" />
                    </div>
                    <x-ui.select label="Escopo" name="scopeFilter" wire:model.live="scopeFilter" placeholder="Todos"
                        :options="$scopeOptions" />
                    <x-ui.select label="Situação" name="statusFilter" wire:model.live="statusFilter" placeholder="Todas"
                        :options="$statusOptions" />
                </div>
                <p wire:loading.delay wire:target="search,scopeFilter,statusFilter,clearFilters" class="mt-2 text-xs text-slate-500" role="status">
                    Atualizando a lista…
                </p>
            </section>

            <section class="fv-card overflow-x-auto" aria-labelledby="price-lists-title">
                <h2 id="price-lists-title" class="px-6 pt-5 text-lg font-semibold">Tabelas cadastradas</h2>

                @if ($lists->isEmpty())
                    <div class="p-6">
                        <x-ui.empty-state title="Nenhuma tabela encontrada"
                            :description="$hasFilters ? 'Nenhuma tabela atende aos filtros informados.' : 'Cadastre a primeira tabela de preço do distribuidor.'">
                            @if ($hasFilters)
                                <x-ui.button variant="secondary" wire:click="clearFilters">Limpar filtros</x-ui.button>
                            @endif
                        </x-ui.empty-state>
                    </div>
                @else
                    <table class="fv-table mt-4">
                        <thead>
                            <tr>
                                <th scope="col">Código</th>
                                <th scope="col">Nome</th>
                                <th scope="col">Escopo</th>
                                <th scope="col">Prioridade</th>
                                <th scope="col">Vigência</th>
                                <th scope="col">Itens</th>
                                <th scope="col">Situação</th>
                                <th scope="col"><span class="sr-only">Ações</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($lists as $list)
                                <tr wire:key="pl-{{ $list->id }}">
                                    <td class="font-mono">{{ $list->code }}</td>
                                    <td>{{ $list->name }}</td>
                                    <td>
                                        {{ $list->scope->label() }}
                                        @if ($list->customerGroup)
                                            <span class="block text-xs text-slate-500">{{ $list->customerGroup->name }}</span>
                                        @elseif ($list->buyerCompany)
                                            <span class="block text-xs text-slate-500">{{ $list->buyerCompany->trade_name }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $list->priority }}</td>
                                    <td class="whitespace-nowrap">
                                        {{ $list->valid_from->format('d/m/Y') }} a {{ $list->valid_until?->format('d/m/Y') ?? 'sem término' }}
                                    </td>
                                    <td>{{ $list->items_count }}</td>
                                    <td>
                                        <x-ui.badge :color="match ($list->status->value) { 'active' => 'green', 'draft' => 'amber', default => 'slate' }">
                                            {{ $list->status->label() }}
                                        </x-ui.badge>
                                    </td>
                                    <td class="text-right">
                                        <a href="{{ route('pricing.lists.edit', ['priceList' => $list->id]) }}" class="fv-btn-secondary">
                                            Abrir<span class="sr-only"> a tabela {{ $list->code }}</span>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="px-6 py-4">{{ $lists->links() }}</div>
                @endif
            </section>
        </div>

        <div class="space-y-6">
            @if ($canManage)
                <section class="fv-card p-6" aria-labelledby="price-list-create">
                    <h2 id="price-list-create" class="text-lg font-semibold">Nova tabela</h2>
                    <p class="mt-1 text-sm text-slate-600">A tabela é criada como rascunho; ative-a depois de cadastrar os itens.</p>
                    <form wire:submit="create" class="mt-4 space-y-3" novalidate>
                        @include('livewire.staff.pricing.partials.header-fields')
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="create">Criar tabela</x-ui.button>
                    </form>
                </section>

                <section class="fv-card p-6" aria-labelledby="pricing-settings">
                    <h2 id="pricing-settings" class="text-lg font-semibold">Margem mínima padrão</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        Referência para a aprovação de descontos quando o SKU não define margem própria. Atual: {{ $currentMargin }}.
                    </p>
                    <form wire:submit="saveSettings" class="mt-4 space-y-3" novalidate>
                        <x-ui.input label="Margem mínima padrão (%)" name="minMargin" wire:model="minMargin" inputmode="decimal" maxlength="6" />
                        <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled" wire:target="saveSettings">Salvar margem</x-ui.button>
                    </form>
                </section>
            @else
                <x-ui.alert type="info">
                    Seu perfil permite consultar as tabelas. Criar e alterar tabelas exige a permissão de gerenciar tabelas de preço.
                </x-ui.alert>
            @endif
        </div>
    </div>
</div>

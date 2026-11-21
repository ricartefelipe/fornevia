<div>
    <x-ui.page-header :title="'Tabela '.$list->code" :description="$list->name">
        <x-slot:actions>
            <a href="{{ route('pricing.lists') }}" class="fv-btn-secondary">Voltar às tabelas</a>
        </x-slot:actions>
    </x-ui.page-header>

    @include('livewire.staff.pricing.partials.nav', ['current' => 'pricing.lists'])

    @if ($message)
        <x-ui.alert type="success" class="mb-4">{{ $message }}</x-ui.alert>
    @endif
    @error('status')
        <x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <section class="fv-card mb-6 p-6" aria-labelledby="price-list-status">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h2 id="price-list-status" class="text-lg font-semibold">Situação</h2>
                <p class="mt-1 text-sm text-slate-700">
                    <x-ui.badge :color="match ($list->status->value) { 'active' => 'green', 'draft' => 'amber', default => 'slate' }">{{ $list->status->label() }}</x-ui.badge>
                    <span class="ml-2">{{ $list->scope->label() }}{{ $list->customerGroup ? ' · '.$list->customerGroup->name : '' }}{{ $list->buyerCompany ? ' · '.$list->buyerCompany->trade_name : '' }}</span>
                    <span class="ml-2 text-slate-500">Vigência {{ $list->valid_from->format('d/m/Y') }} a {{ $list->valid_until?->format('d/m/Y') ?? 'sem término' }} · prioridade {{ $list->priority }}</span>
                </p>
                @if ($list->status->value === 'archived')
                    <p class="mt-2 text-sm text-slate-600">Tabela arquivada: somente consulta.</p>
                @elseif ($list->status->value === 'draft')
                    <p class="mt-2 text-sm text-slate-600">Rascunho: os preços só passam a valer depois da ativação.</p>
                @endif
            </div>

            @if ($editable)
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    @if ($list->status->value === 'draft')
                        <x-ui.button wire:click="activate" wire:loading.attr="disabled" wire:target="activate"
                            wire:confirm="Ativar a tabela? Os preços passam a valer na vigência informada.">Ativar tabela</x-ui.button>
                    @endif
                    <div class="sm:w-64">
                        <x-ui.input label="Motivo do arquivamento" name="archiveReason" wire:model="archiveReason" maxlength="500" hint="Opcional; registrado na auditoria." />
                    </div>
                    <x-ui.button variant="danger" wire:click="archive" wire:loading.attr="disabled" wire:target="archive"
                        wire:confirm="Arquivar a tabela? Ela deixa de participar do cálculo de preços e não poderá ser reativada.">Arquivar</x-ui.button>
                </div>
            @endif
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-4 xl:col-span-2">
            <section class="fv-card overflow-x-auto" aria-labelledby="price-list-items">
                <div class="flex flex-col gap-3 px-6 pt-5 sm:flex-row sm:items-end sm:justify-between">
                    <h2 id="price-list-items" class="text-lg font-semibold">Itens ({{ $items->total() }})</h2>
                    <div class="sm:w-72">
                        <x-ui.input label="Filtrar itens por código ou nome" name="itemSearch" type="search" autocomplete="off"
                            wire:model.live.debounce.400ms="itemSearch" />
                    </div>
                </div>
                <p wire:loading.delay wire:target="itemSearch" class="px-6 pt-2 text-xs text-slate-500" role="status">Atualizando os itens…</p>

                @if ($items->isEmpty())
                    <div class="p-6">
                        <x-ui.empty-state title="Nenhum item"
                            :description="trim($itemSearch) !== '' ? 'Nenhum item corresponde ao filtro.' : 'Use o formulário ao lado para incluir SKUs e preços.'" />
                    </div>
                @else
                    <table class="fv-table mt-4">
                        <thead>
                            <tr>
                                <th scope="col">SKU</th>
                                <th scope="col">Produto</th>
                                <th scope="col">Preço unitário</th>
                                <th scope="col">Faixas por volume</th>
                                <th scope="col"><span class="sr-only">Ações</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($items as $item)
                                <tr wire:key="item-{{ $item->id }}">
                                    <td class="font-mono">{{ $item->sku->code }}</td>
                                    <td>{{ \App\Livewire\Staff\Pricing\PriceListEdit::skuLabel($item->sku) }}
                                        <span class="block text-xs text-slate-500">Unidade: {{ $item->sku->salesUnit->code }}</span></td>
                                    <td class="whitespace-nowrap">{{ $item->unit_price_cents->format() }}</td>
                                    <td class="text-xs">
                                        @forelse ($item->tiers as $tier)
                                            <span class="block whitespace-nowrap">A partir de {{ $tier->min_quantity->format() }} {{ $item->sku->salesUnit->code }}: {{ $tier->unit_price_cents->format() }}</span>
                                        @empty
                                            <span class="text-slate-500">Sem faixas</span>
                                        @endforelse
                                    </td>
                                    <td class="whitespace-nowrap text-right">
                                        @if ($editable)
                                            <x-ui.button variant="secondary" wire:click="editItem('{{ $item->id }}')">
                                                Editar<span class="sr-only"> o preço do SKU {{ $item->sku->code }}</span>
                                            </x-ui.button>
                                            <x-ui.button variant="danger" wire:click="removeItem('{{ $item->id }}')"
                                                wire:confirm="Remover este SKU da tabela?">
                                                Remover<span class="sr-only"> o SKU {{ $item->sku->code }}</span>
                                            </x-ui.button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="px-6 py-4">{{ $items->links() }}</div>
                @endif
            </section>
        </div>

        <div class="space-y-6">
            @if ($editable)
                <section class="fv-card p-6" aria-labelledby="price-list-item-form">
                    <h2 id="price-list-item-form" class="text-lg font-semibold">{{ $selectedSku ? 'Preço do SKU' : 'Incluir SKU' }}</h2>

                    @if ($selectedSku)
                        <div class="mt-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                            <p class="font-mono font-semibold">{{ $selectedSku->code }}</p>
                            <p>{{ \App\Livewire\Staff\Pricing\PriceListEdit::skuLabel($selectedSku) }} · unidade {{ $selectedSku->salesUnit->code }}</p>
                        </div>

                        <form wire:submit="saveItem" class="mt-4 space-y-3" novalidate>
                            <x-ui.input label="Preço unitário (R$)" name="unitPrice" wire:model="unitPrice" inputmode="decimal" maxlength="18" required
                                hint="Preço base, sem faixa atingida." />

                            <fieldset class="space-y-2">
                                <legend class="fv-label">Faixas por volume</legend>
                                <p class="text-xs text-slate-600">A faixa atingida pela quantidade da linha define o preço de todas as unidades. Mínimos em ordem crescente.</p>
                                @error('tiers')
                                    <p class="text-xs text-red-700" role="alert">{{ $message }}</p>
                                @enderror
                                @foreach ($tiers as $index => $tier)
                                    <div class="grid grid-cols-5 items-end gap-2" wire:key="tier-{{ $index }}">
                                        <div class="col-span-2">
                                            <x-ui.input :label="'A partir de (faixa '.($index + 1).')'" name="tiers.{{ $index }}.min" wire:model="tiers.{{ $index }}.min" inputmode="decimal" maxlength="18" />
                                        </div>
                                        <div class="col-span-2">
                                            <x-ui.input :label="'Preço (faixa '.($index + 1).')'" name="tiers.{{ $index }}.price" wire:model="tiers.{{ $index }}.price" inputmode="decimal" maxlength="18" />
                                        </div>
                                        <x-ui.button variant="secondary" wire:click="removeTier({{ $index }})">
                                            <span aria-hidden="true">×</span><span class="sr-only">Remover a faixa {{ $index + 1 }}</span>
                                        </x-ui.button>
                                    </div>
                                @endforeach
                                @if (count($tiers) < $maxTiers)
                                    <x-ui.button variant="secondary" wire:click="addTier">Adicionar faixa</x-ui.button>
                                @endif
                            </fieldset>

                            <div class="flex gap-2">
                                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveItem">Salvar preço</x-ui.button>
                                <x-ui.button variant="secondary" wire:click="cancelItem">Cancelar</x-ui.button>
                            </div>
                        </form>
                    @else
                        <div class="mt-4 space-y-3">
                            <x-ui.input label="Buscar SKU por código ou nome" name="skuSearch" type="search" autocomplete="off"
                                wire:model.live.debounce.400ms="skuSearch" hint="Digite ao menos 2 caracteres." />
                            <p wire:loading.delay wire:target="skuSearch" class="text-xs text-slate-500" role="status">Buscando SKUs…</p>
                            @if (mb_strlen(trim($skuSearch)) >= 2)
                                @if ($skuResults->isEmpty())
                                    <p class="text-sm text-slate-600" role="status">Nenhum SKU encontrado.</p>
                                @else
                                    <ul class="divide-y divide-slate-100 rounded-md border border-slate-200" aria-label="SKUs encontrados">
                                        @foreach ($skuResults as $sku)
                                            <li class="flex items-center justify-between gap-2 px-3 py-2 text-sm" wire:key="sku-{{ $sku->id }}">
                                                <span><span class="font-mono">{{ $sku->code }}</span> · {{ \App\Livewire\Staff\Pricing\PriceListEdit::skuLabel($sku) }}</span>
                                                <x-ui.button variant="secondary" wire:click="selectSku('{{ $sku->id }}')">
                                                    Selecionar<span class="sr-only"> o SKU {{ $sku->code }}</span>
                                                </x-ui.button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            @endif
                        </div>
                    @endif
                </section>

                <section class="fv-card p-6" aria-labelledby="price-list-header-form">
                    <h2 id="price-list-header-form" class="text-lg font-semibold">Cabeçalho</h2>
                    <form wire:submit="saveHeader" class="mt-4 space-y-3" novalidate>
                        @include('livewire.staff.pricing.partials.header-fields')
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveHeader">Salvar cabeçalho</x-ui.button>
                    </form>
                </section>
            @elseif (! $canManage)
                <x-ui.alert type="info">Seu perfil permite consultar a tabela. Alterações exigem a permissão de gerenciar tabelas de preço.</x-ui.alert>
            @endif
        </div>
    </div>
</div>

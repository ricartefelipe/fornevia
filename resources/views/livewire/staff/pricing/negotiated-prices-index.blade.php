<div>
    <x-ui.page-header title="Condições negociadas"
        description="Preços contratados por empresa e SKU. Têm precedência sobre todas as tabelas; vigências ativas do mesmo par empresa e SKU não podem se sobrepor." />

    @include('livewire.staff.pricing.partials.nav', ['current' => 'pricing.negotiated'])

    @if ($message)
        <x-ui.alert type="success" class="mb-4">{{ $message }}</x-ui.alert>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-4 xl:col-span-2">
            <section class="fv-card p-4" aria-labelledby="negotiated-filters">
                <h2 id="negotiated-filters" class="sr-only">Filtros</h2>
                <div class="grid gap-3 sm:grid-cols-3">
                    <x-ui.input label="Buscar empresa" name="filterSearch" type="search" autocomplete="off"
                        wire:model.live.debounce.400ms="filterSearch" />
                    <x-ui.select label="Empresa" name="companyFilter" wire:model.live="companyFilter" placeholder="Todas as empresas"
                        :options="$filterOptions" />
                    <x-ui.select label="Situação" name="statusFilter" wire:model.live="statusFilter" :options="$statusOptions" />
                </div>
                <p wire:loading.delay wire:target="filterSearch,companyFilter,statusFilter" class="mt-2 text-xs text-slate-500" role="status">Atualizando a lista…</p>
            </section>

            <section class="fv-card overflow-x-auto" aria-labelledby="negotiated-list">
                <h2 id="negotiated-list" class="px-6 pt-5 text-lg font-semibold">Condições</h2>

                @if ($canManage && $prices->isNotEmpty())
                    <div class="px-6 pt-3">
                        <x-ui.input label="Justificativa para revogação" name="revokeReason" wire:model="revokeReason" maxlength="500"
                            hint="Obrigatória para revogar; registrada na auditoria." />
                    </div>
                @endif

                @if ($prices->isEmpty())
                    <div class="p-6">
                        <x-ui.empty-state title="Nenhuma condição negociada"
                            :description="$hasFilters ? 'Nenhuma condição atende aos filtros informados.' : 'Ainda não há condições negociadas ativas.'" />
                    </div>
                @else
                    <table class="fv-table mt-4">
                        <thead>
                            <tr>
                                <th scope="col">Empresa</th>
                                <th scope="col">SKU</th>
                                <th scope="col">Preço</th>
                                <th scope="col">Vigência</th>
                                <th scope="col">Aprovação</th>
                                <th scope="col">Situação</th>
                                <th scope="col"><span class="sr-only">Ações</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($prices as $price)
                                <tr wire:key="np-{{ $price->id }}">
                                    <td>{{ $price->buyerCompany->trade_name }}</td>
                                    <td>
                                        <span class="font-mono">{{ $price->sku->code }}</span>
                                        <span class="block text-xs text-slate-500">{{ $price->sku->product->name }}</span>
                                    </td>
                                    <td class="whitespace-nowrap">{{ $price->unit_price_cents->format() }}</td>
                                    <td class="whitespace-nowrap">{{ $price->valid_from->format('d/m/Y') }} a {{ $price->valid_until?->format('d/m/Y') ?? 'sem término' }}</td>
                                    <td class="text-xs">
                                        {{ $approvers[$price->approved_by_user_id] ?? 'Não informado' }}
                                        @if ($price->notes)
                                            <span class="block text-slate-500">{{ $price->notes }}</span>
                                        @endif
                                    </td>
                                    <td><x-ui.badge :color="$price->status->value === 'active' ? 'green' : 'slate'">{{ $price->status->label() }}</x-ui.badge></td>
                                    <td class="text-right">
                                        @if ($canManage && $price->status->value === 'active')
                                            <x-ui.button variant="danger" wire:click="revoke('{{ $price->id }}')"
                                                wire:confirm="Revogar esta condição negociada?">
                                                Revogar<span class="sr-only"> a condição do SKU {{ $price->sku->code }}</span>
                                            </x-ui.button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="px-6 py-4">{{ $prices->links() }}</div>
                @endif
            </section>
        </div>

        <div>
            @if ($canManage)
                <section class="fv-card p-6" aria-labelledby="negotiated-create">
                    <h2 id="negotiated-create" class="text-lg font-semibold">Nova condição negociada</h2>
                    <p class="mt-1 text-sm text-slate-600">Você fica registrado como aprovador da condição.</p>
                    <form wire:submit="create" class="mt-4 space-y-3" novalidate>
                        <x-ui.input label="Buscar empresa compradora" name="companySearch" type="search" autocomplete="off"
                            wire:model.live.debounce.400ms="companySearch" hint="Nome fantasia, razão social ou CNPJ." />
                        <x-ui.select label="Empresa compradora" name="buyerCompanyId" wire:model="buyerCompanyId" placeholder="Selecione"
                            :options="$companyOptions" />

                        @if ($selectedSku)
                            <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                                <p class="font-mono font-semibold">{{ $selectedSku->code }}</p>
                                <p>{{ \App\Livewire\Staff\Pricing\PriceListEdit::skuLabel($selectedSku) }} · unidade {{ $selectedSku->salesUnit->code }}</p>
                                <x-ui.button variant="secondary" class="mt-2" wire:click="clearSku">Trocar SKU</x-ui.button>
                            </div>
                        @else
                            <x-ui.input label="Buscar SKU por código ou nome" name="skuSearch" type="search" autocomplete="off"
                                wire:model.live.debounce.400ms="skuSearch" hint="Digite ao menos 2 caracteres." />
                            <p wire:loading.delay wire:target="skuSearch" class="text-xs text-slate-500" role="status">Buscando SKUs…</p>
                            @if (mb_strlen(trim($skuSearch)) >= 2)
                                @if ($skuResults->isEmpty())
                                    <p class="text-sm text-slate-600" role="status">Nenhum SKU encontrado.</p>
                                @else
                                    <ul class="divide-y divide-slate-100 rounded-md border border-slate-200" aria-label="SKUs encontrados">
                                        @foreach ($skuResults as $sku)
                                            <li class="flex items-center justify-between gap-2 px-3 py-2 text-sm" wire:key="nsku-{{ $sku->id }}">
                                                <span><span class="font-mono">{{ $sku->code }}</span> · {{ \App\Livewire\Staff\Pricing\PriceListEdit::skuLabel($sku) }}</span>
                                                <x-ui.button variant="secondary" wire:click="selectSku('{{ $sku->id }}')">
                                                    Selecionar<span class="sr-only"> o SKU {{ $sku->code }}</span>
                                                </x-ui.button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            @endif
                        @endif

                        <x-ui.input label="Preço negociado (R$)" name="unitPrice" wire:model="unitPrice" inputmode="decimal" maxlength="18" required />
                        <div class="grid grid-cols-2 gap-3">
                            <x-ui.input label="Início da vigência" name="validFrom" type="date" wire:model="validFrom" required />
                            <x-ui.input label="Término da vigência" name="validUntil" type="date" wire:model="validUntil" hint="Vazio: sem término." />
                        </div>
                        <x-ui.textarea label="Observação" name="notes" wire:model="notes" rows="3" maxlength="1000" />
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="create">Registrar condição</x-ui.button>
                    </form>
                </section>
            @else
                <x-ui.alert type="info">Seu perfil permite consultar as condições. Registrar e revogar exige a permissão de gerenciar tabelas de preço.</x-ui.alert>
            @endif
        </div>
    </div>
</div>

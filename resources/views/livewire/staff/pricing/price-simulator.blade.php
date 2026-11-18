<div>
    <x-ui.page-header title="Simulador de preços"
        description="Calcula o preço como o portal e os documentos comerciais calculam, e mostra todos os candidatos considerados na ordem de precedência." />

    @include('livewire.staff.pricing.partials.nav', ['current' => 'pricing.simulator'])

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="fv-card p-6" aria-labelledby="simulator-form">
            <h2 id="simulator-form" class="text-lg font-semibold">Parâmetros</h2>
            <form wire:submit="simulate" class="mt-4 space-y-3" novalidate>
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
                                    <li class="flex items-center justify-between gap-2 px-3 py-2 text-sm" wire:key="ssku-{{ $sku->id }}">
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

                <div class="grid grid-cols-2 gap-3">
                    <x-ui.input label="Quantidade" name="quantity" wire:model="quantity" inputmode="decimal" maxlength="18" required
                        hint="Na unidade de venda." />
                    <x-ui.input label="Data" name="date" type="date" wire:model="date" required />
                </div>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="simulate">Simular</x-ui.button>
                <p wire:loading.delay wire:target="simulate" class="text-xs text-slate-500" role="status">Calculando…</p>
            </form>
        </section>

        <section class="space-y-4 xl:col-span-2" aria-labelledby="simulator-result" aria-live="polite">
            <h2 id="simulator-result" class="sr-only">Resultado da simulação</h2>

            @if ($result === null)
                <x-ui.empty-state title="Nenhuma simulação"
                    description="Escolha a empresa, o SKU, a quantidade e a data para ver o preço resolvido e a explicação da precedência." />
            @else
                <div class="fv-card p-6">
                    <p class="text-sm text-slate-600">{{ $result['buyer_company'] }} · {{ $result['sku'] }} · {{ $result['quantity'] }} unidade(s) em {{ $result['date'] }}</p>
                    @if ($result['available'])
                        <dl class="mt-4 grid gap-4 sm:grid-cols-4">
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Preço unitário</dt>
                                <dd class="mt-1 text-2xl font-semibold text-slate-900">{{ $result['unit_price'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Origem</dt>
                                <dd class="mt-1 text-sm text-slate-900">{{ $result['source_label'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Faixa aplicada</dt>
                                <dd class="mt-1 text-sm text-slate-900">{{ $result['tier'] !== null ? 'A partir de '.$result['tier'] : 'Preço base' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total da linha</dt>
                                <dd class="mt-1 text-sm text-slate-900">{{ $result['line_total'] }}</dd>
                            </div>
                        </dl>
                        <p class="mt-3 text-xs text-slate-500">Versão de preços {{ $result['price_version'] }}. A faixa atingida vale para todas as unidades da linha.</p>
                    @else
                        <x-ui.alert type="warning" class="mt-4">Não há preço vigente para esta empresa, este SKU e esta data.</x-ui.alert>
                    @endif
                </div>

                <div class="fv-card overflow-x-auto">
                    <h3 class="px-6 pt-5 text-base font-semibold">Candidatos considerados</h3>
                    <p class="px-6 pt-1 text-xs text-slate-600">
                        Ordem de precedência: condição negociada, tabela da empresa, do grupo e padrão; no mesmo nível, maior prioridade,
                        início de vigência mais recente e, por fim, a tabela cadastrada primeiro.
                    </p>
                    @if ($result['candidates'] === [])
                        <div class="p-6"><x-ui.empty-state title="Nenhum candidato vigente" description="Nenhuma tabela ativa ou condição negociada cobre este SKU na data." /></div>
                    @else
                        <table class="fv-table mt-4">
                            <thead>
                                <tr>
                                    <th scope="col">Ordem</th>
                                    <th scope="col">Fonte</th>
                                    <th scope="col">Preço base</th>
                                    <th scope="col">Preço para a quantidade</th>
                                    <th scope="col">Prioridade</th>
                                    <th scope="col">Vigência</th>
                                    <th scope="col">Explicação</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($result['candidates'] as $candidate)
                                    <tr wire:key="cand-{{ $candidate['source'] }}-{{ $candidate['reference_id'] }}">
                                        <td>
                                            {{ $candidate['position'] }}º
                                            @if ($candidate['winner'])
                                                <x-ui.badge color="green">Vencedor</x-ui.badge>
                                            @endif
                                        </td>
                                        <td>
                                            {{ $candidate['source_label'] }}
                                            <span class="block text-xs text-slate-500">{{ $candidate['description'] }}</span>
                                        </td>
                                        <td class="whitespace-nowrap">{{ $candidate['base_unit_price'] }}</td>
                                        <td class="whitespace-nowrap">
                                            {{ $candidate['unit_price'] }}
                                            @if ($candidate['applied_tier'] !== null)
                                                <span class="block text-xs text-slate-500">faixa a partir de {{ $candidate['applied_tier'] }}</span>
                                            @endif
                                            @foreach ($candidate['tiers'] as $tier)
                                                <span class="block text-xs text-slate-400">{{ $tier['min_quantity'] }}+: {{ $tier['unit_price'] }}</span>
                                            @endforeach
                                        </td>
                                        <td>{{ $candidate['source'] === 'negotiated' ? '—' : $candidate['priority'] }}</td>
                                        <td class="whitespace-nowrap">{{ $candidate['valid_from'] }} a {{ $candidate['valid_until'] ?? 'sem término' }}</td>
                                        <td class="text-xs">{{ $candidate['explanation'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @endif
        </section>
    </div>
</div>

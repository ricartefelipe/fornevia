<div>
    <x-ui.page-header title="Crédito comercial"
        description="Limite de venda a prazo por empresa compradora. Disponível = limite + exceções vigentes − exposição (pedidos confirmados não faturados + faturas em aberto)." />

    <section class="fv-card mb-4 p-4" aria-labelledby="credit-filters-title">
        <h2 id="credit-filters-title" class="sr-only">Filtros</h2>
        <div class="grid gap-4 sm:grid-cols-4" role="search">
            <div class="sm:col-span-2">
                <x-ui.input label="Buscar por nome ou CNPJ" name="search" type="search" wire:model.live.debounce.400ms="search" maxlength="100" />
            </div>
            <x-ui.select label="Situação do crédito" name="situation" wire:model.live="situation" placeholder="Todas" :options="$situations" />
            <x-ui.input label="Disponível abaixo de (R$)" name="availableBelow" inputmode="decimal" maxlength="25"
                wire:model.live.debounce.600ms="availableBelow" hint="Somente empresas com conta aberta." />
        </div>
        @if ($filtered)
            <div class="mt-3">
                <x-ui.button variant="secondary" wire:click="clearFilters">Limpar filtros</x-ui.button>
            </div>
        @endif
    </section>

    <section class="fv-card overflow-x-auto" aria-labelledby="credit-accounts-title">
        <h2 id="credit-accounts-title" class="sr-only">Contas de crédito por empresa compradora</h2>
        <p class="px-4 pt-3 text-sm text-slate-500" wire:loading wire:target="search,situation,availableBelow,clearFilters,gotoPage,nextPage,previousPage" role="status">Carregando…</p>
        <table class="fv-table">
            <thead>
                <tr>
                    <th scope="col">Empresa</th>
                    <th scope="col" class="text-right">Limite</th>
                    <th scope="col" class="text-right">Exceções vigentes</th>
                    <th scope="col" class="text-right">Comprometido</th>
                    <th scope="col" class="text-right">Recebível</th>
                    <th scope="col" class="text-right">Exposição</th>
                    <th scope="col" class="text-right">Disponível</th>
                    <th scope="col">Situação</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($accounts as $row)
                    <tr wire:key="credit-{{ $row->buyerCompanyId }}">
                        <td>
                            <span class="font-medium">{{ $row->tradeName }}</span><br>
                            <span class="text-xs text-slate-500">{{ $row->legalName }} · <span class="font-mono">{{ \App\Domain\Shared\Cnpj::format($row->document) }}</span></span>
                        </td>
                        @if ($row->position)
                            <td class="whitespace-nowrap text-right">{{ $row->position->limit->format() }}</td>
                            <td class="whitespace-nowrap text-right">{{ $row->position->activeExceptions->format() }}</td>
                            <td class="whitespace-nowrap text-right">{{ $row->position->committed->format() }}</td>
                            <td class="whitespace-nowrap text-right">{{ $row->position->receivable->format() }}</td>
                            <td class="whitespace-nowrap text-right">{{ $row->position->exposure()->format() }}</td>
                            <td @class(['whitespace-nowrap text-right font-medium', 'text-red-700' => ! $row->position->available()->isPositive()])>
                                {{ $row->position->available()->format() }}
                            </td>
                        @else
                            <td colspan="6" class="text-sm text-slate-500">Sem conta de crédito: pedidos a prazo são recusados.</td>
                        @endif
                        <td><x-ui.badge :color="$row->situationColor()">{{ $row->situationLabel() }}</x-ui.badge></td>
                        <td class="whitespace-nowrap text-right">
                            <a href="{{ route('credit.show', ['buyer' => $row->buyerCompanyId]) }}" class="font-medium text-brand-700 hover:underline">
                                @if ($row->hasAccount())
                                    Ver conta<span class="sr-only"> de {{ $row->tradeName }}</span>
                                @elseif ($canManage)
                                    Abrir conta<span class="sr-only"> de crédito para {{ $row->tradeName }}</span>
                                @else
                                    Ver detalhes<span class="sr-only"> de {{ $row->tradeName }}</span>
                                @endif
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">
                            @if ($filtered)
                                <x-ui.empty-state title="Nenhuma empresa encontrada" description="Revise a busca, a situação ou o valor disponível informado." />
                            @else
                                <x-ui.empty-state title="Nenhuma empresa compradora cadastrada" description="Cadastre empresas compradoras para conceder crédito comercial." />
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>
    <div class="mt-4">{{ $accounts->links() }}</div>
</div>

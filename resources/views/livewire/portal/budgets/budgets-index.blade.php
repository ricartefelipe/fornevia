<div>
    <x-ui.page-header title="Orçamentos"
        description="Orçamento mensal por centro de custo. Pedidos enviados para aprovação reservam o valor; pedidos confirmados com o distribuidor o consomem; rejeição e cancelamento liberam a reserva." />

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)
            <x-ui.alert type="success">{{ $message }}</x-ui.alert>
        @endif
    </div>

    <section class="fv-card mb-6 p-4" aria-labelledby="orcamentos-mes">
        <h2 id="orcamentos-mes" class="sr-only">Mês do orçamento</h2>
        <div class="flex flex-wrap items-end gap-3">
            <x-ui.button variant="secondary" wire:click="previousMonth" wire:loading.attr="disabled" :disabled="$isFirstMonth">
                <span aria-hidden="true">←</span> Mês anterior
            </x-ui.button>
            <div class="w-48">
                <x-ui.input label="Mês" name="month" type="month" min="2000-01" max="2099-12" wire:model.live="month" />
            </div>
            <x-ui.button variant="secondary" wire:click="nextMonth" wire:loading.attr="disabled" :disabled="$isLastMonth">
                Próximo mês <span aria-hidden="true">→</span>
            </x-ui.button>
            <p wire:loading.delay class="pb-2 text-sm text-slate-600" role="status">Carregando orçamentos…</p>
        </div>
    </section>

    <section class="fv-card mb-6" aria-labelledby="orcamentos-lista">
        <div class="p-5 pb-0">
            <h2 id="orcamentos-lista" class="text-lg font-semibold">Centros de custo em {{ $monthLabel }}</h2>
            <p class="mt-1 text-sm text-slate-600">Centro de custo sem orçamento no mês não tem controle de valor: os pedidos dele seguem apenas as regras de aprovação.</p>
        </div>

        @if ($rows === [])
            <div class="p-5">
                <x-ui.empty-state title="Nenhum centro de custo ativo" description="Os centros de custo da sua empresa são cadastrados pelo distribuidor. Fale com o seu representante." />
            </div>
        @else
            <div class="mt-4 overflow-x-auto" wire:loading.class="opacity-60">
                <table class="fv-table">
                    <caption class="sr-only">Orçamentos por centro de custo em {{ $monthLabel }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">Centro de custo</th>
                            <th scope="col" class="text-right">Valor</th>
                            <th scope="col" class="text-right">Reservado</th>
                            <th scope="col" class="text-right">Consumido</th>
                            <th scope="col" class="text-right">Disponível</th>
                            <th scope="col"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rows as $row)
                            @php($center = $row['center'])
                            @php($position = $row['position'])
                            <tr wire:key="centro-{{ $center->id }}">
                                <td>
                                    <p class="font-medium text-slate-900">{{ $center->code }} · {{ $center->name }}</p>
                                    @if ($row['unit'])
                                        <p class="text-xs text-slate-600">Unidade: {{ $row['unit'] }}</p>
                                    @endif
                                    @unless ($center->is_active)
                                        <x-ui.badge>Inativo</x-ui.badge>
                                    @endunless
                                </td>
                                @if ($position)
                                    <td class="whitespace-nowrap text-right font-medium">{{ $position->amount->format() }}</td>
                                    <td class="whitespace-nowrap text-right">{{ $position->reserved->format() }}</td>
                                    <td class="whitespace-nowrap text-right">{{ $position->consumed->format() }}</td>
                                    <td @class(['whitespace-nowrap text-right font-semibold', 'text-red-700' => $position->available()->isZero() && $position->amount->isPositive()])>
                                        {{ $position->available()->format() }}
                                    </td>
                                @else
                                    <td colspan="4" class="text-sm text-slate-600">Sem orçamento no mês</td>
                                @endif
                                <td class="whitespace-nowrap text-right">
                                    @if ($center->is_active || $row['budget'])
                                        <x-ui.button variant="secondary" wire:click="edit('{{ $center->id }}')" wire:loading.attr="disabled">
                                            {{ $row['budget'] ? 'Alterar valor' : 'Definir valor' }}<span class="sr-only"> de {{ $center->code }}</span>
                                        </x-ui.button>
                                    @endif
                                    @if ($row['budget'])
                                        <a href="{{ route('portal.budgets.show', $row['budget']->id) }}" class="fv-btn-secondary">
                                            Movimentos<span class="sr-only"> de {{ $center->code }} em {{ $monthLabel }}</span>
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    @if ($totals)
                        <tfoot>
                            <tr class="border-t-2 border-slate-200 bg-slate-50">
                                <th scope="row" class="text-left font-semibold">Total dos orçamentos do mês</th>
                                <td class="whitespace-nowrap text-right font-semibold">{{ $totals->amount->format() }}</td>
                                <td class="whitespace-nowrap text-right font-semibold">{{ $totals->reserved->format() }}</td>
                                <td class="whitespace-nowrap text-right font-semibold">{{ $totals->consumed->format() }}</td>
                                <td class="whitespace-nowrap text-right font-semibold">{{ $totals->available()->format() }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        @endif
    </section>

    @if ($centerOptions !== [])
        <section class="fv-card p-5" aria-labelledby="orcamentos-form">
            <h2 id="orcamentos-form" class="text-lg font-semibold">Definir o valor de {{ $monthLabel }}</h2>
            <p class="mt-1 text-sm text-slate-600">
                O valor pode ser aumentado a qualquer momento. A redução não pode deixar o orçamento abaixo do que já está reservado e consumido no mês.
            </p>

            <form wire:submit="save" class="mt-4 grid gap-4 sm:grid-cols-3" novalidate>
                <x-ui.select label="Centro de custo" name="costCenterId" :options="$centerOptions" placeholder="Selecione o centro de custo" wire:model="costCenterId" required />
                <x-ui.input label="Valor do mês (R$)" name="amount" inputmode="decimal" autocomplete="off" maxlength="25"
                    hint="Em reais, por exemplo 15.000,00." wire:model="amount" required />
                <div class="flex items-end gap-3">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Salvar orçamento</x-ui.button>
                    <span wire:loading wire:target="save" class="text-sm text-slate-600" role="status">Salvando…</span>
                </div>
            </form>
        </section>
    @endif
</div>

<div>
    <x-ui.page-header title="Condições de pagamento"
        description="Condições oferecidas às empresas compradoras. Condições a prazo consomem o crédito comercial da empresa." />

    @include('livewire.staff.pricing.partials.nav', ['current' => 'pricing.payment-terms'])

    @if ($message)
        <x-ui.alert type="success" class="mb-4">{{ $message }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="fv-card overflow-x-auto lg:col-span-2" aria-labelledby="payment-terms-list">
            <h2 id="payment-terms-list" class="px-6 pt-5 text-lg font-semibold">Condições cadastradas</h2>

            @if ($terms->isEmpty())
                <div class="p-6">
                    <x-ui.empty-state title="Nenhuma condição de pagamento" description="Cadastre, por exemplo, À vista (0) e 30/60/90 dias." />
                </div>
            @else
                <table class="fv-table mt-4">
                    <thead>
                        <tr>
                            <th scope="col">Código</th>
                            <th scope="col">Nome</th>
                            <th scope="col">Parcelas</th>
                            <th scope="col">Modalidade</th>
                            <th scope="col">Situação</th>
                            <th scope="col"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($terms as $term)
                            <tr wire:key="pt-{{ $term->id }}">
                                <td class="font-mono">{{ $term->code }}</td>
                                <td>{{ $term->name }}</td>
                                <td>
                                    {{ count($term->installment_days) }}x
                                    <span class="block text-xs text-slate-500">{{ implode(' / ', $term->installment_days) }} dias</span>
                                </td>
                                <td>{{ $term->requires_credit ? 'A prazo' : 'À vista' }}</td>
                                <td><x-ui.badge :color="$term->is_active ? 'green' : 'slate'">{{ $term->is_active ? 'Ativa' : 'Inativa' }}</x-ui.badge></td>
                                <td class="text-right">
                                    @if ($canManage)
                                        <x-ui.button variant="secondary" wire:click="edit('{{ $term->id }}')">
                                            Editar<span class="sr-only"> a condição {{ $term->code }}</span>
                                        </x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-6 py-4">{{ $terms->links() }}</div>
            @endif
        </section>

        @if ($canManage)
            <section class="fv-card p-6" aria-labelledby="payment-term-form">
                <h2 id="payment-term-form" class="text-lg font-semibold">{{ $editingId ? 'Editar condição' : 'Nova condição' }}</h2>
                <form wire:submit="save" class="mt-4 space-y-3" novalidate>
                    <x-ui.input label="Código" name="code" wire:model="code" maxlength="20" autocomplete="off" required />
                    <x-ui.input label="Nome" name="name" wire:model="name" maxlength="80" required />
                    <x-ui.input label="Dias de vencimento" name="installmentDays" wire:model="installmentDays" maxlength="60" required
                        hint="Dias após o faturamento, separados por barra: 0 (à vista) ou 30/60/90. Até 12 parcelas, de 0 a 365 dias." />
                    <div>
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model="requiresCredit" aria-describedby="requires-credit-hint" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600"> A prazo
                        </label>
                        <p id="requires-credit-hint" class="mt-1 text-xs text-slate-500">Desmarcado: à vista, uma única parcela no dia 0. Marcado: consome o crédito comercial da empresa.</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="isActive" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600"> Ativa
                    </label>
                    <div class="flex gap-2">
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Salvar</x-ui.button>
                        @if ($editingId)
                            <x-ui.button variant="secondary" wire:click="cancel">Cancelar</x-ui.button>
                        @endif
                    </div>
                </form>
            </section>
        @else
            <x-ui.alert type="info">Seu perfil permite consultar as condições. Alterações exigem a permissão de gerenciar tabelas de preço.</x-ui.alert>
        @endif
    </div>
</div>

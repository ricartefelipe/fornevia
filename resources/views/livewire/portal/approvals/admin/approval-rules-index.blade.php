<div>
    <x-ui.page-header title="Regras de aprovação" description="Defina quando os pedidos da empresa precisam de aprovação interna e quem aprova cada nível.">
        <x-slot:actions>
            <a href="{{ route('portal.delegations') }}" class="fv-btn-secondary">Delegações</a>
            @if (count($rules) < $maxRules)
                <a href="{{ route('portal.approval-rules.create') }}" class="fv-btn-primary">Nova regra</a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)
            <x-ui.alert type="success">{{ $message }}</x-ui.alert>
        @endif
    </div>

    <x-ui.alert type="info" class="mb-6">
        Cada pedido segue a primeira regra ativa, na ordem abaixo, cujos critérios ele atende; sem regra aplicável, o pedido segue direto para o distribuidor.
        A aprovação interna não reserva estoque nem garante crédito no distribuidor: esses pontos são conferidos na confirmação, depois da última aprovação.
    </x-ui.alert>

    @if ($rules === [])
        <x-ui.empty-state title="Nenhuma regra de aprovação" description="Sem regras, os pedidos da empresa não passam por aprovação interna.">
            <a href="{{ route('portal.approval-rules.create') }}" class="fv-btn-primary">Criar a primeira regra</a>
        </x-ui.empty-state>
    @else
        <section class="fv-card" aria-labelledby="regras-existentes">
            <h2 id="regras-existentes" class="sr-only">Regras cadastradas</h2>
            <div class="overflow-x-auto">
                <table class="fv-table">
                    <caption class="sr-only">Regras de aprovação na ordem de avaliação</caption>
                    <thead>
                        <tr>
                            <th scope="col">Ordem</th>
                            <th scope="col">Regra</th>
                            <th scope="col">Critérios</th>
                            <th scope="col">Níveis</th>
                            <th scope="col">Situação</th>
                            <th scope="col"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @php($position = 0)
                        @foreach ($rules as $rule)
                            <tr wire:key="regra-{{ $rule->id }}">
                                <td class="whitespace-nowrap text-sm">
                                    @if ($rule->isActive)
                                        @php($position++)
                                        {{ $position }}º
                                    @else
                                        <span aria-hidden="true">—</span><span class="sr-only">Fora da avaliação</span>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('portal.approval-rules.edit', $rule->id) }}" class="font-medium text-brand-700 hover:underline">{{ $rule->name }}</a>
                                    <span class="block text-xs text-slate-500">Prioridade {{ $rule->priority }}</span>
                                </td>
                                <td class="text-sm">
                                    @if ($rule->criteria === [])
                                        Todos os pedidos
                                    @else
                                        <ul class="space-y-0.5">
                                            @foreach ($rule->criteria as $criterion)
                                                <li>{{ $criterion }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>
                                <td class="text-sm">
                                    <ol class="space-y-1">
                                        @foreach ($rule->levels as $level)
                                            <li>
                                                <span class="font-medium">Nível {{ $level->number }}:</span> {{ $level->summary() }}
                                                <span class="block text-xs text-slate-600">{{ implode(', ', $level->approverNames) }}</span>
                                                @if ($level->isShortOfApprovers())
                                                    <span class="mt-0.5 block text-xs text-amber-800">
                                                        <x-ui.badge color="amber">Atenção</x-ui.badge>
                                                        Só {{ $level->eligibleCount }} com acesso para quórum de {{ $level->requiredApprovals }}: pedidos neste nível ficam bloqueados até a reatribuição.
                                                    </span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ol>
                                </td>
                                <td>
                                    <x-ui.badge :color="$rule->isActive ? 'green' : 'slate'">{{ $rule->isActive ? 'Ativa' : 'Inativa' }}</x-ui.badge>
                                </td>
                                <td>
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <a href="{{ route('portal.approval-rules.edit', $rule->id) }}" class="fv-btn-secondary">
                                            Editar<span class="sr-only"> a regra {{ $rule->name }}</span>
                                        </a>
                                        @if ($rule->isActive)
                                            <x-ui.button variant="secondary" wire:click="deactivate('{{ $rule->id }}')" wire:loading.attr="disabled">
                                                Desativar<span class="sr-only"> a regra {{ $rule->name }}</span>
                                            </x-ui.button>
                                        @else
                                            <x-ui.button variant="secondary" wire:click="activate('{{ $rule->id }}')" wire:loading.attr="disabled">
                                                Ativar<span class="sr-only"> a regra {{ $rule->name }}</span>
                                            </x-ui.button>
                                        @endif
                                        <x-ui.button variant="danger" wire:click="delete('{{ $rule->id }}')" wire:loading.attr="disabled"
                                            wire:confirm="Excluir esta regra? Pedidos já enviados para aprovação não são afetados.">
                                            Excluir<span class="sr-only"> a regra {{ $rule->name }}</span>
                                        </x-ui.button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        @if (count($rules) >= $maxRules)
            <p class="mt-3 text-sm text-slate-600">A empresa atingiu o limite de {{ $maxRules }} regras. Exclua regras que não usa mais para criar outras.</p>
        @endif
    @endif
</div>

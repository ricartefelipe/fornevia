<div>
    <x-ui.page-header title="Depósitos"
        description="Locais de estoque de cada filial. A prioridade define a ordem de atendimento dos pedidos (maior primeiro); só depósitos ativos que atendem vendas reservam estoque para pedidos." />

    @include('livewire.staff.inventory.partials.nav', ['current' => 'inventory.warehouses', 'canManage' => $canManage])

    @if ($message)
        <x-ui.alert type="success" class="mb-4">{{ $message }}</x-ui.alert>
    @endif

    @if ($errors->has('delete'))
        <x-ui.alert type="error" class="mb-4">{{ $errors->first('delete') }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section @class(['fv-card overflow-x-auto', 'lg:col-span-2' => $canManage, 'lg:col-span-3' => ! $canManage]) aria-labelledby="warehouses-title">
            <h2 id="warehouses-title" class="sr-only">Lista de depósitos</h2>
            @if ($warehouses->isEmpty())
                <div class="p-4">
                    <x-ui.empty-state title="Nenhum depósito cadastrado"
                        :description="$canManage ? 'Cadastre o primeiro depósito para registrar entradas de mercadoria.' : 'Ainda não há depósitos cadastrados.'" />
                </div>
            @else
                <table class="fv-table">
                    <thead>
                        <tr>
                            <th scope="col">Código</th>
                            <th scope="col">Nome</th>
                            <th scope="col">Filial</th>
                            <th scope="col">Prioridade</th>
                            <th scope="col">Atende vendas</th>
                            <th scope="col">SKUs com saldo</th>
                            <th scope="col">Situação</th>
                            <th scope="col"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($warehouses as $warehouse)
                            <tr wire:key="warehouse-{{ $warehouse->id }}" @class(['bg-brand-50' => $editingId === $warehouse->id])>
                                <td class="font-mono">{{ $warehouse->code }}</td>
                                <td>{{ $warehouse->name }}</td>
                                <td>{{ $warehouse->branch->name }}</td>
                                <td>{{ $warehouse->priority }}</td>
                                <td>{{ $warehouse->allows_sales ? 'Sim' : 'Não' }}</td>
                                <td>{{ $warehouse->skus_in_stock }}</td>
                                <td><x-ui.badge :color="$warehouse->is_active ? 'green' : 'slate'">{{ $warehouse->is_active ? 'Ativo' : 'Inativo' }}</x-ui.badge></td>
                                <td class="whitespace-nowrap text-right">
                                    @if ($canManage)
                                        <x-ui.button variant="secondary" wire:click="edit('{{ $warehouse->id }}')">Editar<span class="sr-only"> o depósito {{ $warehouse->code }}</span></x-ui.button>
                                        @unless ($warehouse->has_history)
                                            <x-ui.button variant="danger" wire:click="delete('{{ $warehouse->id }}')"
                                                wire:confirm="Excluir o depósito {{ $warehouse->code }}? Ele ainda não tem movimentações.">
                                                Excluir<span class="sr-only"> o depósito {{ $warehouse->code }}</span>
                                            </x-ui.button>
                                        @endunless
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-4 py-3">{{ $warehouses->links() }}</div>
            @endif
        </section>

        @if ($canManage)
            <section class="fv-card p-6" aria-labelledby="warehouse-form-title">
                <h2 id="warehouse-form-title" class="text-lg font-semibold">{{ $editingId ? 'Editar depósito' : 'Novo depósito' }}</h2>
                <form wire:submit="save" class="mt-4 space-y-3" novalidate>
                    <x-ui.input label="Código" name="code" wire:model="code" required maxlength="20" autocomplete="off"
                        hint="Letras, números, ponto, hífen ou sublinhado (ex.: CD1, LOJA-SP)." />
                    <x-ui.input label="Nome" name="name" wire:model="name" required maxlength="120" />
                    <x-ui.select label="Filial" name="branchId" wire:model="branchId" placeholder="Selecione"
                        :options="$branches->mapWithKeys(fn ($branch) => [$branch->id => $branch->name.($branch->is_active ? '' : ' (inativa)')])->all()" />
                    <x-ui.input label="Prioridade" name="priority" type="number" min="0" max="9999" wire:model="priority" required
                        hint="De 0 a 9999. Maior prioridade atende os pedidos primeiro." />
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="allowsSales" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600"> Atende vendas
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="isActive" aria-describedby="warehouse-active-hint" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600"> Ativo
                    </label>
                    <p id="warehouse-active-hint" class="text-xs text-slate-500">Depósito inativo não recebe entradas nem novas reservas; saldos e reservas existentes são mantidos.</p>
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
</div>

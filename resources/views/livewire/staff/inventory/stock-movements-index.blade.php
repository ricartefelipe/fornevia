<div>
    <x-ui.page-header title="Movimentações de estoque"
        description="Histórico completo e imutável: cada saldo é a soma destes movimentos. Correções são feitas por novos movimentos, nunca por edição." />

    @include('livewire.staff.inventory.partials.nav', ['current' => 'inventory.movements', 'canManage' => $canManage])

    <section class="fv-card mb-4 p-4" aria-labelledby="movement-filters-title">
        <h2 id="movement-filters-title" class="sr-only">Busca e filtros</h2>
        <form wire:submit="applyFilters" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5" role="search">
            <div class="lg:col-span-2">
                <x-ui.input label="SKU" name="search" type="search" wire:model.live.debounce.400ms="search"
                    hint="Código do SKU ou nome do produto." autocomplete="off" maxlength="100" />
            </div>
            <x-ui.select label="Depósito" name="warehouseId" wire:model.live="warehouseId" placeholder="Todos" :options="$warehouseOptions" />
            <x-ui.select label="Tipo" name="type" wire:model.live="type" placeholder="Todos">
                @foreach ($types as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </x-ui.select>
            <div class="grid grid-cols-2 gap-2">
                <x-ui.input label="De" name="from" type="date" wire:model.live="from" />
                <x-ui.input label="Até" name="until" type="date" wire:model.live="until" />
            </div>
            @if ($invalidPeriod)
                <div class="sm:col-span-2 lg:col-span-5">
                    <x-ui.alert type="warning">Data do período inválida: use datas entre 01/01/2000 e 31/12/2099. A data inválida foi ignorada.</x-ui.alert>
                </div>
            @endif
            @if ($filtered || $invalidPeriod)
                <div class="sm:col-span-2 lg:col-span-5">
                    <x-ui.button variant="secondary" wire:click="clearFilters">Limpar filtros</x-ui.button>
                </div>
            @endif
        </form>
    </section>

    <section class="fv-card overflow-x-auto" aria-labelledby="movements-title" wire:loading.attr="aria-busy">
        <h2 id="movements-title" class="sr-only">Movimentações</h2>
        <p class="px-4 pt-3 text-sm text-slate-600" wire:loading.delay role="status">Carregando movimentações…</p>

        @if ($movements->isEmpty())
            <div class="p-4">
                <x-ui.empty-state :title="$filtered ? 'Nenhuma movimentação encontrada' : 'Nenhuma movimentação registrada'"
                    :description="$filtered ? 'Revise a busca ou limpe os filtros.' : 'As movimentações aparecem a partir da primeira entrada de mercadoria.'" />
            </div>
        @else
            <table class="fv-table">
                <thead>
                    <tr>
                        <th scope="col">Data e hora</th>
                        <th scope="col">Tipo</th>
                        <th scope="col">SKU</th>
                        <th scope="col">Depósito</th>
                        <th scope="col" class="text-right">Físico</th>
                        <th scope="col" class="text-right">Reservado</th>
                        <th scope="col">Referência e motivo</th>
                        <th scope="col">Responsável</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($movements as $movement)
                        @php($unit = $movement->sku->product->baseUnit->code)
                        <tr wire:key="movement-{{ $movement->id }}">
                            <td class="whitespace-nowrap">{{ $movement->created_at?->setTimezone($timezone)->format('d/m/Y H:i') }}</td>
                            <td>{{ $movement->type->label() }}</td>
                            <td>
                                <span class="font-mono">{{ $movement->sku->code }}</span>
                                <span class="block text-xs text-slate-500">{{ $movement->sku->product->name }}</span>
                            </td>
                            <td class="font-mono">{{ $movement->warehouse->code }}</td>
                            @foreach ([$movement->on_hand_delta, $movement->reserved_delta] as $delta)
                                <td @class(['whitespace-nowrap text-right', 'text-emerald-800' => $delta->isPositive(), 'text-red-700' => $delta->isNegative(), 'text-slate-400' => $delta->isZero()])>
                                    @if ($delta->isZero())
                                        <span aria-label="sem alteração">—</span>
                                    @else
                                        {{ $delta->isPositive() ? '+' : '' }}{{ $delta->format() }} {{ $unit }}
                                    @endif
                                </td>
                            @endforeach
                            <td>
                                {{ $references[$movement->id] ?? 'Outro documento' }}
                                @if ($movement->reason)
                                    <span class="block text-xs text-slate-500">{{ $movement->reason }}</span>
                                @endif
                            </td>
                            <td>{{ $movement->actor_user_id !== null ? ($actors[$movement->actor_user_id] ?? 'Usuário removido') : 'Sistema' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-4 py-3">{{ $movements->links() }}</div>
        @endif
    </section>
</div>

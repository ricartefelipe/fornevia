<div>
    <x-ui.page-header title="Estoque"
        description="Saldos por SKU e depósito, na unidade base do produto. Disponível = físico − reservado para pedidos confirmados.">
        @if ($canManage)
            <x-slot:actions>
                <a href="{{ route('inventory.operations') }}" class="fv-btn-primary">Movimentar estoque</a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('livewire.staff.inventory.partials.nav', ['current' => 'inventory.index', 'canManage' => $canManage])

    <section class="fv-card mb-4 p-4" aria-labelledby="stock-filters-title">
        <h2 id="stock-filters-title" class="sr-only">Busca e filtros</h2>
        <form wire:submit="applyFilters" class="grid gap-4 sm:grid-cols-4" role="search">
            <div class="sm:col-span-2">
                <x-ui.input label="Buscar" name="search" type="search" wire:model.live.debounce.400ms="search"
                    hint="Código do SKU ou nome do produto." autocomplete="off" maxlength="100" />
            </div>
            <x-ui.select label="Depósito" name="warehouseId" wire:model.live="warehouseId" placeholder="Todos" :options="$warehouseOptions" />
            <div class="flex items-end pb-2">
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model.live="onlyWithStock" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600"> Somente com saldo
                </label>
            </div>
            @if ($filtered)
                <div class="sm:col-span-4">
                    <x-ui.button variant="secondary" wire:click="clearFilters">Limpar filtros</x-ui.button>
                </div>
            @endif
        </form>
    </section>

    <section class="fv-card overflow-x-auto" aria-labelledby="stock-levels-title" wire:loading.attr="aria-busy">
        <h2 id="stock-levels-title" class="sr-only">Saldos</h2>
        <p class="px-4 pt-3 text-sm text-slate-600" wire:loading.delay role="status">Carregando saldos…</p>

        @if ($levels->isEmpty())
            <div class="p-4">
                @if ($filtered)
                    <x-ui.empty-state title="Nenhum saldo encontrado" description="Revise a busca ou limpe os filtros." />
                @else
                    <x-ui.empty-state title="Nenhum saldo registrado" description="Os saldos aparecem após a primeira entrada de mercadoria em um depósito.">
                        @if ($canManage)
                            <a href="{{ route('inventory.operations') }}" class="fv-btn-primary">Registrar entrada</a>
                        @endif
                    </x-ui.empty-state>
                @endif
            </div>
        @else
            <table class="fv-table">
                <thead>
                    <tr>
                        <th scope="col">SKU</th>
                        <th scope="col">Depósito</th>
                        <th scope="col" class="text-right">Físico</th>
                        <th scope="col" class="text-right">Reservado</th>
                        <th scope="col" class="text-right">Disponível</th>
                        <th scope="col"><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($levels as $level)
                        @php($unit = $level->sku->product->baseUnit->code)
                        @php($available = $level->on_hand->minus($level->reserved))
                        <tr wire:key="level-{{ $level->id }}">
                            <td>
                                <span class="font-mono font-medium">{{ $level->sku->code }}</span>
                                <span class="block text-xs text-slate-500">{{ $level->sku->product->name }}{{ $level->sku->variant_label ? ' · '.$level->sku->variant_label : '' }}</span>
                            </td>
                            <td>
                                <span class="font-mono">{{ $level->warehouse->code }}</span>
                                <span class="block text-xs text-slate-500">{{ $level->warehouse->name }}{{ $level->warehouse->is_active ? '' : ' (inativo)' }}</span>
                            </td>
                            <td class="whitespace-nowrap text-right">{{ $level->on_hand->format() }} {{ $unit }}</td>
                            <td class="whitespace-nowrap text-right">{{ $level->reserved->format() }} {{ $unit }}</td>
                            <td @class(['whitespace-nowrap text-right font-semibold', 'text-red-700' => ! $available->isPositive()])>{{ $available->format() }} {{ $unit }}</td>
                            <td class="whitespace-nowrap text-right">
                                <a href="{{ route('inventory.movements', ['busca' => $level->sku->code, 'deposito' => $level->warehouse_id]) }}" class="fv-btn-secondary">
                                    Histórico<span class="sr-only"> do SKU {{ $level->sku->code }} no depósito {{ $level->warehouse->code }}</span>
                                </a>
                                @if ($canManage)
                                    <a href="{{ route('inventory.operations', ['sku' => $level->sku_id, 'deposito' => $level->warehouse_id]) }}" class="fv-btn-secondary">
                                        Movimentar<span class="sr-only"> o SKU {{ $level->sku->code }} no depósito {{ $level->warehouse->code }}</span>
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-4 py-3">{{ $levels->links() }}</div>
        @endif
    </section>
</div>

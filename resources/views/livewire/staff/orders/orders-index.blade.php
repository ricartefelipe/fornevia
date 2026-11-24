@php($timezone = (string) config('app.business_timezone'))
<div>
    <x-ui.page-header title="Pedidos"
        description="Pedidos das empresas compradoras, com situação comercial, aprovação interna do comprador, atendimento e financeiro acompanhados separadamente.">
        <x-slot:actions>
            <x-ui.button variant="secondary" wire:click="export" wire:loading.attr="disabled" wire:target="export">Exportar CSV</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @error('export')
        <x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    @if ($portfolioOnly)
        <x-ui.alert type="info" class="mb-4">Você vê os pedidos das empresas da sua carteira.</x-ui.alert>
    @endif

    <section class="fv-card mb-4 p-4" aria-labelledby="orders-filters-title">
        <h2 id="orders-filters-title" class="sr-only">Filtros</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" role="search">
            <x-ui.input label="Número do pedido ou ordem de compra" name="search" type="search" maxlength="60" autocomplete="off"
                wire:model.live.debounce.400ms="search" />
            <x-ui.select label="Situação comercial" name="commercialStatus" wire:model.live="commercialStatus" placeholder="Todas" :options="$commercialOptions" />
            <x-ui.select label="Aprovação" name="approvalStatus" wire:model.live="approvalStatus" placeholder="Todas" :options="$approvalOptions" />
            <x-ui.select label="Financeiro" name="financialStatus" wire:model.live="financialStatus" placeholder="Todas" :options="$financialOptions" />
            <x-ui.select label="Atendimento" name="fulfillmentStatus" wire:model.live="fulfillmentStatus" placeholder="Todas" :options="$fulfillmentOptions" />
            <x-ui.input label="Buscar empresa" name="companySearch" type="search" maxlength="100" autocomplete="off"
                wire:model.live.debounce.400ms="companySearch" hint="Nome fantasia, razão social ou CNPJ." />
            <x-ui.select label="Empresa compradora" name="company" wire:model.live="company" placeholder="Todas as empresas" :options="$companyOptions" />
            <div class="grid grid-cols-2 gap-3">
                <x-ui.input label="Pedidos de" name="dateFrom" type="date" wire:model.live="dateFrom" min="2000-01-01" max="2099-12-31" />
                <x-ui.input label="até" name="dateUntil" type="date" wire:model.live="dateUntil" min="2000-01-01" max="2099-12-31" />
            </div>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-3">
            @if ($filtered)
                <x-ui.button variant="secondary" wire:click="clearFilters">Limpar filtros</x-ui.button>
            @endif
            <p wire:loading.delay wire:target="search,commercialStatus,approvalStatus,financialStatus,fulfillmentStatus,company,companySearch,dateFrom,dateUntil,clearFilters,gotoPage,nextPage,previousPage"
                class="text-xs text-slate-500" role="status">Atualizando a lista…</p>
        </div>
    </section>

    <section class="fv-card overflow-x-auto" aria-labelledby="orders-list-title">
        <h2 id="orders-list-title" class="sr-only">Lista de pedidos</h2>

        @if ($orders->isEmpty())
            <div class="p-6">
                @if ($filtered)
                    <x-ui.empty-state title="Nenhum pedido encontrado" description="Nenhum pedido atende aos filtros informados." />
                @else
                    <x-ui.empty-state title="Nenhum pedido registrado" description="Os pedidos enviados pelo portal do comprador aparecem aqui." />
                @endif
            </div>
        @else
            <p class="px-4 pt-3 text-sm text-slate-600" role="status">
                {{ $orders->total() }} {{ $orders->total() === 1 ? 'pedido encontrado' : 'pedidos encontrados' }}.
            </p>
            <table class="fv-table">
                <caption class="sr-only">Pedidos, do mais recente para o mais antigo</caption>
                <thead>
                    <tr>
                        <th scope="col">Pedido</th>
                        <th scope="col">Data</th>
                        <th scope="col">Empresa</th>
                        <th scope="col">Ordem de compra</th>
                        <th scope="col">Comercial</th>
                        <th scope="col">Aprovação</th>
                        <th scope="col">Atendimento</th>
                        <th scope="col">Financeiro</th>
                        <th scope="col" class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($orders as $order)
                        <tr wire:key="order-{{ $order->id }}">
                            <td class="whitespace-nowrap">
                                <a href="{{ route('orders.show', ['order' => $order->id]) }}" class="font-mono font-medium text-brand-700 hover:underline">
                                    {{ $order->number }}<span class="sr-only">, abrir o pedido</span>
                                </a>
                            </td>
                            <td class="whitespace-nowrap">{{ $order->created_at->setTimezone($timezone)->format('d/m/Y H:i') }}</td>
                            <td>{{ $order->buyerCompany->trade_name }}</td>
                            <td>{{ $order->purchase_order_reference ?? '—' }}</td>
                            <td>
                                @include('livewire.staff.orders.partials.status-badge', ['status' => $order->commercial_status])
                                @if (\App\Actions\Approvals\Support\OrderApprovalSummary::awaitsConfirmation($order))
                                    <span class="mt-1 block text-xs text-amber-800">Confirmação pendente</span>
                                @endif
                            </td>
                            <td>
                                @include('livewire.staff.orders.partials.status-badge', ['status' => $order->approval_status])
                                @if ($order->approval_status === \App\Enums\Ordering\ApprovalStatus::Pending && $order->approvalSteps->isNotEmpty())
                                    @php($currentLevel = $order->approvalSteps->first(static fn ($step) => $step->status === \App\Enums\Approvals\ApprovalStepStatus::Pending))
                                    @if ($currentLevel)
                                        <span class="mt-1 block text-xs text-slate-600">Nível {{ $currentLevel->level_number }} de {{ $order->approvalSteps->count() }}</span>
                                    @endif
                                @endif
                            </td>
                            <td>@include('livewire.staff.orders.partials.status-badge', ['status' => $order->fulfillment_status])</td>
                            <td>@include('livewire.staff.orders.partials.status-badge', ['status' => $order->financial_status])</td>
                            <td class="whitespace-nowrap text-right font-medium">{{ $order->total_cents->format() }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-slate-200 bg-slate-50">
                        <th scope="row" colspan="8" class="text-left font-semibold">
                            Total desta página ({{ $pageCount }} {{ $pageCount === 1 ? 'pedido' : 'pedidos' }})
                        </th>
                        <td class="whitespace-nowrap text-right font-semibold">{{ $pageTotal?->format() ?? "Acima do limite de cálculo" }}</td>
                    </tr>
                </tfoot>
            </table>
            <div class="px-6 py-4">{{ $orders->links() }}</div>
        @endif
    </section>
</div>

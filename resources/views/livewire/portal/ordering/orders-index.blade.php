@php($timezone = (string) config('app.business_timezone'))
<div>
    <x-ui.page-header title="Pedidos" :description="$seesAll ? 'Pedidos feitos pelos usuários da sua empresa.' : 'Pedidos feitos por você.'" />

    <section class="fv-card mb-6 p-4" role="search" aria-labelledby="pedidos-filtros">
        <h2 id="pedidos-filtros" class="sr-only">Filtrar pedidos</h2>
        <div class="grid gap-4 sm:grid-cols-4">
            <x-ui.select label="Situação" name="status" placeholder="Todas as situações" wire:model.live="status">
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}" wire:key="s-{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input label="Feitos a partir de" name="from" type="date" min="2000-01-01" max="2099-12-31" wire:model.live="from" />
            <x-ui.input label="Feitos até" name="until" type="date" min="2000-01-01" max="2099-12-31" wire:model.live="until" />
            @if ($seesAll)
                <label class="flex items-center gap-2 self-end pb-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model.live="mine" class="rounded border-slate-300">
                    Somente os meus pedidos
                </label>
            @endif
        </div>
    </section>

    <p wire:loading.delay class="mb-3 text-sm text-slate-600" role="status">Carregando pedidos…</p>

    <div wire:loading.class="opacity-60">
        <p class="sr-only" role="status" aria-live="polite">
            {{ $orders->total() === 1 ? '1 pedido encontrado.' : $orders->total().' pedidos encontrados.' }}
        </p>

        @if ($orders->isEmpty())
            <x-ui.empty-state
                :title="$filtered ? 'Nenhum pedido encontrado' : 'Nenhum pedido ainda'"
                :description="$filtered ? 'Revise a situação ou o período selecionado.' : 'Os pedidos enviados, inclusive os que aguardam aprovação interna, aparecem aqui.'">
                @if ($filtered)
                    <x-ui.button variant="secondary" wire:click="clearFilters">Limpar filtros</x-ui.button>
                @else
                    <a href="{{ route('portal.catalog') }}" class="fv-btn-primary">Ir para o catálogo</a>
                @endif
            </x-ui.empty-state>
        @else
            <section class="fv-card" aria-labelledby="pedidos-lista">
                <h2 id="pedidos-lista" class="sr-only">Lista de pedidos</h2>
                <div class="overflow-x-auto">
                    <table class="fv-table">
                        <caption class="sr-only">Pedidos</caption>
                        <thead>
                            <tr>
                                <th scope="col">Pedido</th>
                                <th scope="col">Data</th>
                                @if ($seesAll)
                                    <th scope="col">Feito por</th>
                                @endif
                                <th scope="col">Sua OC</th>
                                <th scope="col" class="text-right">Total</th>
                                <th scope="col">Situação</th>
                                <th scope="col">Aprovação interna</th>
                                <th scope="col">Atendimento</th>
                                <th scope="col">Financeiro</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($orders as $order)
                                <tr wire:key="pedido-{{ $order->id }}">
                                    <td>
                                        <a href="{{ route('portal.orders.show', $order->id) }}" class="font-medium text-brand-700 hover:underline">{{ $order->number }}</a>
                                    </td>
                                    <td class="text-sm">{{ $order->created_at->setTimezone($timezone)->format('d/m/Y H:i') }}</td>
                                    @if ($seesAll)
                                        <td class="text-sm">{{ $order->placed_by_membership_id === $membershipId ? 'Você' : ($placedBy[$order->placed_by_user_id] ?? '—') }}</td>
                                    @endif
                                    <td class="text-sm">{{ $order->purchase_order_reference ?? '—' }}</td>
                                    <td class="text-right font-medium">{{ $order->total_cents->format() }}</td>
                                    <td>
                                        @include('livewire.portal.ordering.partials.status-badge', ['status' => $order->commercial_status])
                                        @if (\App\Livewire\Portal\Ordering\OrderShow::awaitsConfirmation($order))
                                            <span class="mt-1 block text-xs text-amber-800">Confirmação com o distribuidor pendente</span>
                                        @endif
                                    </td>
                                    <td>
                                        @include('livewire.portal.ordering.partials.status-badge', ['status' => $order->approval_status])
                                        @if ($order->approval_status === \App\Enums\Ordering\ApprovalStatus::Pending && $order->approvalSteps->isNotEmpty())
                                            @php($currentLevel = $order->approvalSteps->first(static fn ($step) => $step->status === \App\Enums\Approvals\ApprovalStepStatus::Pending))
                                            @if ($currentLevel)
                                                <span class="mt-1 block text-xs text-slate-600">Nível {{ $currentLevel->level_number }} de {{ $order->approvalSteps->count() }}</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td>@include('livewire.portal.ordering.partials.status-badge', ['status' => $order->fulfillment_status])</td>
                                    <td>@include('livewire.portal.ordering.partials.status-badge', ['status' => $order->financial_status])</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="mt-6">{{ $orders->links() }}</div>
        @endif
    </div>
</div>

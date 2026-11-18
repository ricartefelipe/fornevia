@php($timezone = (string) config('app.business_timezone'))
<div class="grid gap-6 xl:grid-cols-2">
    <section class="fv-card overflow-x-auto" aria-labelledby="credit-changes-title">
        <h2 id="credit-changes-title" class="px-6 pt-5 text-lg font-semibold">Alterações de limite e situação</h2>
        @if ($changes === null || $changes->isEmpty())
            <div class="p-6">
                <x-ui.empty-state title="Nenhuma alteração registrada" />
            </div>
        @else
            <table class="fv-table mt-4">
                <thead>
                    <tr>
                        <th scope="col">Data</th>
                        <th scope="col">Alteração</th>
                        <th scope="col">Responsável e justificativa</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($changes as $change)
                        <tr wire:key="credit-change-{{ $change->id }}">
                            <td class="whitespace-nowrap text-xs">{{ $change->createdAt->setTimezone($timezone)->format('d/m/Y H:i') }}</td>
                            <td class="text-sm">{{ $change->description() }}</td>
                            <td class="text-xs">
                                {{ $change->changedBy }}
                                <span class="block text-slate-600">{{ $change->reason }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-6 py-4">{{ $changes->links() }}</div>
        @endif
    </section>

    <section class="fv-card overflow-x-auto" aria-labelledby="credit-movements-title">
        <h2 id="credit-movements-title" class="px-6 pt-5 text-lg font-semibold">Movimentos de crédito</h2>
        <p class="px-6 pt-1 text-xs text-slate-500">Comprometido e recebível mudam somente por estes movimentos.</p>
        @if ($movements === null || $movements->isEmpty())
            <div class="p-6">
                <x-ui.empty-state title="Nenhum movimento registrado" description="Pedidos a prazo confirmados, cancelamentos, faturamentos e pagamentos aparecem aqui." />
            </div>
        @else
            <table class="fv-table mt-4">
                <thead>
                    <tr>
                        <th scope="col">Data</th>
                        <th scope="col">Movimento</th>
                        <th scope="col" class="text-right">Comprometido</th>
                        <th scope="col" class="text-right">Recebível</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($movements as $movement)
                        <tr wire:key="credit-movement-{{ $movement->id }}">
                            <td class="whitespace-nowrap text-xs">{{ $movement->createdAt->setTimezone($timezone)->format('d/m/Y H:i') }}</td>
                            <td class="text-sm">
                                {{ $movement->type->label() }}
                                <span class="block text-xs text-slate-600">{{ $movement->reference }} · {{ $movement->actor }}</span>
                                @if ($movement->reason)
                                    <span class="block text-xs text-slate-500">{{ $movement->reason }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-right">{{ \App\Data\Credit\CreditMovementEntry::signed($movement->committedDelta) }}</td>
                            <td class="whitespace-nowrap text-right">{{ \App\Data\Credit\CreditMovementEntry::signed($movement->receivableDelta) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-6 py-4">{{ $movements->links() }}</div>
        @endif
    </section>
</div>

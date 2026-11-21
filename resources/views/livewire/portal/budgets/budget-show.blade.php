@php($timezone = (string) config('app.business_timezone'))
<div>
    <nav aria-label="Trilha de navegação" class="mb-3 text-sm text-slate-600">
        <a href="{{ route('portal.budgets', ['mes' => $budget->period_start->format('Y-m')]) }}" class="font-medium text-brand-700 hover:underline">Orçamentos</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{{ $center->code }} · {{ $monthLabel }}</span>
    </nav>

    <x-ui.page-header :title="'Orçamento de '.$center->code.' · '.$center->name"
        :description="'Mês de '.$monthLabel.'. Saldos mantidos pelos movimentos dos pedidos deste centro de custo.'" />

    <section class="fv-card mb-6 p-5" aria-labelledby="orcamento-posicao">
        <h2 id="orcamento-posicao" class="text-lg font-semibold">Posição do mês</h2>
        <dl class="mt-3 grid gap-4 sm:grid-cols-4">
            <div>
                <dt class="text-xs font-medium uppercase text-slate-500">Valor</dt>
                <dd class="mt-1 text-lg font-semibold">{{ $position->amount->format() }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase text-slate-500">Reservado</dt>
                <dd class="mt-1 text-lg">{{ $position->reserved->format() }}</dd>
                <dd class="text-xs text-slate-600">Pedidos aguardando aprovação ou confirmação</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase text-slate-500">Consumido</dt>
                <dd class="mt-1 text-lg">{{ $position->consumed->format() }}</dd>
                <dd class="text-xs text-slate-600">Pedidos confirmados com o distribuidor</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase text-slate-500">Disponível</dt>
                <dd class="mt-1 text-lg font-semibold">{{ $position->available()->format() }}</dd>
            </div>
        </dl>
    </section>

    <section class="fv-card" aria-labelledby="orcamento-movimentos">
        <div class="p-5 pb-0">
            <h2 id="orcamento-movimentos" class="text-lg font-semibold">Movimentos</h2>
            <p class="mt-1 text-sm text-slate-600">Do mais recente para o mais antigo. Movimentos não são editados: correções aparecem como novos movimentos.</p>
        </div>

        @if ($movements->isEmpty())
            <div class="p-5">
                <x-ui.empty-state title="Nenhum movimento no mês" description="Os pedidos com este centro de custo aparecem aqui quando reservam ou consomem o orçamento." />
            </div>
        @else
            <div class="mt-4 overflow-x-auto" wire:loading.class="opacity-60">
                <table class="fv-table">
                    <caption class="sr-only">Movimentos do orçamento de {{ $center->code }} em {{ $monthLabel }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">Data</th>
                            <th scope="col">Pedido</th>
                            <th scope="col">Movimento</th>
                            <th scope="col" class="text-right">Reservado</th>
                            <th scope="col" class="text-right">Consumido</th>
                            <th scope="col">Responsável</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($movements as $movement)
                            @php($order = $orders->get($movement->order_id))
                            <tr wire:key="movimento-{{ $movement->id }}">
                                <td class="whitespace-nowrap text-sm">
                                    <time datetime="{{ $movement->created_at->toIso8601String() }}">{{ $movement->created_at->setTimezone($timezone)->format('d/m/Y H:i') }}</time>
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($order && in_array($order->id, $linkable, true))
                                        <a href="{{ route('portal.orders.show', $order->id) }}" class="font-medium text-brand-700 hover:underline">{{ $order->number }}</a>
                                    @else
                                        {{ $order?->number ?? '—' }}
                                    @endif
                                </td>
                                <td>{{ $movement->type->label() }}</td>
                                <td class="whitespace-nowrap text-right">
                                    {{ $movement->reserved_delta === 0 ? '—' : ($movement->reserved_delta > 0 ? '+ ' : '− ').\App\Domain\Shared\Money::ofCents(abs($movement->reserved_delta))->format() }}
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    {{ $movement->consumed_delta === 0 ? '—' : '+ '.\App\Domain\Shared\Money::ofCents($movement->consumed_delta)->format() }}
                                </td>
                                <td class="text-sm">{{ $movement->actor_user_id !== null ? ($names[$movement->actor_user_id] ?? 'Usuário não identificado') : 'Sistema' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-5">{{ $movements->links() }}</div>
        @endif
    </section>
</div>

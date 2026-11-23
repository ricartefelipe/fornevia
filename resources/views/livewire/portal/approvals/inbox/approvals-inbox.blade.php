@php($timezone = (string) config('app.business_timezone'))
@php($tab = $overview->tab)
@php($entries = $overview->entries)
<div>
    <x-ui.page-header title="Aprovações" description="Pedidos da sua empresa que dependem de aprovação interna antes de seguir para o distribuidor." />

    <x-ui.alert type="info" class="mb-6">
        A aprovação interna não garante estoque nem crédito no distribuidor: eles são verificados quando o pedido é confirmado, depois do último nível aprovado.
    </x-ui.alert>

    <nav aria-label="Filas de aprovação" class="mb-4 border-b border-slate-200">
        <ul class="-mb-px flex flex-wrap gap-4">
            @foreach ($tabs as $option)
                @php($active = $option === $tab)
                <li wire:key="aba-{{ $option->value }}">
                    <a href="{{ route('portal.approvals', $option === \App\Actions\Approvals\Queries\InboxTab::Awaiting ? [] : ['aba' => $option->value]) }}"
                        @if ($active) aria-current="page" @endif
                        @class([
                            'inline-flex items-center gap-2 border-b-2 px-1 pb-2 text-sm font-medium',
                            'border-brand-700 text-brand-800' => $active,
                            'border-transparent text-slate-600 hover:text-slate-900' => ! $active,
                        ])>
                        {{ $option->label() }}
                        <x-ui.badge :color="$active ? 'brand' : 'slate'">{{ $overview->count($option) ?? 0 }}<span class="sr-only"> pedidos</span></x-ui.badge>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    <p wire:loading.delay class="mb-3 text-sm text-slate-600" role="status">Carregando pedidos…</p>

    <div wire:loading.class="opacity-60">
        <p class="sr-only" role="status" aria-live="polite">
            {{ $entries->total() === 1 ? '1 pedido nesta fila.' : $entries->total().' pedidos nesta fila.' }}
        </p>

        @if ($entries->isEmpty())
            @php($empty = match ($tab) {
                \App\Actions\Approvals\Queries\InboxTab::Awaiting => ['Nenhum pedido aguardando sua decisão', 'Quando um pedido depender da sua aprovação, ou de quem delegou a aprovação a você, ele aparece aqui.'],
                \App\Actions\Approvals\Queries\InboxTab::Decided => ['Você ainda não decidiu nenhum pedido', 'Os pedidos que você aprovar ou rejeitar aparecem aqui.'],
                \App\Actions\Approvals\Queries\InboxTab::Blocked => ['Nenhum pedido bloqueado', 'Pedidos cujo nível atual não tem aprovadores elegíveis suficientes para o quórum aparecem aqui.'],
            })
            <x-ui.empty-state :title="$empty[0]" :description="$empty[1]" />
        @else
            @if ($overview->amount !== null)
                <p class="mb-3 text-sm text-slate-700">
                    {{ $entries->total() === 1 ? '1 pedido' : $entries->total().' pedidos' }}, somando <span class="font-semibold">{{ $overview->amount->format() }}</span>.
                </p>
            @endif

            <section class="fv-card" aria-labelledby="aprovacoes-lista">
                <h2 id="aprovacoes-lista" class="sr-only">{{ $tab->label() }}</h2>
                <div class="overflow-x-auto">
                    <table class="fv-table">
                        <caption class="sr-only">{{ $tab->label() }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">Pedido</th>
                                <th scope="col">Solicitante</th>
                                <th scope="col">Centro de custo</th>
                                <th scope="col">Regra aplicada</th>
                                @if ($tab === \App\Actions\Approvals\Queries\InboxTab::Decided)
                                    <th scope="col">Sua decisão</th>
                                    <th scope="col">Situação</th>
                                @else
                                    <th scope="col">Nível atual</th>
                                    <th scope="col">Aguardando há</th>
                                @endif
                                <th scope="col" class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($entries as $entry)
                                @php($order = $entry->order)
                                <tr wire:key="aprovacao-{{ $order->id }}">
                                    <td>
                                        <a href="{{ route('portal.approvals.show', $order->id) }}" class="font-medium text-brand-700 hover:underline">{{ $order->number }}</a>
                                        @if ($entry->principalName)
                                            <p class="text-xs text-slate-600">Em nome de {{ $entry->principalName }} (delegação)</p>
                                        @endif
                                    </td>
                                    <td class="text-sm">{{ $order->placed_by_membership_id === $membershipId ? 'Você' : ($entry->requesterName ?? '—') }}</td>
                                    <td class="text-sm">{{ $entry->costCenterName ?? 'Não informado' }}</td>
                                    <td class="text-sm">{{ $order->approval_rule_name ?? '—' }}</td>
                                    @if ($tab === \App\Actions\Approvals\Queries\InboxTab::Decided)
                                        <td class="text-sm">
                                            @if ($entry->decision)
                                                <x-ui.badge :color="$entry->decision->decision === \App\Enums\Approvals\ApprovalDecision::Approved ? 'green' : 'red'">{{ $entry->decision->decision->label() }}</x-ui.badge>
                                                <p class="mt-1 text-xs text-slate-600">
                                                    Nível {{ $entry->decision->level }} ·
                                                    <time datetime="{{ $entry->decision->at->toIso8601String() }}">{{ $entry->decision->at->setTimezone($timezone)->format('d/m/Y H:i') }}</time>
                                                </p>
                                                @if ($entry->decision->principalName)
                                                    <p class="text-xs text-slate-600">Em nome de {{ $entry->decision->principalName }} (delegação)</p>
                                                @endif
                                                @if ($entry->decision->actorName)
                                                    <p class="text-xs text-slate-600">Decidido por {{ $entry->decision->actorName }} em seu nome (delegação)</p>
                                                @endif
                                                @if ($entry->decision->invalidated)
                                                    <p class="text-xs text-amber-800">Invalidada: o pedido voltou para aprovação</p>
                                                @endif
                                            @endif
                                        </td>
                                        <td>@include('livewire.portal.ordering.partials.status-badge', ['status' => $order->commercial_status])</td>
                                    @else
                                        <td class="text-sm">
                                            @if ($entry->pending)
                                                <p>Nível {{ $entry->pending->step->level_number }} de {{ $entry->levelCount }}</p>
                                                <p class="text-xs text-slate-600">{{ $entry->pending->approvals() }} de {{ $entry->pending->step->required_approvals }} {{ $entry->pending->step->required_approvals === 1 ? 'aprovação' : 'aprovações' }}</p>
                                                @if ($entry->pending->blocked)
                                                    <x-ui.badge color="red" class="mt-1">Sem aprovadores suficientes</x-ui.badge>
                                                @endif
                                            @endif
                                        </td>
                                        <td class="text-sm">
                                            <p>{{ $entry->waiting($now) }}</p>
                                            <p class="text-xs text-slate-600">Enviado em <time datetime="{{ $entry->submittedAt()->toIso8601String() }}">{{ $entry->submittedAt()->setTimezone($timezone)->format('d/m/Y H:i') }}</time></p>
                                        </td>
                                    @endif
                                    <td class="text-right font-medium">{{ $order->total_cents->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="mt-6">{{ $entries->links() }}</div>
        @endif
    </div>
</div>

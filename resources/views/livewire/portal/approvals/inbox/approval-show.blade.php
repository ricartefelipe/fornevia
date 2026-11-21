@php($timezone = (string) config('app.business_timezone'))
@php($order = $review->order)
@php($current = $review->current)
@php($submittedAt = $order->submitted_at ?? $order->created_at)
<div>
    <nav aria-label="Trilha de navegação" class="mb-3 text-sm text-slate-600">
        <a href="{{ route('portal.approvals') }}" class="font-medium text-brand-700 hover:underline">Aprovações</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{{ $order->number }}</span>
    </nav>

    <x-ui.page-header :title="'Aprovação do pedido '.$order->number"
        :description="'Enviado para aprovação em '.$submittedAt->setTimezone($timezone)->format('d/m/Y').' às '.$submittedAt->setTimezone($timezone)->format('H:i').($review->requesterName ? ' por '.($order->placed_by_membership_id === $membershipId ? 'você' : $review->requesterName) : '').'.'" />

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)
            <x-ui.alert :type="$messageType">{{ $message }}</x-ui.alert>
        @endif
        @if ($failure)
            <x-ui.alert type="error">{{ $failure }}</x-ui.alert>
        @endif
    </div>

    <x-ui.alert type="warning" class="mb-6">
        <p class="font-semibold">A aprovação interna não garante estoque nem crédito no distribuidor.</p>
        <p class="mt-1">Depois do último nível aprovado, o pedido é enviado para confirmação: o distribuidor confere preços, estoque e crédito nesse momento. Se algo mudar, o pedido pode voltar para aprovação ou ficar aprovado sem confirmação.</p>
    </x-ui.alert>

    @if ($order->confirmation_issue)
        <x-ui.alert type="warning" class="mb-6">
            Aprovado internamente, ainda não confirmado com o distribuidor: {{ $order->confirmation_issue }}
        </x-ui.alert>
    @endif

    <section class="fv-card mb-6 p-5" aria-labelledby="aprovacao-situacao">
        <h2 id="aprovacao-situacao" class="text-lg font-semibold">Situação</h2>
        <dl class="mt-3 grid gap-4 sm:grid-cols-4">
            <div>
                <dt class="text-xs font-medium uppercase text-slate-500">Comercial</dt>
                <dd class="mt-1">@include('livewire.portal.ordering.partials.status-badge', ['status' => $order->commercial_status])</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase text-slate-500">Aprovação interna</dt>
                <dd class="mt-1">@include('livewire.portal.ordering.partials.status-badge', ['status' => $order->approval_status])</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase text-slate-500">Regra aplicada</dt>
                <dd class="mt-1 text-sm">{{ $order->approval_rule_name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase text-slate-500">Nível atual</dt>
                <dd class="mt-1 text-sm">
                    @if ($current)
                        Nível {{ $current->step->level_number }} de {{ count($review->steps) }} ·
                        {{ $current->approvals() }} de {{ $current->step->required_approvals }} {{ $current->step->required_approvals === 1 ? 'aprovação' : 'aprovações' }}
                        <span class="block text-xs text-slate-600">Aguardando há {{ \App\Actions\Approvals\Queries\InboxEntry::elapsed($submittedAt, $now) }}</span>
                    @else
                        Nenhum nível pendente
                    @endif
                </dd>
            </div>
        </dl>
        @if ($order->cancel_reason)
            <p class="mt-3 text-sm text-slate-700">Motivo registrado: {{ $order->cancel_reason }}</p>
        @endif
    </section>

    @if ($current)
        @if ($review->blocked())
            <section class="fv-card mb-6 border-red-200 p-5" aria-labelledby="aprovacao-bloqueio">
                <h2 id="aprovacao-bloqueio" class="text-lg font-semibold text-red-800">Nível {{ $current->step->level_number }} bloqueado</h2>
                <p class="mt-1 text-sm text-slate-700">
                    Não há aprovadores elegíveis suficientes para atingir o quórum de {{ $current->step->required_approvals }} {{ $current->step->required_approvals === 1 ? 'aprovação' : 'aprovações' }}: aprovadores revogados ou sem permissão de aprovar não contam.
                    @unless ($review->canReassign)
                        A administração da empresa precisa reatribuir os aprovadores deste nível.
                    @endunless
                </p>

                @if ($review->canReassign)
                    <form wire:submit="reassign" class="mt-4 space-y-4">
                        <fieldset aria-describedby="reatribuir-ajuda">
                            <legend class="fv-label">Aprovadores do nível {{ $current->step->level_number }}</legend>
                            <p id="reatribuir-ajuda" class="mb-2 text-xs text-slate-600">Aprovações já registradas permanecem. Quem solicitou o pedido não pode aprovar.</p>
                            @if ($review->candidates === [])
                                <p class="text-sm text-slate-700">Nenhum usuário ativo da empresa com permissão de aprovar está disponível. Conceda a permissão a alguém antes de reatribuir.</p>
                            @else
                                <ul class="grid gap-2 sm:grid-cols-2">
                                    @foreach ($review->candidates as $candidateId => $candidateName)
                                        <li wire:key="candidato-{{ $candidateId }}">
                                            <label class="flex items-center gap-2 text-sm text-slate-800">
                                                <input type="checkbox" value="{{ $candidateId }}" wire:model="approvers" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                                                {{ $candidateName }}
                                            </label>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @error('approvers')
                                <p class="mt-1 text-xs text-red-700" role="alert">{{ $message }}</p>
                            @enderror
                        </fieldset>
                        <x-ui.textarea label="Motivo da reatribuição" name="reassignReason" rows="2" maxlength="500" wire:model="reassignReason" />
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="reassign">Reatribuir aprovadores</x-ui.button>
                    </form>
                @endif
            </section>
        @endif

        <section class="fv-card mb-6 p-5" aria-labelledby="aprovacao-decisao">
            <h2 id="aprovacao-decisao" class="text-lg font-semibold">Sua decisão</h2>
            @if ($review->canDecide())
                @if ($review->principalName)
                    <p class="mt-1 text-sm text-slate-700">Você decide em nome de <span class="font-medium">{{ $review->principalName }}</span>, por delegação vigente.</p>
                @endif
                <form wire:submit="approve" class="mt-3 space-y-3">
                    <x-ui.textarea label="Comentário (obrigatório para rejeitar)" name="comment" rows="3" maxlength="1000" wire:model="comment" />
                    <div class="flex flex-wrap gap-3">
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="approve,reject">Aprovar</x-ui.button>
                        <x-ui.button variant="danger" wire:click="reject" wire:confirm="Rejeitar este pedido? A rejeição encerra o pedido e libera o orçamento reservado." wire:loading.attr="disabled" wire:target="approve,reject">
                            Rejeitar
                        </x-ui.button>
                    </div>
                </form>
            @else
                <p class="mt-1 text-sm text-slate-700">{{ $current->unavailableReason ?? 'Você não pode decidir este nível agora.' }}</p>
            @endif
        </section>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="fv-card" aria-labelledby="aprovacao-itens">
                <div class="p-5 pb-0">
                    <h2 id="aprovacao-itens" class="text-lg font-semibold">Itens</h2>
                    <p class="mt-1 text-sm text-slate-600">Preços e quantidades registrados na submissão para aprovação.</p>
                </div>
                <div class="mt-4 overflow-x-auto">
                    <table class="fv-table">
                        <caption class="sr-only">Itens do pedido {{ $order->number }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">Item</th>
                                <th scope="col" class="text-right">Quantidade</th>
                                <th scope="col" class="text-right">Preço unitário</th>
                                <th scope="col" class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($order->items as $item)
                                <tr wire:key="item-{{ $item->id }}">
                                    <td>
                                        <p class="font-medium text-slate-900">{{ $item->product_name }}</p>
                                        <p class="font-mono text-xs text-slate-600">{{ $item->sku_code }}</p>
                                        @if ($item->variant_label || $item->package_label)
                                            <p class="text-xs text-slate-600">{{ implode(' · ', array_filter([$item->variant_label, $item->package_label])) }}</p>
                                        @endif
                                    </td>
                                    <td class="text-right">{{ $item->quantity->format() }} {{ $item->unit_code }}</td>
                                    <td class="text-right">{{ $item->unit_net_price_cents->format() }}</td>
                                    <td class="text-right font-medium">{{ $item->line_total_cents->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <dl class="ml-auto grid max-w-sm grid-cols-2 gap-x-6 gap-y-1 p-5 text-sm" aria-label="Totais do pedido">
                    <dt class="text-slate-600">Subtotal</dt>
                    <dd class="text-right">{{ $order->subtotal_cents->format() }}</dd>
                    <dt class="text-slate-600">Descontos</dt>
                    <dd class="text-right">{{ $order->discount_cents->format() }}</dd>
                    <dt class="text-slate-600">Frete</dt>
                    <dd class="text-right">{{ $order->freight_cents->isZero() ? 'A combinar' : $order->freight_cents->format() }}</dd>
                    <dt class="font-semibold text-slate-900">Total</dt>
                    <dd class="text-right text-lg font-semibold text-slate-900">{{ $order->total_cents->format() }}</dd>
                </dl>
            </section>

            <section class="fv-card p-5" aria-labelledby="aprovacao-niveis">
                <h2 id="aprovacao-niveis" class="text-lg font-semibold">Níveis de aprovação</h2>
                <p class="mt-1 text-sm text-slate-600">Os níveis são decididos em sequência. Quem solicitou o pedido não conta como aprovador.</p>
                <ol class="mt-4 space-y-5">
                    @foreach ($review->steps as $level)
                        <li wire:key="nivel-{{ $level->step->id }}" @class(['rounded-md border p-4', 'border-brand-300 bg-brand-50/40' => $level->current, 'border-slate-200' => ! $level->current])>
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold text-slate-900">Nível {{ $level->step->level_number }}</h3>
                                <x-ui.badge :color="match ($level->step->status) { \App\Enums\Approvals\ApprovalStepStatus::Approved => 'green', \App\Enums\Approvals\ApprovalStepStatus::Rejected => 'red', \App\Enums\Approvals\ApprovalStepStatus::Cancelled, \App\Enums\Approvals\ApprovalStepStatus::Superseded => 'slate', default => 'amber' }">{{ $level->step->status->label() }}</x-ui.badge>
                                @if ($level->current)
                                    <x-ui.badge color="brand">Nível atual</x-ui.badge>
                                @endif
                                @if ($level->blocked)
                                    <x-ui.badge color="red">Bloqueado</x-ui.badge>
                                @endif
                            </div>
                            <p class="mt-1 text-sm text-slate-700">
                                Quórum: {{ $level->approvals }} de {{ $level->step->required_approvals }} {{ $level->step->required_approvals === 1 ? 'aprovação' : 'aprovações' }}
                            </p>

                            <h4 class="mt-3 text-xs font-medium uppercase text-slate-500">Aprovadores</h4>
                            <ul class="mt-1 space-y-1">
                                @forelse ($level->approvers as $approver)
                                    <li wire:key="aprovador-{{ $level->step->id }}-{{ $approver['id'] }}" class="flex flex-wrap items-center gap-2 text-sm">
                                        <span class="text-slate-900">{{ $approver['id'] === $membershipId ? $approver['name'].' (você)' : $approver['name'] }}</span>
                                        <x-ui.badge :color="$approver['color']">{{ $approver['situation'] }}</x-ui.badge>
                                    </li>
                                @empty
                                    <li class="text-sm text-slate-600">Nenhum aprovador definido.</li>
                                @endforelse
                            </ul>

                            @if ($level->decisions !== [])
                                <h4 class="mt-3 text-xs font-medium uppercase text-slate-500">Decisões</h4>
                                <ul class="mt-1 space-y-2">
                                    @foreach ($level->decisions as $decision)
                                        <li wire:key="decisao-{{ $decision['id'] }}" @class(['text-sm', 'text-slate-500' => $decision['invalidatedAt'] !== null])>
                                            <p>
                                                <span class="font-medium">{{ $decision['decision']->label() }}</span>
                                                por {{ $decision['actor'] }}
                                                @if ($decision['principal'])
                                                    em nome de {{ $decision['principal'] }} (delegação)
                                                @endif
                                                · <time datetime="{{ $decision['at']->toIso8601String() }}">{{ $decision['at']->setTimezone($timezone)->format('d/m/Y H:i') }}</time>
                                            </p>
                                            @if ($decision['comment'])
                                                <p class="whitespace-pre-line text-slate-700">“{{ $decision['comment'] }}”</p>
                                            @endif
                                            @if ($decision['invalidatedAt'])
                                                <p class="text-xs text-amber-800">Invalidada em {{ $decision['invalidatedAt']->setTimezone($timezone)->format('d/m/Y H:i') }}: o pedido mudou e voltou para aprovação.</p>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </section>

            <section class="fv-card p-5" aria-labelledby="aprovacao-historico">
                <h2 id="aprovacao-historico" class="text-lg font-semibold">Histórico</h2>
                @if ($review->history === [])
                    <p class="mt-2 text-sm text-slate-600">Nenhum evento registrado.</p>
                @else
                    <ol class="mt-3 space-y-3 border-l border-slate-200 pl-4">
                        @foreach ($review->history as $event)
                            <li wire:key="evento-{{ $event['id'] }}">
                                <p class="text-sm font-medium text-slate-900">{{ $event['label'] }}</p>
                                <p class="text-xs text-slate-600">
                                    <time datetime="{{ $event['at']->toIso8601String() }}">{{ $event['at']->setTimezone($timezone)->format('d/m/Y H:i') }}</time>
                                    @if ($event['actor'])
                                        · {{ $event['actor'] }}
                                    @endif
                                </p>
                                @foreach ($event['details'] as $detail)
                                    <p class="text-xs text-slate-700">{{ $detail }}</p>
                                @endforeach
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>

        <aside class="space-y-6" aria-label="Condições do pedido">
            <section class="fv-card p-5" aria-labelledby="aprovacao-condicoes">
                <h2 id="aprovacao-condicoes" class="text-lg font-semibold">Condições</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div><dt class="text-slate-600">Solicitante</dt><dd>{{ $review->requesterName ?? '—' }}</dd></div>
                    <div><dt class="text-slate-600">Centro de custo</dt><dd>{{ $review->costCenterName ?? 'Não informado' }}</dd></div>
                    @if ($review->unitName)
                        <div><dt class="text-slate-600">Unidade</dt><dd>{{ $review->unitName }}</dd></div>
                    @endif
                    <div><dt class="text-slate-600">Condição de pagamento</dt><dd>{{ $order->payment_term['name'] ?? '—' }}</dd></div>
                    <div><dt class="text-slate-600">Ordem de compra</dt><dd>{{ $order->purchase_order_reference ?? 'Não informada' }}</dd></div>
                    @if ($order->notes)
                        <div><dt class="text-slate-600">Observação</dt><dd class="whitespace-pre-line">{{ $order->notes }}</dd></div>
                    @endif
                </dl>
            </section>

            <section class="fv-card p-5" aria-labelledby="aprovacao-entrega">
                <h2 id="aprovacao-entrega" class="text-lg font-semibold">Entrega</h2>
                <div class="mt-2">@include('livewire.portal.ordering.partials.address', ['address' => $order->shipping_address])</div>
            </section>

            @if ($order->billing_address)
                <section class="fv-card p-5" aria-labelledby="aprovacao-cobranca">
                    <h2 id="aprovacao-cobranca" class="text-lg font-semibold">Cobrança</h2>
                    <div class="mt-2">@include('livewire.portal.ordering.partials.address', ['address' => $order->billing_address])</div>
                </section>
            @endif
        </aside>
    </div>
</div>

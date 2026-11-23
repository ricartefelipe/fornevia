@php($timezone = (string) config('app.business_timezone'))
<div>
    <nav aria-label="Trilha de navegação" class="mb-3 text-sm text-slate-600">
        <a href="{{ route('portal.orders') }}" class="font-medium text-brand-700 hover:underline">Pedidos</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{{ $order->number }}</span>
    </nav>

    <x-ui.page-header :title="'Pedido '.$order->number" :description="'Feito em '.$order->created_at->setTimezone($timezone)->format('d/m/Y').' às '.$order->created_at->setTimezone($timezone)->format('H:i').($placedBy ? ' por '.$placedBy : '').'.'">
        <x-slot:actions>
            @if ($canReorder)
                <x-ui.button wire:click="reorder" wire:loading.attr="disabled" wire:target="reorder">Comprar novamente</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($quoteOrigin)
        <p class="mb-4 text-sm text-slate-700">
            Origem: cotação <a href="{{ route('portal.quotes.show', $quoteOrigin->quote_id) }}" class="font-medium text-blue-800 underline">{{ $quoteOrigin->quote->number }}</a>,
            versão {{ $quoteOrigin->version_number }}. Os preços negociados ficam mantidos.
        </p>
    @endif

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)
            <x-ui.alert :type="$messageType">{{ $message }}</x-ui.alert>
        @endif
        @if ($failure)
            <x-ui.alert type="error">{{ $failure }}</x-ui.alert>
        @endif
    </div>

    @if ($report)
        <div class="mb-6">
            @include('livewire.portal.ordering.partials.addition-report', [
                'report' => $report,
                'reportId' => 'pedido-recompra',
                'reportTitle' => 'Recompra: itens com preço e disponibilidade de hoje',
            ])
        </div>
    @endif

    <section class="fv-card mb-6 p-5" aria-labelledby="pedido-situacao">
        <h2 id="pedido-situacao" class="text-lg font-semibold">Situação</h2>
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
                <dt class="text-xs font-medium uppercase text-slate-500">Atendimento</dt>
                <dd class="mt-1">@include('livewire.portal.ordering.partials.status-badge', ['status' => $order->fulfillment_status])</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase text-slate-500">Financeiro</dt>
                <dd class="mt-1">@include('livewire.portal.ordering.partials.status-badge', ['status' => $order->financial_status])</dd>
            </div>
        </dl>
        @if ($order->cancel_reason)
            <p class="mt-3 text-sm text-slate-700">
                {{ $order->commercial_status === \App\Enums\Ordering\CommercialStatus::Rejected ? 'Motivo da rejeição' : 'Motivo do último cancelamento' }}: {{ $order->cancel_reason }}
            </p>
        @endif

        @if ($order->commercial_status === \App\Enums\Ordering\CommercialStatus::PendingApproval)
            <x-ui.alert type="info" class="mt-4">
                Este pedido aguarda a aprovação interna da sua empresa. Estoque e crédito ainda não foram reservados: a aprovação interna não garante
                estoque nem crédito no distribuidor, que são conferidos somente na confirmação, depois da aprovação.
                @if ($pendingIssue = \App\Actions\Approvals\Support\OrderApprovalSummary::pendingIssue($order))
                    <span class="mt-1 block">Na reavaliação, o novo total não coube no orçamento do centro de custo: {{ $pendingIssue }}</span>
                @endif
            </x-ui.alert>
        @endif

        @if (\App\Actions\Approvals\Support\OrderApprovalSummary::awaitsConfirmation($order))
            <x-ui.alert type="warning" class="mt-4">
                <p class="font-semibold">Aprovado internamente, mas ainda não confirmado com o distribuidor.</p>
                @if ($order->confirmation_issue)
                    <p class="mt-1">Motivo: {{ $order->confirmation_issue }}</p>
                @endif
                <p class="mt-1">A aprovação interna não garante estoque nem crédito no distribuidor. Resolvido o motivo, tente confirmar de novo; preços e condições são conferidos outra vez.</p>
                @if ($canRetry)
                    <div class="mt-3">
                        <x-ui.button wire:click="retryConfirmation" wire:loading.attr="disabled" wire:target="retryConfirmation">Tentar confirmar novamente</x-ui.button>
                        <span wire:loading wire:target="retryConfirmation" class="ml-2 text-sm" role="status">Confirmando com o distribuidor…</span>
                    </div>
                @endif
            </x-ui.alert>
        @endif
    </section>

    @if ($approvalLevels !== [])
        <section class="fv-card mb-6 p-5" aria-labelledby="pedido-aprovacao">
            <h2 id="pedido-aprovacao" class="text-lg font-semibold">Aprovação interna</h2>
            <dl class="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-3">
                @if ($order->approval_rule_name)
                    <div><dt class="text-slate-600">Regra aplicada</dt><dd>{{ $order->approval_rule_name }}</dd></div>
                @endif
                @if ($order->submitted_at)
                    <div><dt class="text-slate-600">Enviado para aprovação em</dt><dd>{{ $order->submitted_at->setTimezone($timezone)->format('d/m/Y H:i') }}</dd></div>
                @endif
                @if ($order->approved_at)
                    <div><dt class="text-slate-600">Aprovado em</dt><dd>{{ $order->approved_at->setTimezone($timezone)->format('d/m/Y H:i') }}</dd></div>
                @endif
            </dl>
            @include('livewire.portal.ordering.partials.approval-levels', ['levels' => $approvalLevels, 'levelsLabel' => 'Níveis de aprovação do pedido '.$order->number])
        </section>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="fv-card" aria-labelledby="pedido-itens">
                <div class="p-5 pb-0">
                    <h2 id="pedido-itens" class="text-lg font-semibold">Itens</h2>
                    @if ($canCancel)
                        <p class="mt-1 text-sm text-slate-600">Para cancelar parte do pedido, informe a quantidade em cada item e o motivo. Itens já expedidos exigem devolução.</p>
                    @endif
                </div>

                <form wire:submit="cancelItems">
                    <div class="mt-4 overflow-x-auto">
                        <table class="fv-table">
                            <caption class="sr-only">Itens do pedido {{ $order->number }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Item</th>
                                    <th scope="col" class="text-right">Pedida</th>
                                    <th scope="col" class="text-right">Cancelada</th>
                                    <th scope="col" class="text-right">Expedida</th>
                                    <th scope="col" class="text-right">Preço unitário</th>
                                    <th scope="col" class="text-right">Total</th>
                                    @if ($canCancel)
                                        <th scope="col">Cancelar</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($order->items as $item)
                                    @php($open = \App\Actions\Ordering\Portal\BuyerOrders::cancellable($item))
                                    @php($inputId = 'cancelar-'.$item->id)
                                    @php($error = $errors->first('cancelQuantities.'.$item->id))
                                    <tr wire:key="item-{{ $item->id }}">
                                        <td>
                                            <p class="font-medium text-slate-900">{{ $item->product_name }}</p>
                                            <p class="font-mono text-xs text-slate-600">{{ $item->sku_code }}</p>
                                            @if ($item->variant_label || $item->package_label)
                                                <p class="text-xs text-slate-600">{{ implode(' · ', array_filter([$item->variant_label, $item->package_label])) }}</p>
                                            @endif
                                            @if ($item->quantity_backordered->isPositive())
                                                <p class="text-xs text-amber-800">Sob encomenda: {{ $item->backorderedInSalesUnit()->format() }} {{ $item->unit_code }}</p>
                                            @endif
                                        </td>
                                        <td class="text-right">{{ $item->quantity->format() }} {{ $item->unit_code }}</td>
                                        <td class="text-right">{{ $item->quantity_cancelled->format() }}</td>
                                        <td class="text-right">{{ $item->quantity_shipped->format() }}</td>
                                        <td class="text-right">{{ $item->unit_net_price_cents->format() }}</td>
                                        <td class="text-right font-medium">{{ $item->line_total_cents->format() }}</td>
                                        @if ($canCancel)
                                            <td class="min-w-40">
                                                @if ($open->isPositive())
                                                    <label for="{{ $inputId }}" class="sr-only">Quantidade de {{ $item->sku_code }} a cancelar, até {{ $open->format() }} {{ $item->unit_code }}</label>
                                                    <input id="{{ $inputId }}" type="text" inputmode="decimal" autocomplete="off" maxlength="20"
                                                        placeholder="até {{ $open->format() }}"
                                                        wire:model="cancelQuantities.{{ $item->id }}"
                                                        @if ($error) aria-invalid="true" aria-describedby="{{ $inputId }}-erro" @endif
                                                        @class(['fv-input w-28', 'fv-input-error' => $error])>
                                                    @if ($error)
                                                        <p id="{{ $inputId }}-erro" class="mt-1 text-xs text-red-700">{{ $error }}</p>
                                                    @endif
                                                @else
                                                    <span class="text-xs text-slate-500">Sem saldo a cancelar</span>
                                                @endif
                                            </td>
                                        @endif
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

                    @if ($canCancel)
                        <div class="space-y-3 border-t border-slate-100 p-5">
                            <h3 class="font-semibold">Cancelamento</h3>
                            @error('cancelQuantities')
                                <x-ui.alert type="error">{{ $message }}</x-ui.alert>
                            @enderror
                            <x-ui.textarea label="Motivo do cancelamento" name="cancelReason" rows="2" maxlength="500" wire:model="cancelReason" />
                            <div class="flex flex-wrap gap-3">
                                <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled" wire:target="cancelItems">Cancelar as quantidades informadas</x-ui.button>
                                <x-ui.button variant="danger" wire:click="cancelAll" wire:confirm="Cancelar todos os itens ainda não expedidos deste pedido?" wire:loading.attr="disabled">
                                    Cancelar o pedido inteiro
                                </x-ui.button>
                            </div>
                        </div>
                    @endif
                </form>
            </section>

            @if ($canCancelUnconfirmed)
                <section class="fv-card p-5" aria-labelledby="pedido-cancelamento">
                    <h2 id="pedido-cancelamento" class="text-lg font-semibold">Cancelamento</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        O pedido ainda não foi confirmado com o distribuidor e só pode ser cancelado por inteiro. A reserva de orçamento do centro de custo é liberada.
                    </p>
                    <form wire:submit="cancelAll" class="mt-3 space-y-3">
                        <x-ui.textarea label="Motivo do cancelamento" name="cancelReason" rows="2" maxlength="500" wire:model="cancelReason" />
                        <x-ui.button type="submit" variant="danger" wire:confirm="Cancelar o pedido {{ $order->number }} por inteiro?" wire:loading.attr="disabled" wire:target="cancelAll">
                            Cancelar o pedido
                        </x-ui.button>
                    </form>
                </section>
            @endif

            <section class="fv-card p-5" aria-labelledby="pedido-historico">
                <h2 id="pedido-historico" class="text-lg font-semibold">Histórico</h2>
                @if ($events === [])
                    <p class="mt-2 text-sm text-slate-600">Nenhum evento registrado.</p>
                @else
                    <ol class="mt-3 space-y-3 border-l border-slate-200 pl-4">
                        @foreach ($events as $event)
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

        <aside class="space-y-6" aria-label="Entrega e pagamento">
            <section class="fv-card p-5" aria-labelledby="pedido-dados">
                <h2 id="pedido-dados" class="text-lg font-semibold">Dados do pedido</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div><dt class="text-slate-600">Sua ordem de compra</dt><dd>{{ $order->purchase_order_reference ?? 'Não informada' }}</dd></div>
                    <div><dt class="text-slate-600">Condição de pagamento</dt><dd>{{ $order->payment_term['name'] ?? '—' }}</dd></div>
                    @if ($unitName)
                        <div><dt class="text-slate-600">Unidade</dt><dd>{{ $unitName }}</dd></div>
                    @endif
                    @if ($costCenterName)
                        <div><dt class="text-slate-600">Centro de custo</dt><dd>{{ $costCenterName }}</dd></div>
                    @endif
                    @if ($order->notes)
                        <div><dt class="text-slate-600">Observação</dt><dd class="whitespace-pre-line">{{ $order->notes }}</dd></div>
                    @endif
                </dl>
            </section>

            <section class="fv-card p-5" aria-labelledby="pedido-entrega">
                <h2 id="pedido-entrega" class="text-lg font-semibold">Entrega</h2>
                <div class="mt-2">@include('livewire.portal.ordering.partials.address', ['address' => $order->shipping_address])</div>
            </section>

            @if ($order->billing_address)
                <section class="fv-card p-5" aria-labelledby="pedido-cobranca">
                    <h2 id="pedido-cobranca" class="text-lg font-semibold">Cobrança</h2>
                    <div class="mt-2">@include('livewire.portal.ordering.partials.address', ['address' => $order->billing_address])</div>
                </section>
            @endif
        </aside>
    </div>
</div>

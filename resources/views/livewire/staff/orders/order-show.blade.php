@php($timezone = (string) config('app.business_timezone'))
@php($company = $order->buyerCompany)
@php($costs = $detail->costs)
<div>
    <nav aria-label="Trilha de navegação" class="mb-3 text-sm">
        <a href="{{ route('orders.index') }}" class="text-brand-700 hover:underline">Pedidos</a>
        <span class="text-slate-400" aria-hidden="true">/</span>
        <span class="text-slate-600" aria-current="page">{{ $order->number }}</span>
    </nav>

    <x-ui.page-header :title="'Pedido '.$order->number"
        :description="$company->trade_name.' · registrado em '.$order->created_at->setTimezone($timezone)->format('d/m/Y H:i')" />

    @if ($notice)
        <x-ui.alert type="success" class="mb-4">
            <p class="font-semibold">{{ $notice }}</p>
            @if ($releaseNotes !== [])
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($releaseNotes as $note)
                        <li>{{ $note }}</li>
                    @endforeach
                </ul>
            @endif
        </x-ui.alert>
    @endif

    @error('cancellation')
        <x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <section class="fv-card mb-6 p-6" aria-labelledby="order-states-title">
        <h2 id="order-states-title" class="text-lg font-semibold">Situação</h2>
        <dl class="mt-3 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-slate-500">Comercial</dt><dd class="mt-1">@include('livewire.staff.orders.partials.status-badge', ['status' => $order->commercial_status])</dd></div>
            <div><dt class="text-slate-500">Aprovação do comprador</dt><dd class="mt-1">@include('livewire.staff.orders.partials.status-badge', ['status' => $order->approval_status])</dd></div>
            <div><dt class="text-slate-500">Atendimento</dt><dd class="mt-1">@include('livewire.staff.orders.partials.status-badge', ['status' => $order->fulfillment_status])</dd></div>
            <div><dt class="text-slate-500">Financeiro</dt><dd class="mt-1">@include('livewire.staff.orders.partials.status-badge', ['status' => $order->financial_status])</dd></div>
        </dl>
        @if ($order->cancel_reason)
            <p class="mt-4 rounded-md bg-slate-50 p-3 text-sm">
                <span class="font-medium">{{ $order->commercial_status === \App\Enums\Ordering\CommercialStatus::Rejected ? 'Motivo da rejeição pelo comprador:' : 'Motivo do último cancelamento:' }}</span> {{ $order->cancel_reason }}
                @if ($order->cancelled_at)
                    <span class="text-slate-500">· pedido cancelado em {{ $order->cancelled_at->setTimezone($timezone)->format('d/m/Y H:i') }}</span>
                @endif
            </p>
        @endif
        @if ($order->commercial_status === \App\Enums\Ordering\CommercialStatus::PendingApproval)
            <p class="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-900">
                Pedido aguardando a aprovação interna do comprador. Estoque e crédito só são reservados quando ele for aprovado e confirmado.
            </p>
        @elseif (\App\Actions\Approvals\Support\OrderApprovalSummary::awaitsConfirmation($order))
            <p class="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-900">
                <span class="font-medium">Aprovado pelo comprador, ainda não confirmado.</span>
                @if ($order->confirmation_issue)
                    Motivo: {{ $order->confirmation_issue }}
                @endif
            </p>
        @endif
    </section>

    @if ($approvalLevels !== [])
        <section class="fv-card mb-6 p-6" aria-labelledby="order-approval-title">
            <h2 id="order-approval-title" class="text-lg font-semibold">Aprovação interna do comprador</h2>
            <p class="mt-1 text-sm text-slate-600">
                Somente leitura: os níveis e aprovadores foram retratados no envio do pedido, e as decisões são registradas pelos aprovadores da empresa compradora no portal.
            </p>
            <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-3">
                <div><dt class="text-slate-500">Regra aplicada</dt><dd>{{ $order->approval_rule_name ?? 'Não registrada' }}</dd></div>
                <div><dt class="text-slate-500">Enviado para aprovação em</dt><dd>{{ $order->submitted_at?->setTimezone($timezone)->format('d/m/Y H:i') ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">Aprovado em</dt><dd>{{ $order->approved_at?->setTimezone($timezone)->format('d/m/Y H:i') ?? '—' }}</dd></div>
            </dl>
            @include('livewire.portal.ordering.partials.approval-levels', ['levels' => $approvalLevels, 'levelsLabel' => 'Níveis de aprovação do pedido '.$order->number])
        </section>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="fv-card p-6 lg:col-span-2" aria-labelledby="order-header-title">
            <h2 id="order-header-title" class="text-lg font-semibold">Dados do pedido</h2>
            <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-slate-500">Empresa compradora</dt>
                    <dd class="font-medium">
                        @if ($canViewBuyer)
                            <a href="{{ route('buyers.show', ['buyer' => $company->id]) }}" class="text-brand-700 hover:underline">{{ $company->trade_name }}</a>
                        @else
                            {{ $company->trade_name }}
                        @endif
                        <span class="block font-mono text-xs font-normal text-slate-500">CNPJ {{ \App\Domain\Shared\Cnpj::format($company->document) }}</span>
                    </dd>
                </div>
                <div><dt class="text-slate-500">Solicitante</dt><dd>{{ $detail->placedByName() }}</dd></div>
                <div><dt class="text-slate-500">Unidade</dt><dd>{{ $detail->unit ? $detail->unit->name.' ('.$detail->unit->code.')' : 'Não informada' }}</dd></div>
                <div><dt class="text-slate-500">Centro de custo</dt><dd>{{ $detail->costCenter ? $detail->costCenter->name.' ('.$detail->costCenter->code.')' : 'Não informado' }}</dd></div>
                <div><dt class="text-slate-500">Condição de pagamento</dt><dd>{{ $detail->paymentTermLabel() }}</dd></div>
                <div><dt class="text-slate-500">Ordem de compra do cliente</dt><dd>{{ $order->purchase_order_reference ?? 'Não informada' }}</dd></div>
                <div><dt class="text-slate-500">Origem</dt><dd>{{ $order->source->label() }}</dd></div>
                <div><dt class="text-slate-500">Representante na confirmação</dt><dd>{{ $detail->salesRepName ?? 'Sem representante' }}</dd></div>
                <div>
                    <dt class="text-slate-500">Endereço de entrega</dt>
                    <dd>
                        @foreach (\App\Actions\Ordering\Staff\StaffOrderDetail::addressLines($order->shipping_address) as $line)
                            <span class="block">{{ $line }}</span>
                        @endforeach
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-500">Endereço de cobrança</dt>
                    <dd>
                        @forelse (\App\Actions\Ordering\Staff\StaffOrderDetail::addressLines($order->billing_address) as $line)
                            <span class="block">{{ $line }}</span>
                        @empty
                            Não informado
                        @endforelse
                    </dd>
                </div>
                <div class="sm:col-span-2"><dt class="text-slate-500">Observação</dt><dd class="whitespace-pre-line">{{ $order->notes ?? 'Sem observação' }}</dd></div>
            </dl>
        </section>

        <section class="fv-card p-6" aria-labelledby="order-values-title">
            <h2 id="order-values-title" class="text-lg font-semibold">Valores e crédito</h2>
            <dl class="mt-4 space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-slate-500">Subtotal</dt><dd>{{ $order->subtotal_cents->format() }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-slate-500">Descontos</dt><dd>{{ $order->discount_cents->format() }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-slate-500">Frete</dt><dd>{{ $order->freight_cents->format() }}</dd></div>
                <div class="flex justify-between gap-3 border-t border-slate-200 pt-2 font-semibold"><dt>Total do pedido</dt><dd>{{ $order->total_cents->format() }}</dd></div>
                <div class="flex justify-between gap-3 pt-2"><dt class="text-slate-500">Crédito comprometido na confirmação</dt><dd>{{ $detail->creditCommitted()->format() }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-slate-500">Crédito liberado</dt><dd>{{ $detail->creditReleased()->format() }}</dd></div>
                <div class="flex justify-between gap-3 font-semibold"><dt>Crédito comprometido atual</dt><dd>{{ $order->credit_committed_cents->format() }}</dd></div>
            </dl>
            @if ($detail->creditMovements->isEmpty())
                <p class="mt-3 text-xs text-slate-500">A condição de pagamento não usa crédito comercial ou nenhum valor foi comprometido.</p>
            @else
                <h3 class="mt-4 text-sm font-semibold">Movimentos de crédito do pedido</h3>
                <ul class="mt-2 divide-y divide-slate-100 text-sm">
                    @foreach ($detail->creditMovements as $movement)
                        <li class="py-2" wire:key="credit-{{ $movement->id }}">
                            <span class="font-medium">{{ $movement->type->label() }}</span>
                            <span class="whitespace-nowrap">{{ $movement->committed_delta->format() }}</span>
                            <span class="block text-xs text-slate-500">
                                {{ $movement->created_at->setTimezone($timezone)->format('d/m/Y H:i') }} · {{ $detail->actorName($movement) }}
                                @if ($movement->reason) · {{ $movement->reason }} @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <section class="fv-card mt-6 overflow-x-auto" aria-labelledby="order-items-title">
        <h2 id="order-items-title" class="px-6 pt-5 text-lg font-semibold">Itens</h2>
        <p class="px-6 pt-1 text-xs text-slate-500">
            Preços e descrições conforme o snapshot gravado na confirmação. Quantidades pedidas, canceladas, expedidas, entregues e devolvidas
            na unidade de venda; reservadas e encomendadas na unidade base do produto.
        </p>
        <table class="fv-table mt-3">
            <thead>
                <tr>
                    <th scope="col">Linha</th>
                    <th scope="col">Item</th>
                    <th scope="col" class="text-right">Pedida</th>
                    <th scope="col" class="text-right">Preço unitário</th>
                    <th scope="col" class="text-right">Desconto</th>
                    <th scope="col" class="text-right">Preço líquido</th>
                    <th scope="col" class="text-right">Total</th>
                    <th scope="col">Origem do preço</th>
                    <th scope="col" class="text-right">Reservada</th>
                    <th scope="col" class="text-right">Encomendada</th>
                    <th scope="col" class="text-right">Cancelada</th>
                    <th scope="col" class="text-right">Expedida</th>
                    <th scope="col" class="text-right">Entregue</th>
                    <th scope="col" class="text-right">Devolvida</th>
                    @if ($costs !== null)
                        <th scope="col" class="text-right">Custo unitário</th>
                        <th scope="col" class="text-right">Margem</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($detail->items as $item)
                    @php($base = $detail->baseUnit($item))
                    <tr wire:key="item-{{ $item->id }}">
                        <td>{{ $item->line_number }}</td>
                        <td>
                            <span class="font-mono font-medium">{{ $item->sku_code }}</span>
                            <span class="block">{{ $item->product_name }}{{ $item->variant_label ? ' · '.$item->variant_label : '' }}</span>
                            @if ($item->package_label)
                                <span class="block text-xs text-slate-500">{{ $item->package_label }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap text-right">{{ $item->quantity->format() }} {{ $item->unit_code }}</td>
                        <td class="whitespace-nowrap text-right">{{ $item->unit_price_cents->format() }}</td>
                        <td class="whitespace-nowrap text-right">{{ \App\Domain\Shared\Percentage::ofBasisPoints($item->discount_bps)->format() }}</td>
                        <td class="whitespace-nowrap text-right">{{ $item->unit_net_price_cents->format() }}</td>
                        <td class="whitespace-nowrap text-right font-medium">{{ $item->line_total_cents->format() }}</td>
                        <td class="text-xs">{{ $detail->priceOrigin($item) }}</td>
                        <td class="whitespace-nowrap text-right">{{ $item->quantity_reserved->format() }} {{ $base }}</td>
                        <td class="whitespace-nowrap text-right">{{ $item->quantity_backordered->format() }} {{ $base }}</td>
                        <td class="whitespace-nowrap text-right">{{ $item->quantity_cancelled->format() }} {{ $item->unit_code }}</td>
                        <td class="whitespace-nowrap text-right">{{ $item->quantity_shipped->format() }} {{ $item->unit_code }}</td>
                        <td class="whitespace-nowrap text-right">{{ $item->quantity_delivered->format() }} {{ $item->unit_code }}</td>
                        <td class="whitespace-nowrap text-right">{{ $item->quantity_returned->format() }} {{ $item->unit_code }}</td>
                        @if ($costs !== null)
                            @php($cost = $costs[$item->id] ?? null)
                            <td class="whitespace-nowrap text-right">{{ $cost?->unitCost?->format() ?? 'Sem custo registrado' }}</td>
                            <td class="whitespace-nowrap text-right">
                                @if ($cost?->margin)
                                    {{ $cost->margin->format() }}
                                    <span class="block text-xs text-slate-500">{{ $cost->lineMargin?->format() }} na linha</span>
                                @else
                                    —
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <section class="fv-card overflow-x-auto" aria-labelledby="order-reservations-title">
            <h2 id="order-reservations-title" class="px-6 pt-5 text-lg font-semibold">Reservas por depósito</h2>
            @if ($detail->reservationsByWarehouse === [])
                <div class="p-6">
                    <x-ui.empty-state title="Nenhuma reserva de estoque" description="Itens sem saldo disponível ficam como encomenda, sem reserva." />
                </div>
            @else
                @foreach ($detail->reservationsByWarehouse as $group)
                    <h3 class="px-6 pt-4 text-sm font-semibold" id="wh-{{ $group['warehouse']->id }}">
                        {{ $group['warehouse']->code }} · {{ $group['warehouse']->name }}
                    </h3>
                    <table class="fv-table mt-2" aria-labelledby="wh-{{ $group['warehouse']->id }}">
                        <thead>
                            <tr>
                                <th scope="col">SKU</th>
                                <th scope="col" class="text-right">Reservada</th>
                                <th scope="col" class="text-right">Consumida</th>
                                <th scope="col" class="text-right">Liberada</th>
                                <th scope="col" class="text-right">Em aberto</th>
                                <th scope="col">Situação</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($group['reservations'] as $reservation)
                                @php($reservedItem = $detail->items->firstWhere('id', $reservation->order_item_id))
                                @php($unit = $reservedItem ? $detail->baseUnit($reservedItem) : '')
                                <tr wire:key="reservation-{{ $reservation->id }}">
                                    <td class="font-mono">{{ $reservedItem?->sku_code }}</td>
                                    <td class="whitespace-nowrap text-right">{{ $reservation->quantity->format() }} {{ $unit }}</td>
                                    <td class="whitespace-nowrap text-right">{{ $reservation->quantity_consumed->format() }} {{ $unit }}</td>
                                    <td class="whitespace-nowrap text-right">{{ $reservation->quantity_released->format() }} {{ $unit }}</td>
                                    <td class="whitespace-nowrap text-right font-medium">{{ $reservation->quantity->minus($reservation->quantity_consumed)->minus($reservation->quantity_released)->format() }} {{ $unit }}</td>
                                    <td><x-ui.badge :color="$reservation->status->value === 'active' ? 'green' : 'slate'">{{ $reservation->status->label() }}</x-ui.badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endforeach
                <div class="pb-4"></div>
            @endif
        </section>

        <section class="fv-card p-6" aria-labelledby="order-events-title">
            <h2 id="order-events-title" class="text-lg font-semibold">Histórico</h2>
            @if ($detail->events->isEmpty())
                <p class="mt-3 text-sm text-slate-600">Nenhum evento registrado.</p>
            @else
                <ol class="mt-3 space-y-3 text-sm">
                    @foreach ($detail->events as $event)
                        <li class="border-l-2 border-slate-200 pl-3" wire:key="event-{{ $event->id }}">
                            <p>
                                <span class="font-medium">{{ $approvalEventLabels[$event->type] ?? $detail->eventLabel($event) }}</span>
                                <time class="text-slate-500" datetime="{{ $event->created_at->toIso8601String() }}">
                                    · {{ $event->created_at->setTimezone($timezone)->format('d/m/Y H:i') }}
                                </time>
                                <span class="text-slate-500">· {{ $detail->actorName($event) }}</span>
                            </p>
                            @foreach ($eventDetails[$event->id] ?? [] as $line)
                                <p class="text-slate-600">{{ $line }}</p>
                            @endforeach
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    </div>

    @if ($canManage && $detail->isCancellable() && $detail->isUnconfirmed())
        <section class="fv-card mt-6 p-6" aria-labelledby="order-cancel-title">
            <h2 id="order-cancel-title" class="text-lg font-semibold">Cancelar pedido não confirmado</h2>
            <p class="mt-1 text-sm text-slate-600">
                O pedido ainda não foi confirmado: não há reserva de estoque nem crédito comprometido. O cancelamento é do pedido inteiro
                e libera a reserva de orçamento do comprador.
            </p>

            <form wire:submit="cancelAll" class="mt-4 space-y-4" novalidate>
                <x-ui.textarea label="Motivo do cancelamento" name="cancelReason" wire:model="cancelReason" rows="2" maxlength="500"></x-ui.textarea>
                <x-ui.button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="cancelAll"
                    wire:confirm="Cancelar o pedido {{ $order->number }}?">
                    Cancelar o pedido
                </x-ui.button>
            </form>
        </section>
    @elseif ($canManage && $detail->isCancellable())
        <section class="fv-card mt-6 p-6" aria-labelledby="order-cancel-title">
            <h2 id="order-cancel-title" class="text-lg font-semibold">Cancelar itens não expedidos</h2>
            <p class="mt-1 text-sm text-slate-600">
                O cancelamento retira primeiro a parte encomendada, depois libera a reserva de estoque, e libera o crédito comprometido
                correspondente ao valor cancelado. Itens já expedidos exigem devolução.
            </p>

            @error('quantities')
                <x-ui.alert type="error" class="mt-4">{{ $message }}</x-ui.alert>
            @enderror

            <form wire:submit="cancelSelected" class="mt-4 space-y-4" novalidate>
                <table class="fv-table">
                    <thead>
                        <tr>
                            <th scope="col">Item</th>
                            <th scope="col" class="text-right">Saldo cancelável</th>
                            <th scope="col">Quantidade a cancelar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($detail->items as $item)
                            @php($open = \App\Actions\Ordering\Staff\StaffOrderDetail::openQuantity($item))
                            @if ($open->isPositive())
                                @php($decimals = $detail->unitDecimals($item))
                                <tr wire:key="cancel-{{ $item->id }}">
                                    <td>
                                        <span class="font-mono font-medium">{{ $item->sku_code }}</span>
                                        <span class="block text-xs text-slate-500">{{ $item->product_name }}</span>
                                    </td>
                                    <td class="whitespace-nowrap text-right">{{ $open->format() }} {{ $item->unit_code }}</td>
                                    <td class="w-56">
                                        <x-ui.input :label="'Quantidade a cancelar de '.$item->sku_code.' ('.$item->unit_code.')'" :name="'quantities.'.$item->id"
                                            :id="'cancel-qty-'.$item->id" class="text-right" inputmode="decimal" maxlength="20" autocomplete="off"
                                            wire:model="quantities.{{ $item->id }}"
                                            :hint="$decimals === 0 ? 'Somente inteiros.' : 'Até '.$decimals.' casas decimais.'" />
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>

                <x-ui.textarea label="Motivo do cancelamento" name="cancelReason" wire:model="cancelReason" rows="2" maxlength="500"
                    :aria-describedby="$errors->has('cancelReason') ? null : 'cancel-reason-hint'"></x-ui.textarea>
                @unless ($errors->has('cancelReason'))
                    <p id="cancel-reason-hint" class="-mt-3 text-xs text-slate-500">Obrigatório (mínimo de 5 caracteres); fica registrado no histórico e na auditoria.</p>
                @endunless

                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="cancelSelected,cancelAll">
                        Cancelar as quantidades informadas
                    </x-ui.button>
                    <x-ui.button variant="secondary" wire:click="cancelAll" wire:loading.attr="disabled" wire:target="cancelSelected,cancelAll"
                        wire:confirm="Cancelar todo o saldo não expedido do pedido {{ $order->number }}?">
                        Cancelar todo o saldo do pedido
                    </x-ui.button>
                </div>
                <p wire:loading.delay wire:target="cancelSelected,cancelAll" class="text-xs text-slate-500" role="status">Registrando o cancelamento…</p>
            </form>
        </section>
    @elseif (! $canManage)
        <x-ui.alert type="info" class="mt-6">Seu perfil permite consultar o pedido. Cancelar itens exige a permissão de gerenciar pedidos.</x-ui.alert>
    @endif
</div>

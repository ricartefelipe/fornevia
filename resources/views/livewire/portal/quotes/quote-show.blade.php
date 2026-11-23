@php($timezone = (string) config('app.business_timezone'))
@php($at = static fn ($instant): string => $instant->setTimezone($timezone)->format('d/m/Y H:i'))
<div>
    <x-ui.page-header :title="'Cotação '.$quote->number" :description="\App\Livewire\Portal\Quotes\QuotesIndex::effectiveLabel($quote, $current, $now)">
        <x-slot:actions>
            <a href="{{ route('portal.quotes') }}" class="fv-btn-secondary">Voltar às cotações</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)
            <x-ui.alert type="success">{{ $message }}</x-ui.alert>
        @endif
        @if ($failure)
            <x-ui.alert type="error">{{ $failure }}</x-ui.alert>
        @endif
    </div>

    @if ($order)
        <x-ui.alert type="info" class="mb-6">
            Esta cotação gerou o pedido
            <a href="{{ route('portal.orders.show', $order->id) }}" class="font-semibold underline">{{ $order->number }}</a>.
            Os preços da proposta ficam mantidos no pedido.
        </x-ui.alert>
    @endif

    @if ($current)
        <section class="fv-card mb-6 p-5" aria-labelledby="cotacao-proposta">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="cotacao-proposta" class="text-lg font-semibold">Proposta vigente · versão {{ $current->version_number }}</h2>
                <p class="text-sm {{ $expired ? 'font-semibold text-red-700' : 'text-slate-700' }}">
                    {{ $expired ? 'Vencida em '.$at($current->valid_until) : 'Válida até '.$at($current->valid_until) }}
                </p>
            </div>
            <p class="mt-1 text-sm text-slate-700">Condição de pagamento: {{ $current->payment_term['name'] ?? '—' }}</p>
            @if ($current->notes)
                <p class="mt-2 whitespace-pre-line text-sm text-slate-800">{{ $current->notes }}</p>
            @endif

            @if ($holdActive)
                <x-ui.alert type="success" class="mt-4">
                    Estoque reservado para esta proposta até {{ $at($current->stock_hold_until) }}. Depois disso, a reserva é liberada.
                </x-ui.alert>
            @elseif ($current->stock_hold_status === \App\Enums\Quotes\StockHoldStatus::Ended || ($current->stock_hold_until && $current->stock_hold_status === \App\Enums\Quotes\StockHoldStatus::Active))
                <x-ui.alert type="warning" class="mt-4">
                    A reserva de estoque desta proposta terminou{{ $current->stock_hold_ended_at ? ' em '.$at($current->stock_hold_ended_at) : '' }}.
                    A disponibilidade abaixo é só uma consulta deste momento.
                </x-ui.alert>
            @else
                <x-ui.alert type="info" class="mt-4">
                    Esta proposta não reserva estoque. A disponibilidade abaixo é uma consulta deste momento: a reserva de estoque só acontece quando o pedido é confirmado.
                </x-ui.alert>
            @endif

            <div class="mt-4 overflow-x-auto">
                <table class="fv-table">
                    <caption class="sr-only">Itens da proposta</caption>
                    <thead>
                        <tr>
                            <th scope="col">Item</th>
                            <th scope="col" class="text-right">Quantidade</th>
                            <th scope="col" class="text-right">Preço de tabela</th>
                            <th scope="col" class="text-right">Desconto</th>
                            <th scope="col" class="text-right">Preço líquido</th>
                            <th scope="col" class="text-right">Total</th>
                            @unless ($holdActive)
                                <th scope="col">Disponibilidade agora</th>
                            @endunless
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($current->items as $item)
                            <tr wire:key="item-{{ $item->id }}">
                                <td>
                                    <span class="font-medium">{{ $item->product_name }}</span>
                                    <span class="block text-xs text-slate-600">{{ $item->sku_code }}{{ $item->variant_label ? ' · '.$item->variant_label : '' }}{{ $item->package_label ? ' · '.$item->package_label : '' }}</span>
                                </td>
                                <td class="whitespace-nowrap text-right">{{ $item->quantity->format() }} {{ $item->unit_code }}</td>
                                <td class="whitespace-nowrap text-right">{{ $item->unit_price_cents->format() }}</td>
                                <td class="whitespace-nowrap text-right">{{ \App\Domain\Shared\Percentage::ofBasisPoints($item->discount_bps)->format() }}</td>
                                <td class="whitespace-nowrap text-right">{{ $item->unit_net_price_cents->format() }}</td>
                                <td class="whitespace-nowrap text-right font-medium">{{ $item->line_total_cents->format() }}</td>
                                @unless ($holdActive)
                                    @php($free = $available[$item->sku_id] ?? \App\Domain\Shared\Quantity::zero())
                                    <td class="text-sm">{{ $free->greaterThanOrEqual($item->base_quantity) ? 'Disponível agora' : 'Indisponível agora no total' }}</td>
                                @endunless
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <dl class="mt-4 grid max-w-sm gap-1 text-sm">
                <div class="flex justify-between"><dt>Subtotal</dt><dd>{{ $current->subtotal_cents->format() }}</dd></div>
                <div class="flex justify-between"><dt>Descontos</dt><dd>− {{ $current->discount_cents->format() }}</dd></div>
                <div class="flex justify-between font-semibold"><dt>Total</dt><dd>{{ $current->total_cents->format() }}</dd></div>
            </dl>
        </section>

        @if ($awaiting && ! $expired)
            <section class="fv-card mb-6 p-5" aria-labelledby="cotacao-aceite">
                <h2 id="cotacao-aceite" class="text-lg font-semibold">Aceitar e gerar pedido</h2>
                @if (! $canAccept)
                    <x-ui.alert type="info" class="mt-3">Seu perfil pode acompanhar a cotação. Peça a um comprador da sua empresa para aceitar.</x-ui.alert>
                @elseif ($choices)
                    <p class="mt-1 text-sm text-slate-700">
                        Os preços desta proposta ficam mantidos no pedido. Se a sua empresa exige aprovação interna, o pedido segue para aprovação depois do aceite;
                        a aprovação interna não garante estoque nem crédito no distribuidor, conferidos na confirmação.
                        @if ($holdActive)
                            A reserva vale até {{ $at($current->stock_hold_until) }}, inclusive durante a aprovação interna; depois disso, o estoque é verificado de novo na confirmação.
                        @endif
                    </p>
                    <form wire:submit="accept" class="mt-4 grid gap-4 sm:grid-cols-2" novalidate>
                        @if ($choices->units !== [])
                            <x-ui.select label="Unidade" name="unitId" :options="$choices->units" placeholder="Sem unidade específica" wire:model.live="unitId" />
                        @endif
                        <x-ui.select label="Endereço de entrega" name="shippingAddressId" :options="$choices->shippingAddresses" placeholder="Selecione o endereço" wire:model="shippingAddressId" required />
                        @if ($choices->billingAddresses !== [])
                            <x-ui.select label="Endereço de cobrança" name="billingAddressId" :options="$choices->billingAddresses" placeholder="Usar o cadastro da empresa" wire:model="billingAddressId" />
                        @endif
                        @if ($choices->costCenters !== [])
                            <x-ui.select label="Centro de custo" name="costCenterId" :options="$choices->costCenters" placeholder="Sem centro de custo" wire:model="costCenterId" />
                        @endif
                        <x-ui.input label="Sua ordem de compra (opcional)" name="purchaseOrderReference" maxlength="60" wire:model="purchaseOrderReference" />
                        <div class="sm:col-span-2">
                            <x-ui.textarea label="Observações do pedido (opcional)" name="orderNotes" maxlength="2000" wire:model="orderNotes" />
                        </div>
                        <div class="flex items-center gap-3 sm:col-span-2">
                            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="accept">Aceitar e gerar pedido de {{ $current->total_cents->format() }}</x-ui.button>
                            <span wire:loading wire:target="accept" class="text-sm text-slate-600" role="status">Gerando o pedido…</span>
                        </div>
                    </form>
                @endif
            </section>
        @endif

        @if (($awaiting || $quote->status === \App\Enums\Quotes\QuoteStatus::Expired))
            <section class="fv-card mb-6 p-5" aria-labelledby="cotacao-revisao">
                <h2 id="cotacao-revisao" class="text-lg font-semibold">{{ $expired || $quote->status === \App\Enums\Quotes\QuoteStatus::Expired ? 'Pedir revalidação' : 'Pedir revisão' }}</h2>
                <p class="mt-1 text-sm text-slate-700">Ajuste as quantidades (zero remove o item) e explique o que precisa mudar. A proposta atual deixa de poder ser aceita.</p>
                <form wire:submit="counter" class="mt-4 grid gap-4 sm:grid-cols-3" novalidate>
                    @foreach ($current->items as $item)
                        <x-ui.input :label="$item->sku_code.' ('.$item->unit_code.')'" name="counterQuantities.{{ $item->line_number }}" inputmode="decimal" maxlength="21"
                            wire:model="counterQuantities.{{ $item->line_number }}" wire:key="revisao-{{ $item->id }}" />
                    @endforeach
                    <div class="sm:col-span-3">
                        <x-ui.textarea label="O que precisa mudar" name="counterMessage" maxlength="2000" wire:model="counterMessage" required />
                    </div>
                    <div class="sm:col-span-3">
                        <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled" wire:target="counter">Enviar pedido de revisão</x-ui.button>
                    </div>
                </form>
            </section>
        @endif
    @elseif ($quote->status === \App\Enums\Quotes\QuoteStatus::Requested)
        <x-ui.alert type="info" class="mb-6">O distribuidor está preparando a proposta. Você será avisado nesta tela quando ela chegar.</x-ui.alert>
    @endif

    @if ($awaiting || $cancellable)
        <section class="fv-card mb-6 p-5" aria-labelledby="cotacao-encerrar">
            <h2 id="cotacao-encerrar" class="text-lg font-semibold">{{ $awaiting ? 'Recusar proposta' : 'Cancelar solicitação' }}</h2>
            <form wire:submit="{{ $awaiting ? 'reject' : 'cancel' }}" class="mt-4 grid gap-4 sm:grid-cols-2" novalidate>
                <x-ui.select label="Motivo" name="closeReasonCode" :options="collect($reasons)->mapWithKeys(fn ($r) => [$r->value => $r->label()])->all()" placeholder="Escolha o motivo" wire:model="closeReasonCode" required />
                <x-ui.input label="Detalhe o motivo (obrigatório para &quot;Outro motivo&quot;)" name="closeReason" maxlength="500" wire:model="closeReason" />
                <div class="sm:col-span-2">
                    <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled">{{ $awaiting ? 'Recusar proposta' : 'Cancelar solicitação' }}</x-ui.button>
                </div>
            </form>
        </section>
    @endif

    <section class="fv-card mb-6 p-5" aria-labelledby="cotacao-historico">
        <h2 id="cotacao-historico" class="text-lg font-semibold">Histórico</h2>
        <ol class="mt-3 space-y-3 text-sm">
            @foreach ($requests as $request)
                <li wire:key="pedido-{{ $request->id }}">
                    <p class="font-medium">{{ $request->kind->label() }} · {{ $at($request->created_at) }}</p>
                    <p class="text-slate-700">{{ $request->items->map(fn ($i) => $i->sku_code.': '.$i->quantity->format().' '.$i->unit_code)->implode('; ') }}</p>
                    @if ($request->message)
                        <p class="mt-1 whitespace-pre-line text-slate-800">{{ $request->message }}</p>
                    @endif
                </li>
            @endforeach
            @foreach ($history as $version)
                <li wire:key="versao-{{ $version->id }}">
                    <p class="font-medium">Proposta versão {{ $version->version_number }} · {{ $at($version->sent_at) }} · {{ $version->status->label() }}</p>
                    <p class="text-slate-700">Total {{ $version->total_cents->format() }}, válida até {{ $at($version->valid_until) }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    <section class="fv-card p-5" aria-labelledby="cotacao-conversa">
        <h2 id="cotacao-conversa" class="text-lg font-semibold">Conversa</h2>
        @if ($comments->isEmpty())
            <p class="mt-2 text-sm text-slate-600">Nenhum comentário ainda.</p>
        @else
            <ol class="mt-3 space-y-3 text-sm">
                @foreach ($comments as $entry)
                    <li wire:key="comentario-{{ $entry->id }}">
                        <p class="font-medium">{{ $entry->author_name }} · {{ $at($entry->created_at) }}</p>
                        <p class="whitespace-pre-line text-slate-800">{{ $entry->body }}</p>
                    </li>
                @endforeach
            </ol>
        @endif
        @if ($open)
            <form wire:submit="addComment" class="mt-4 space-y-3" novalidate>
                <x-ui.textarea label="Comentário" name="comment" maxlength="2000" wire:model="comment" />
                <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled" wire:target="addComment">Enviar comentário</x-ui.button>
            </form>
        @endif
    </section>
</div>

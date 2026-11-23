<div>
    <x-ui.page-header title="Carrinho" description="Preços, disponibilidade e totais são recalculados a cada atualização. A reserva de estoque acontece na confirmação do pedido.">
        <x-slot:actions>
            <a href="{{ route('portal.catalog') }}" class="fv-btn-secondary">Continuar comprando</a>
            <a href="{{ route('portal.quick-order') }}" class="fv-btn-secondary">Pedido rápido</a>
            <a href="{{ route('portal.lists') }}" class="fv-btn-secondary">Listas de compra</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)
            <x-ui.alert type="success">{{ $message }}</x-ui.alert>
        @endif
        {{-- Com o carrinho já convertido (outra aba, reenvio), o retorno da confirmação aparece aqui. --}}
        @if ($cart->isEmpty())
            @error('checkout')
                <x-ui.alert type="error">{{ $message }}</x-ui.alert>
            @enderror
        @endif
    </div>

    @unless ($cart->companyCanPurchase)
        <x-ui.alert type="warning" class="mb-4">
            Sua empresa está com a situação "{{ $cart->companyStatusLabel }}" e não pode confirmar novos pedidos no momento. Fale com o seu representante.
        </x-ui.alert>
    @endunless

    @if ($cart->isEmpty())
        <x-ui.empty-state title="Seu carrinho está vazio" description="Adicione produtos pelo catálogo, cole códigos no pedido rápido ou use uma lista de compra.">
            <a href="{{ route('portal.catalog') }}" class="fv-btn-primary">Ir para o catálogo</a>
        </x-ui.empty-state>
    @else
        <section class="fv-card mb-6" aria-labelledby="carrinho-itens">
            <div class="p-5 pb-0">
                <h2 id="carrinho-itens" class="text-lg font-semibold">Itens</h2>
                @if ($cart->problemCount() > 0)
                    <p class="mt-1 text-sm text-red-700">
                        {{ $cart->problemCount() === 1 ? '1 item precisa de ajuste' : $cart->problemCount().' itens precisam de ajuste' }} antes da confirmação.
                    </p>
                @endif
                <p wire:loading.delay class="mt-2 text-sm text-slate-600" role="status">Atualizando o carrinho…</p>
            </div>

            @if ($cart->overLimit)
                <x-ui.alert type="error" class="mx-5 mt-4">O total do carrinho excede o limite de cálculo. Reduza as quantidades para ver os preços.</x-ui.alert>
            @endif

            <div class="mt-4 overflow-x-auto" wire:loading.class="opacity-60">
                <table class="fv-table">
                    <caption class="sr-only">Itens do carrinho</caption>
                    <thead>
                        <tr>
                            <th scope="col">Item</th>
                            <th scope="col">Quantidade</th>
                            <th scope="col" class="text-right">Preço unitário</th>
                            <th scope="col" class="text-right">Total</th>
                            <th scope="col">Disponibilidade</th>
                            <th scope="col"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($cart->lines as $line)
                            @php($inputId = 'quantidade-'.$line->cartItemId)
                            @php($errorKey = 'quantities.'.$line->cartItemId)
                            @php($error = $errors->first($errorKey))
                            <tr wire:key="linha-{{ $line->cartItemId }}">
                                <td>
                                    <p class="font-medium text-slate-900">{{ $line->productName }}</p>
                                    <p class="font-mono text-xs text-slate-600">{{ $line->skuCode }}</p>
                                    @if ($line->variantLabel || $line->packageLabel)
                                        <p class="text-xs text-slate-600">{{ implode(' · ', array_filter([$line->variantLabel, $line->packageLabel])) }}</p>
                                    @endif
                                    @unless ($line->isReady())
                                        <ul class="mt-1 space-y-0.5 text-xs text-red-700" aria-label="Problemas do item {{ $line->skuCode }}">
                                            @foreach ($line->problems as $problem)
                                                <li>{{ $problem }}</li>
                                            @endforeach
                                        </ul>
                                    @endunless
                                </td>
                                <td class="min-w-56">
                                    <form wire:submit="updateQuantity('{{ $line->cartItemId }}')" class="flex items-start gap-2">
                                        <div>
                                            <label for="{{ $inputId }}" class="sr-only">Quantidade de {{ $line->skuCode }} em {{ $line->unitCode }}</label>
                                            <input id="{{ $inputId }}" type="text" inputmode="decimal" autocomplete="off" maxlength="20"
                                                wire:model="quantities.{{ $line->cartItemId }}"
                                                @if ($error) aria-invalid="true" aria-describedby="{{ $inputId }}-erro" @endif
                                                @class(['fv-input w-28', 'fv-input-error' => $error])>
                                            <span class="text-xs text-slate-600">{{ $line->unitCode }}</span>
                                        </div>
                                        <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled">
                                            Atualizar<span class="sr-only"> quantidade de {{ $line->skuCode }}</span>
                                        </x-ui.button>
                                    </form>
                                    @if ($error)
                                        <p id="{{ $inputId }}-erro" class="mt-1 text-xs text-red-700">{{ $error }}</p>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if ($line->amounts)
                                        <span class="font-medium">{{ $line->amounts->unitPrice->format() }}</span>
                                        <span class="text-xs text-slate-600">/ {{ $line->unitCode }}</span>
                                        @if ($line->price?->tier)
                                            <span class="block text-xs text-emerald-700">Preço por volume</span>
                                        @endif
                                    @else
                                        <span class="text-sm text-slate-600">—</span>
                                    @endif
                                </td>
                                <td class="text-right font-semibold">{{ $line->amounts?->total->format() ?? '—' }}</td>
                                <td>
                                    @switch($line->availability)
                                        @case('available')
                                            <x-ui.badge color="green">Disponível</x-ui.badge>
                                            @break
                                        @case('backorder')
                                            <x-ui.badge color="amber">Sob encomenda</x-ui.badge>
                                            @break
                                        @default
                                            <x-ui.badge color="red">Indisponível</x-ui.badge>
                                    @endswitch
                                </td>
                                <td class="text-right">
                                    <x-ui.button variant="secondary" wire:click="removeItem('{{ $line->cartItemId }}')" wire:loading.attr="disabled">
                                        Remover<span class="sr-only"> {{ $line->skuCode }}</span>
                                    </x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($totals)
                <dl class="ml-auto grid max-w-sm grid-cols-2 gap-x-6 gap-y-1 p-5 text-sm" aria-label="Totais do carrinho">
                    <dt class="text-slate-600">Subtotal</dt>
                    <dd class="text-right">{{ $totals->subtotal->format() }}</dd>
                    <dt class="text-slate-600">Descontos</dt>
                    <dd class="text-right">{{ $totals->discount->format() }}</dd>
                    <dt class="text-slate-600">Frete do distribuidor @if ($freight)<span class="block text-xs">{{ $freight->source }}</span>@endif</dt>
                    <dd class="text-right">{{ $totals->freight->isZero() ? 'Sem frete' : $totals->freight->format() }}</dd>
                    <dt class="font-semibold text-slate-900">Total</dt>
                    <dd class="text-right text-lg font-semibold text-slate-900">{{ $totals->total->format() }}</dd>
                </dl>
            @endif
        </section>

        <section class="fv-card p-5" aria-labelledby="carrinho-checkout">
            <h2 id="carrinho-checkout" class="text-lg font-semibold">Entrega e pagamento</h2>

            @unless ($canPlace)
                <x-ui.alert type="info" class="mt-3">
                    Seu perfil pode montar o carrinho, mas a confirmação de pedidos é feita por um usuário da empresa com essa permissão.
                </x-ui.alert>
            @endunless

            @php($shippingAddressMissing = $choices->shippingAddresses === [])
            <form wire:submit="placeOrder" class="mt-4 grid gap-4 sm:grid-cols-2" novalidate>
                @if ($choices->units !== [])
                    <x-ui.select label="Unidade" name="unitId" :options="$choices->units" placeholder="Sem unidade específica" wire:model.live="unitId" />
                @endif

                <x-ui.select label="Endereço de entrega" name="shippingAddressId" :options="$choices->shippingAddresses" placeholder="Selecione o endereço" wire:model.live="shippingAddressId" required />

                @if ($choices->billingAddresses !== [])
                    <x-ui.select label="Endereço de cobrança" name="billingAddressId" :options="$choices->billingAddresses" placeholder="Usar o cadastro da empresa" wire:model="billingAddressId" />
                @endif

                @if ($choices->costCenters !== [])
                    <div>
                        <x-ui.select label="Centro de custo" name="costCenterId" :options="$choices->costCenters" placeholder="Sem centro de custo" wire:model.live="costCenterId"
                            :aria-describedby="$budget && ! $errors->has('costCenterId') ? 'centro-custo-orcamento' : null" />
                        @if ($budget && ! $errors->has('costCenterId'))
                            <p id="centro-custo-orcamento" class="mt-1 text-xs text-slate-600">
                                Orçamento do centro de custo no mês: {{ $budget->available()->format() }} disponíveis de {{ $budget->amount->format() }}.
                                @if ($totals && $cart->isReady() && ! $budget->fits($totals->total))
                                    <span class="block font-medium text-red-700">O total do carrinho excede o disponível: o pedido será recusado com este centro de custo.</span>
                                @endif
                            </p>
                        @endif
                    </div>
                @endif

                <x-ui.select label="Condição de pagamento" name="paymentTermId" :options="$choices->paymentTerms" placeholder="Selecione a condição" wire:model="paymentTermId" required />

                <x-ui.input label="Referência da sua ordem de compra (opcional)" name="purchaseOrderReference" maxlength="60" autocomplete="off"
                    hint="Número da OC interna da sua empresa, impresso no pedido." wire:model="purchaseOrderReference" />

                <div class="sm:col-span-2">
                    <x-ui.textarea label="Observação para o distribuidor (opcional)" name="notes" rows="3" maxlength="1000" wire:model="notes" />
                </div>

                @if ($shippingAddressMissing)
                    <x-ui.alert type="warning" class="sm:col-span-2">Sua empresa não tem endereço de entrega ativo. Fale com o seu representante para cadastrá-lo.</x-ui.alert>
                @endif

                @if ($approval)
                    <x-ui.alert type="info" class="sm:col-span-2">
                        <p class="font-semibold">Este pedido seguirá para aprovação interna da sua empresa.</p>
                        <p class="mt-1">
                            Regra aplicável: {{ $approval['rule'] }} ({{ $approval['levels'] === 1 ? '1 nível de aprovação' : $approval['levels'].' níveis de aprovação' }}).
                            O orçamento do centro de custo, quando houver, é reservado no envio.
                        </p>
                        <p class="mt-1">
                            A aprovação interna não garante estoque nem crédito no distribuidor: eles são conferidos e reservados somente na confirmação, depois da aprovação.
                        </p>
                    </x-ui.alert>
                @endif

                @error('checkout')
                    <x-ui.alert type="error" class="sm:col-span-2">{{ $message }}</x-ui.alert>
                @enderror

                <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="placeOrder"
                        :disabled="! $canPlace || ! $cart->isReady() || ! $cart->companyCanPurchase || $shippingAddressMissing">
                        {{ $approval ? 'Enviar para aprovação' : 'Confirmar pedido' }}
                    </x-ui.button>
                    @if ($totals && $cart->isReady())
                        <span class="text-sm text-slate-700">{{ $approval ? 'Total a enviar' : 'Total a confirmar' }}: <strong>{{ $totals->total->format() }}</strong></span>
                    @endif
                    <span wire:loading wire:target="placeOrder" class="text-sm text-slate-600" role="status">{{ $approval ? 'Enviando o pedido…' : 'Confirmando o pedido…' }}</span>
                </div>
            </form>
        </section>

        @if ($canQuote)
            <section class="fv-card mt-6 p-5" aria-labelledby="carrinho-cotacao" x-data="{ aberto: false }">
                <h2 id="carrinho-cotacao" class="text-lg font-semibold">Precisa negociar?</h2>
                <p class="mt-1 text-sm text-slate-700">
                    Solicite uma cotação destes itens: o distribuidor envia uma proposta com preços e validade. Itens sem preço ou sem estoque também podem ser cotados.
                </p>
                <x-ui.button variant="secondary" class="mt-3" x-on:click="aberto = ! aberto" x-bind:aria-expanded="aberto" aria-controls="carrinho-cotacao-form">Solicitar cotação</x-ui.button>

                <form id="carrinho-cotacao-form" wire:submit="requestQuote" class="mt-4 grid gap-4 sm:grid-cols-2" x-show="aberto" x-cloak novalidate>
                    <div class="sm:col-span-2">
                        <x-ui.textarea label="Mensagem ao distribuidor (opcional)" name="quoteMessage" wire:model="quoteMessage" maxlength="2000" rows="3" />
                    </div>
                    <x-ui.input label="Preciso até (opcional)" name="quoteNeededBy" type="date" min="2000-01-01" max="2099-12-31" wire:model="quoteNeededBy" />
                    <label class="flex items-center gap-2 self-end pb-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="quoteRemoveItems" class="rounded border-slate-300">
                        Remover estes itens do carrinho
                    </label>
                    @error('quote')
                        <x-ui.alert type="error" class="sm:col-span-2">{{ $message }}</x-ui.alert>
                    @enderror
                    <div class="flex items-center gap-3 sm:col-span-2">
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="requestQuote">Enviar solicitação de cotação</x-ui.button>
                        <span wire:loading wire:target="requestQuote" class="text-sm text-slate-600" role="status">Enviando a solicitação…</span>
                    </div>
                </form>
            </section>
        @endif
    @endif
</div>

@php($timezone = (string) config('app.business_timezone'))
@php($at = static fn ($instant): string => $instant->setTimezone($timezone)->format('d/m/Y H:i'))
<div>
    <x-ui.page-header :title="'Cotação '.$quote->number" :description="$quote->buyerCompany->trade_name.' · '.$quote->status->label()">
        <x-slot:actions>
            @if ($canManage && ! $quote->status->isTerminal())
                <a href="{{ route('quotes.versions.create', $quote->id) }}" class="fv-btn-primary">Elaborar nova versão</a>
            @endif
            <a href="{{ route('quotes.index') }}" class="fv-btn-secondary">Voltar</a>
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

    @if ($quote->needed_by)
        <p class="mb-4 text-sm text-slate-700">O comprador precisa até {{ $quote->needed_by->format('d/m/Y') }}.</p>
    @endif

    @if ($preparing)
        @php($review = $preparing->review)
        <section class="fv-card mb-6 p-5" aria-labelledby="cotacao-preparo">
            <h2 id="cotacao-preparo" class="text-lg font-semibold">Versão em preparo · {{ $preparing->status->label() }}</h2>
            <p class="mt-1 text-sm text-slate-700">
                Elaborada por {{ $names[$preparing->created_by_user_id] ?? '—' }} · total {{ $preparing->total_cents->format() }} · válida até {{ $at($preparing->valid_until) }}
                @if ($preparing->stock_hold_until) · reserva até {{ $at($preparing->stock_hold_until) }} @endif
            </p>
            @if ($review->requires_approval)
                <x-ui.alert type="warning" class="mt-3">
                    <p class="font-semibold">Fora da política comercial: {{ collect($review->violations)->map(fn ($v) => \App\Domain\Pricing\DiscountViolation::from($v)->label())->implode('; ') }}.</p>
                    <p class="mt-1">Maior desconto: {{ \App\Domain\Shared\Percentage::ofBasisPoints($review->max_discount_bps)->format() }}. Justificativa: {{ $review->justification }}</p>
                    <p class="mt-1">Esta aprovação vale somente para esta versão.</p>
                </x-ui.alert>
            @endif
            @include('livewire.staff.quotes.partials.version-items', ['version' => $preparing, 'review' => $review, 'canViewCosts' => $canViewCosts])

            @if ($preparing->status === $preparingStatus && $canApprove)
                @if ($review->evaluated_by_membership_id === $membershipId)
                    <p class="mt-4 text-sm text-slate-700">Você elaborou esta versão: a decisão cabe a outra pessoa com permissão de aprovar.</p>
                @elseif ($limit < $review->max_discount_bps)
                    <p class="mt-4 text-sm text-slate-700">O maior desconto desta versão está acima do seu limite.</p>
                @else
                    <form class="mt-4 space-y-3" wire:submit="decide(true)" novalidate>
                        <x-ui.textarea label="Comentário (obrigatório para reprovar)" name="decisionComment" maxlength="1000" wire:model="decisionComment" />
                        @if ($canManage)
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" wire:model="sendNow" class="rounded border-slate-300"> Enviar ao comprador ao aprovar
                            </label>
                        @endif
                        <div class="flex flex-wrap gap-3">
                            <x-ui.button type="submit" wire:loading.attr="disabled">Aprovar exceção</x-ui.button>
                            <x-ui.button type="button" variant="secondary" wire:click="decide(false)" wire:loading.attr="disabled">Reprovar</x-ui.button>
                        </div>
                    </form>
                @endif
            @elseif ($preparing->status === \App\Enums\Quotes\QuoteVersionStatus::Ready && $canManage)
                <x-ui.button class="mt-4" wire:click="send" wire:loading.attr="disabled">Enviar ao comprador</x-ui.button>
            @endif
        </section>
    @endif

    @if ($withBuyer)
        <section class="fv-card mb-6 p-5" aria-labelledby="cotacao-vigente">
            <h2 id="cotacao-vigente" class="text-lg font-semibold">Com o comprador · versão {{ $withBuyer->version_number }}</h2>
            <p class="mt-1 text-sm text-slate-700">
                Enviada em {{ $at($withBuyer->sent_at) }} · total {{ $withBuyer->total_cents->format() }} · válida até {{ $at($withBuyer->valid_until) }}
                · {{ $withBuyer->stock_hold_status === \App\Enums\Quotes\StockHoldStatus::Active ? 'estoque reservado até '.$at($withBuyer->stock_hold_until) : 'sem reserva' }}
            </p>
            @include('livewire.staff.quotes.partials.version-items', ['version' => $withBuyer, 'review' => $withBuyer->review, 'canViewCosts' => $canViewCosts])
        </section>
    @endif

    @if ($canManage && ! $quote->status->isTerminal())
        <section class="fv-card mb-6 p-5" aria-labelledby="cotacao-acoes">
            <h2 id="cotacao-acoes" class="text-lg font-semibold">Retirar ou encerrar</h2>
            <form class="mt-3 space-y-3" wire:submit="close" novalidate>
                <x-ui.input label="Motivo" name="reason" maxlength="500" wire:model="reason" />
                <div class="flex flex-wrap gap-3">
                    @if ($withBuyer)
                        <x-ui.button type="button" variant="secondary" wire:click="withdraw('sent')">Retirar a proposta enviada</x-ui.button>
                    @endif
                    @if ($preparing)
                        <x-ui.button type="button" variant="secondary" wire:click="withdraw('preparing')">Retirar a versão em preparo</x-ui.button>
                    @endif
                    <x-ui.button type="submit" variant="secondary">Encerrar a cotação</x-ui.button>
                </div>
            </form>
        </section>
    @endif

    <section class="fv-card mb-6 p-5" aria-labelledby="cotacao-linha">
        <h2 id="cotacao-linha" class="text-lg font-semibold">Linha do tempo</h2>
        <ol class="mt-3 space-y-3 text-sm">
            @foreach ($requests as $request)
                <li wire:key="pedido-{{ $request->id }}">
                    <p class="font-medium">{{ $request->kind->label() }} do comprador · {{ $at($request->created_at) }}</p>
                    <p class="text-slate-700">{{ $request->items->map(fn ($i) => $i->sku_code.': '.$i->quantity->format().' '.$i->unit_code)->implode('; ') }}</p>
                    @if ($request->message)
                        <p class="mt-1 whitespace-pre-line text-slate-800">{{ $request->message }}</p>
                    @endif
                </li>
            @endforeach
            @foreach ($versions as $version)
                <li wire:key="versao-{{ $version->id }}">
                    <p class="font-medium">
                        {{ $version->version_number ? 'Versão '.$version->version_number : 'Versão não enviada' }} · {{ $version->status->label() }} · {{ $version->total_cents->format() }}
                        @if ($version->review?->self_approved) · <x-ui.badge color="amber">Autoaprovada</x-ui.badge> @endif
                    </p>
                    <p class="text-slate-700">Criada em {{ $at($version->created_at) }} por {{ $names[$version->created_by_user_id] ?? '—' }}
                        @if ($version->review?->decided_at) · decisão comercial em {{ $at($version->review->decided_at) }} por {{ $names[$version->review->decided_by_user_id] ?? '—' }} @endif
                        @if ($version->stock_hold_end_reason) · reserva encerrada: {{ $version->stock_hold_end_reason->label() }} @endif
                    </p>
                </li>
            @endforeach
        </ol>
    </section>

    <section class="fv-card p-5" aria-labelledby="cotacao-conversa">
        <h2 id="cotacao-conversa" class="text-lg font-semibold">Conversa e notas internas</h2>
        <ol class="mt-3 space-y-3 text-sm">
            @forelse ($comments as $entry)
                <li wire:key="comentario-{{ $entry->id }}">
                    <p class="font-medium">{{ $entry->author_name }} · {{ $at($entry->created_at) }}
                        @if ($entry->visibility === \App\Enums\Quotes\QuoteCommentVisibility::Internal) <x-ui.badge>Nota interna</x-ui.badge> @endif
                    </p>
                    <p class="whitespace-pre-line text-slate-800">{{ $entry->body }}</p>
                </li>
            @empty
                <li class="text-slate-600">Nenhum comentário ainda.</li>
            @endforelse
        </ol>
        @if ($canManage && ! $quote->status->isTerminal())
            <form wire:submit="addComment" class="mt-4 space-y-3" novalidate>
                <x-ui.textarea label="Comentário" name="comment" maxlength="2000" wire:model="comment" />
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="internal" class="rounded border-slate-300"> Nota interna (o comprador não vê)
                </label>
                <x-ui.button type="submit" variant="secondary">Registrar</x-ui.button>
            </form>
        @endif
    </section>
</div>

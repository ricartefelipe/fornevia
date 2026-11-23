@php($timezone = (string) config('app.business_timezone'))
<div>
    <x-ui.page-header title="Cotações" :description="$seesAll ? 'Cotações solicitadas pelos usuários da sua empresa.' : 'Cotações solicitadas por você.'" />

    <nav class="mb-4 flex flex-wrap gap-2" aria-label="Situação das cotações">
        @foreach (['awaiting' => 'Aguardando sua resposta', 'distributor' => 'Com o distribuidor', 'closed' => 'Encerradas'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" wire:key="aba-{{ $key }}"
                @class(['fv-btn-primary' => $tab === $key, 'fv-btn-secondary' => $tab !== $key]) @if ($tab === $key) aria-current="page" @endif>
                {{ $label }} ({{ $counts[$key] }})
            </button>
        @endforeach
    </nav>

    @if ($quotes->isEmpty())
        <x-ui.empty-state title="Nenhuma cotação nesta aba"
            description="Para pedir uma cotação, monte o carrinho e use &quot;Solicitar cotação&quot;.">
            <a href="{{ route('portal.cart') }}" class="fv-btn-primary">Ir para o carrinho</a>
        </x-ui.empty-state>
    @else
        <section class="fv-card" aria-labelledby="cotacoes-lista">
            <h2 id="cotacoes-lista" class="sr-only">Lista de cotações</h2>
            <div class="overflow-x-auto">
                <table class="fv-table">
                    <caption class="sr-only">Cotações</caption>
                    <thead>
                        <tr>
                            <th scope="col">Cotação</th>
                            <th scope="col">Situação</th>
                            @if ($seesAll)
                                <th scope="col">Solicitante</th>
                            @endif
                            <th scope="col">Versão</th>
                            <th scope="col" class="text-right">Total</th>
                            <th scope="col">Válida até</th>
                            <th scope="col">Estoque</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($quotes as $quote)
                            @php($version = $current->get($quote->id))
                            <tr wire:key="cotacao-{{ $quote->id }}">
                                <td><a href="{{ route('portal.quotes.show', $quote->id) }}" class="font-medium text-blue-800 underline">{{ $quote->number }}</a></td>
                                <td>{{ \App\Livewire\Portal\Quotes\QuotesIndex::effectiveLabel($quote, $version, $now) }}</td>
                                @if ($seesAll)
                                    <td>{{ $requesters[$quote->requested_by_user_id] ?? '—' }}</td>
                                @endif
                                <td>{{ $version ? 'v'.$version->version_number : '—' }}</td>
                                <td class="whitespace-nowrap text-right">{{ $version?->total_cents->format() ?? '—' }}</td>
                                <td class="whitespace-nowrap">{{ $version ? $version->valid_until->setTimezone($timezone)->format('d/m/Y H:i') : '—' }}</td>
                                <td>
                                    @if ($version && $version->status === $sentStatus && $version->stock_hold_status === \App\Enums\Quotes\StockHoldStatus::Active && $version->stock_hold_until->greaterThan($now))
                                        <x-ui.badge color="green">Reservado até {{ $version->stock_hold_until->setTimezone($timezone)->format('d/m H:i') }}</x-ui.badge>
                                    @elseif ($version)
                                        <span class="text-sm text-slate-600">Sem reserva</span>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <div class="mt-4">{{ $quotes->links() }}</div>
    @endif
</div>

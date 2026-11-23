@php($timezone = (string) config('app.business_timezone'))
<div>
    <x-ui.page-header title="Cotações" description="Solicitações dos compradores, propostas e aprovações comerciais.">
        <x-slot:actions>
            @if ($canApprove)
                <a href="{{ route('quotes.approvals') }}" class="fv-btn-secondary">Aprovações comerciais</a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <section class="fv-card mb-6 p-4" role="search" aria-labelledby="cotacoes-filtros">
        <h2 id="cotacoes-filtros" class="sr-only">Filtrar cotações</h2>
        <div class="grid gap-4 sm:grid-cols-3">
            <x-ui.select label="Fila" name="filter" :options="$filters" wire:model.live="filter" />
            <x-ui.input label="Número ou empresa" name="search" maxlength="60" wire:model.live.debounce.400ms="search" />
        </div>
    </section>

    @if ($quotes->isEmpty())
        <x-ui.empty-state title="Nenhuma cotação nesta fila" description="Troque a fila ou a busca." />
    @else
        <section class="fv-card" aria-labelledby="cotacoes-lista">
            <h2 id="cotacoes-lista" class="sr-only">Lista de cotações</h2>
            <div class="overflow-x-auto">
                <table class="fv-table">
                    <caption class="sr-only">Cotações</caption>
                    <thead>
                        <tr>
                            <th scope="col">Cotação</th>
                            <th scope="col">Empresa</th>
                            <th scope="col">Situação</th>
                            <th scope="col">Versão</th>
                            <th scope="col" class="text-right">Total</th>
                            <th scope="col">Válida até</th>
                            <th scope="col">Reserva</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($quotes as $quote)
                            @php($version = $current->get($quote->id))
                            <tr wire:key="cotacao-{{ $quote->id }}">
                                <td><a href="{{ route('quotes.show', $quote->id) }}" class="font-medium text-blue-800 underline">{{ $quote->number }}</a></td>
                                <td>{{ $quote->buyerCompany->trade_name }}</td>
                                <td>{{ $version?->status === \App\Enums\Quotes\QuoteVersionStatus::PendingApproval ? 'Em aprovação comercial' : $quote->status->label() }}</td>
                                <td>{{ $version?->version_number ? 'v'.$version->version_number : ($version ? 'em preparo' : '—') }}</td>
                                <td class="whitespace-nowrap text-right">{{ $version?->total_cents->format() ?? '—' }}</td>
                                <td class="whitespace-nowrap">{{ $version ? $version->valid_until->setTimezone($timezone)->format('d/m/Y H:i') : '—' }}</td>
                                <td>{{ $version?->stock_hold_status === \App\Enums\Quotes\StockHoldStatus::Active ? 'Até '.$version->stock_hold_until->setTimezone($timezone)->format('d/m H:i') : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <div class="mt-4">{{ $quotes->links() }}</div>
    @endif
</div>

@php($timezone = (string) config('app.business_timezone'))
<div>
    <x-ui.page-header title="Aprovações comerciais" description="Propostas fora da política comercial aguardando decisão. Esta aprovação vale somente para a versão aprovada." />

    @if ($versions->isEmpty())
        <x-ui.empty-state title="Nenhuma proposta aguardando aprovação comercial" description="As versões com desconto, preço ou margem fora da política aparecem aqui." />
    @else
        <section class="fv-card" aria-labelledby="aprovacoes-comerciais">
            <h2 id="aprovacoes-comerciais" class="sr-only">Versões aguardando aprovação comercial</h2>
            <div class="overflow-x-auto">
                <table class="fv-table">
                    <caption class="sr-only">Aprovações comerciais</caption>
                    <thead>
                        <tr>
                            <th scope="col">Cotação</th>
                            <th scope="col">Empresa</th>
                            <th scope="col" class="text-right">Total</th>
                            <th scope="col" class="text-right">Maior desconto</th>
                            <th scope="col">Motivos</th>
                            <th scope="col">Justificativa</th>
                            <th scope="col">Sua decisão</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($versions as $version)
                            @php($review = $version->review)
                            <tr wire:key="versao-{{ $version->id }}">
                                <td><a href="{{ route('quotes.show', $version->quote_id) }}" class="font-medium text-blue-800 underline">{{ $version->quote->number }}</a></td>
                                <td>{{ $version->quote->buyerCompany->trade_name }}</td>
                                <td class="whitespace-nowrap text-right">{{ $version->total_cents->format() }}</td>
                                <td class="whitespace-nowrap text-right">{{ \App\Domain\Shared\Percentage::ofBasisPoints($review->max_discount_bps)->format() }}</td>
                                <td class="text-sm">{{ collect($review->violations)->map(fn ($v) => \App\Domain\Pricing\DiscountViolation::from($v)->label())->implode('; ') }}</td>
                                <td class="text-sm">{{ $review->justification }}</td>
                                <td class="text-sm">
                                    @if ($review->evaluated_by_membership_id === $membershipId)
                                        Elaborada por você
                                    @elseif ($limit < $review->max_discount_bps)
                                        Acima do seu limite (somente leitura)
                                    @else
                                        <a href="{{ route('quotes.show', $version->quote_id) }}" class="text-blue-800 underline">Decidir</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>

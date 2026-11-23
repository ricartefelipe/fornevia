{{--
    Itens de uma versão para a equipe. Custo e margem da avaliação
    comercial só com catalog.view_costs (RS-020). Parâmetros: $version,
    $review e $canViewCosts.
--}}
@php($lines = collect($review?->lines ?? [])->keyBy('line_number'))
<div class="mt-4 overflow-x-auto">
    <table class="fv-table">
        <caption class="sr-only">Itens da versão</caption>
        <thead>
            <tr>
                <th scope="col">Item</th>
                <th scope="col" class="text-right">Quantidade</th>
                <th scope="col" class="text-right">Preço de tabela</th>
                <th scope="col" class="text-right">Desconto</th>
                <th scope="col" class="text-right">Líquido</th>
                <th scope="col" class="text-right">Total</th>
                @if ($canViewCosts)
                    <th scope="col" class="text-right">Custo</th>
                    <th scope="col" class="text-right">Margem</th>
                @endif
                <th scope="col">Política</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($version->items as $item)
                @php($line = $lines->get($item->line_number, []))
                <tr wire:key="item-{{ $item->id }}">
                    <td><span class="font-medium">{{ $item->sku_code }}</span> <span class="block text-xs text-slate-600">{{ $item->product_name }}</span></td>
                    <td class="whitespace-nowrap text-right">{{ $item->quantity->format() }} {{ $item->unit_code }}</td>
                    <td class="whitespace-nowrap text-right">{{ $item->unit_price_cents->format() }}</td>
                    <td class="whitespace-nowrap text-right">{{ \App\Domain\Shared\Percentage::ofBasisPoints($item->discount_bps)->format() }}</td>
                    <td class="whitespace-nowrap text-right">{{ $item->unit_net_price_cents->format() }}</td>
                    <td class="whitespace-nowrap text-right">{{ $item->line_total_cents->format() }}</td>
                    @if ($canViewCosts)
                        <td class="whitespace-nowrap text-right">{{ isset($line['unit_cost_cents']) ? \App\Domain\Shared\Money::ofCents($line['unit_cost_cents'])->format() : 'Não cadastrado' }}</td>
                        <td class="whitespace-nowrap text-right">{{ isset($line['margin_bps']) ? \App\Domain\Shared\Percentage::ofBasisPoints($line['margin_bps'], allowOutOfRange: true)->format() : '—' }}</td>
                    @endif
                    <td class="text-sm">{{ collect($line['violations'] ?? [])->map(fn ($v) => \App\Domain\Pricing\DiscountViolation::from($v)->label())->implode('; ') ?: 'Dentro da política' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

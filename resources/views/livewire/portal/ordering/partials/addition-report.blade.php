{{-- Relatório por linha de uma inclusão em lote no carrinho (pedido rápido, lista de compra, recompra). --}}
<section class="fv-card mt-6" aria-labelledby="{{ $reportId }}">
    <div class="flex flex-wrap items-center justify-between gap-3 p-5 pb-0">
        <div>
            <h2 id="{{ $reportId }}" class="text-lg font-semibold">{{ $reportTitle ?? 'Resultado' }}</h2>
            <p class="mt-1 text-sm text-slate-700" role="status">{{ $report['summary'] }}</p>
        </div>
        @if ($report['added'] > 0)
            <a href="{{ route('portal.cart') }}" class="fv-btn-primary">Ir para o carrinho</a>
        @endif
    </div>

    <div class="mt-4 overflow-x-auto">
        <table class="fv-table">
            <caption class="sr-only">Resultado por linha</caption>
            <thead>
                <tr>
                    @if ($showLineNumbers ?? false)
                        <th scope="col">Linha</th>
                    @endif
                    <th scope="col">Item</th>
                    <th scope="col">Situação</th>
                    <th scope="col">Detalhes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($report['lines'] as $index => $line)
                    <tr wire:key="resultado-{{ $index }}">
                        @if ($showLineNumbers ?? false)
                            <td class="text-sm text-slate-600">{{ $line['line'] }}</td>
                        @endif
                        <td>
                            @if ($line['product'])
                                <p class="font-medium text-slate-900">{{ $line['product'] }}</p>
                            @endif
                            <p class="font-mono text-xs text-slate-600">{{ $line['sku_code'] ?? $line['input'] }}</p>
                        </td>
                        <td>
                            <x-ui.badge :color="$line['status'] === 'rejected' ? 'red' : ($line['status'] === 'adjusted' ? 'amber' : 'green')">{{ $line['status_label'] }}</x-ui.badge>
                        </td>
                        <td class="text-sm">
                            @if ($line['quantity'] !== null)
                                <p>{{ $line['quantity'] }} {{ $line['unit'] }}</p>
                            @endif
                            @if ($line['messages'] !== [])
                                <ul @class(['space-y-0.5', 'text-red-700' => $line['status'] === 'rejected', 'text-slate-700' => $line['status'] !== 'rejected'])>
                                    @foreach ($line['messages'] as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($line['suggestion'] !== null)
                                <p class="text-slate-700">Quantidade válida mais próxima: <strong>{{ $line['suggestion'] }} {{ $line['unit'] }}</strong>.</p>
                            @endif
                            @foreach ($line['warnings'] as $warning)
                                <p class="text-amber-800">{{ $warning }}</p>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

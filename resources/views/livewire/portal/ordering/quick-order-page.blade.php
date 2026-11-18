<div>
    <x-ui.page-header title="Pedido rápido" description="Cole ou digite os itens, um por linha, no formato CÓDIGO quantidade. Os itens aceitos entram no carrinho, somados ao que já estiver lá.">
        <x-slot:actions>
            <a href="{{ route('portal.cart') }}" class="fv-btn-secondary">Ver carrinho</a>
        </x-slot:actions>
    </x-ui.page-header>

    <section class="fv-card p-5" aria-labelledby="pedido-rapido-form">
        <h2 id="pedido-rapido-form" class="sr-only">Itens do pedido rápido</h2>
        <form wire:submit="submit" class="space-y-4">
            @php($error = $errors->first('text'))
            <div>
                <label for="pedido-rapido-itens" class="fv-label">Itens (um por linha)</label>
                <textarea id="pedido-rapido-itens" name="text" rows="10" spellcheck="false" autocomplete="off" wire:model="text"
                    aria-describedby="pedido-rapido-ajuda{{ $error ? ' pedido-rapido-erro' : '' }}"
                    @if ($error) aria-invalid="true" @endif
                    @class(['fv-input font-mono', 'fv-input-error' => $error])></textarea>
                @if ($error)
                    <p id="pedido-rapido-erro" class="mt-1 text-xs text-red-700">{{ $error }}</p>
                @endif
                <div id="pedido-rapido-ajuda" class="mt-2 text-xs text-slate-600">
                    <p>Exemplos: <code>CIM-50 20</code>, <code>ARE-M;2,5</code> ou colunas copiadas de uma planilha. Separe código e quantidade por espaço, tabulação, ponto e vírgula ou vírgula.</p>
                    <p>Quantidades no formato brasileiro (1.000 = mil; 2,5 = dois e meio), na unidade de venda do item. Até {{ $maxLines }} linhas por vez.</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="submit">Adicionar ao carrinho</x-ui.button>
                <span wire:loading wire:target="submit" class="text-sm text-slate-600" role="status">Conferindo os itens…</span>
            </div>
        </form>
    </section>

    @if ($report)
        @include('livewire.portal.ordering.partials.addition-report', ['report' => $report, 'reportId' => 'pedido-rapido-resultado', 'showLineNumbers' => true])
    @endif
</div>

<div>
    <x-ui.page-header :title="'Nova versão · '.$quote->number" :description="$quote->buyerCompany->trade_name">
        <x-slot:actions>
            <a href="{{ route('quotes.show', $quote->id) }}" class="fv-btn-secondary">Voltar à cotação</a>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($replacing)
        <x-ui.alert type="info" class="mb-4">Há uma versão em preparo: esta nova versão a substitui.</x-ui.alert>
    @endif

    @error('form')
        <x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <form wire:submit="submit" class="space-y-6" novalidate>
        <section class="fv-card p-5" aria-labelledby="versao-itens">
            <h2 id="versao-itens" class="text-lg font-semibold">Itens</h2>
            <p class="mt-1 text-sm text-slate-700">O preço de tabela vem do cadastro de preços; informe a quantidade e o desconto. A prévia é recalculada no servidor.</p>
            <div class="mt-4 space-y-3">
                @foreach ($lines as $index => $line)
                    <div class="grid gap-3 sm:grid-cols-4" wire:key="linha-{{ $index }}">
                        <x-ui.input label="SKU" name="lines.{{ $index }}.sku" maxlength="60" wire:model.blur="lines.{{ $index }}.sku" />
                        <x-ui.input label="Quantidade" name="lines.{{ $index }}.quantity" inputmode="decimal" maxlength="21" wire:model.blur="lines.{{ $index }}.quantity" />
                        <x-ui.input label="Desconto (%)" name="lines.{{ $index }}.discount" inputmode="decimal" maxlength="6" wire:model.blur="lines.{{ $index }}.discount" />
                        <x-ui.button type="button" variant="secondary" class="self-end" wire:click="removeLine({{ $index }})">Remover</x-ui.button>
                    </div>
                @endforeach
                <div class="flex items-end gap-3">
                    <x-ui.input label="Adicionar SKU pelo código" name="newSku" maxlength="60" wire:model="newSku" />
                    <x-ui.button type="button" variant="secondary" wire:click="addLine">Adicionar</x-ui.button>
                </div>
            </div>
        </section>

        <section class="fv-card p-5" aria-labelledby="versao-previa">
            <h2 id="versao-previa" class="text-lg font-semibold">Prévia</h2>
            @if ($previewError)
                <x-ui.alert type="warning" class="mt-3">{{ $previewError }}</x-ui.alert>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="fv-table">
                        <caption class="sr-only">Prévia da proposta</caption>
                        <thead><tr><th scope="col">SKU</th><th scope="col" class="text-right">Preço de tabela</th><th scope="col" class="text-right">Líquido</th><th scope="col" class="text-right">Total</th></tr></thead>
                        <tbody>
                            @foreach ($priced as $line)
                                <tr wire:key="previa-{{ $line->lineNumber }}">
                                    <td>{{ $line->sku->code }}</td>
                                    <td class="text-right">{{ $line->amounts->unitPrice->format() }}</td>
                                    <td class="text-right">{{ $line->amounts->unitNetPrice->format() }}</td>
                                    <td class="text-right">{{ $line->amounts->total->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 font-semibold">Total: {{ $totals?->total->format() }}</p>
                @if ($review?->requiresApproval())
                    <x-ui.alert type="warning" class="mt-3">
                        Exigirá aprovação comercial: {{ implode('; ', \App\Livewire\Staff\Quotes\QuoteVersionForm::violationLabels($review)) }}.
                    </x-ui.alert>
                @endif
            @endif
        </section>

        <section class="fv-card grid gap-4 p-5 sm:grid-cols-2" aria-labelledby="versao-condicoes">
            <h2 id="versao-condicoes" class="text-lg font-semibold sm:col-span-2">Condições</h2>
            <x-ui.select label="Condição de pagamento" name="paymentTermId" :options="$terms" placeholder="Selecione" wire:model="paymentTermId" required />
            <x-ui.input label="Válida até" name="validUntilDay" type="date" min="2000-01-01" max="2099-12-31" wire:model="validUntilDay" required />
            <x-ui.input label="Reservar estoque até (opcional)" name="stockHoldUntilDay" type="date" min="2000-01-01" max="2099-12-31" wire:model="stockHoldUntilDay" />
            <div class="sm:col-span-2">
                <x-ui.textarea label="Observações ao comprador (opcional)" name="notes" maxlength="2000" wire:model="notes" />
            </div>
            @if ($review?->requiresApproval())
                <div class="sm:col-span-2">
                    <x-ui.textarea label="Justificativa da exceção" name="justification" maxlength="1000" wire:model="justification" required />
                </div>
                @if ($selfApprovable)
                    <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
                        <input type="checkbox" wire:model.live="selfApprove" class="rounded border-slate-300"> Aprovar a exceção eu mesmo (fica registrado)
                    </label>
                @endif
            @endif
        </section>

        <div class="flex items-center gap-3">
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="submit" :disabled="$previewError !== null">
                {{ ! $review?->requiresApproval() ? 'Enviar ao cliente' : ($selfApprove ? 'Aprovar exceção e enviar' : 'Enviar para aprovação comercial') }}
            </x-ui.button>
            <span wire:loading wire:target="submit" class="text-sm text-slate-600" role="status">Enviando…</span>
        </div>
    </form>
</div>

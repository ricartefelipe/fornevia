<section class="fv-card" aria-labelledby="skus-title">
    <div class="flex flex-wrap items-center justify-between gap-2 px-6 pt-5">
        <h2 id="skus-title" class="text-lg font-semibold">SKUs e embalagens</h2>
        <p class="text-sm text-slate-600">Quantidades na unidade de venda; a conversão indica quantas unidades base ({{ $product->baseUnit->code }}) cabem em uma unidade de venda.</p>
    </div>

    @if ($message)
        <div class="px-6 pt-4"><x-ui.alert type="success">{{ $message }}</x-ui.alert></div>
    @endif

    <div class="mt-4 overflow-x-auto">
        @if ($skus->isEmpty())
            <div class="px-6 pb-6">
                <x-ui.empty-state title="Nenhum SKU cadastrado" description="O produto só pode ser ativado com ao menos um SKU ativo." />
            </div>
        @else
            <table class="fv-table">
                <thead>
                    <tr>
                        <th scope="col">Código</th>
                        <th scope="col">Variação</th>
                        <th scope="col">Venda e embalagem</th>
                        <th scope="col">Mínimo</th>
                        <th scope="col">Múltiplo</th>
                        <th scope="col">Conversão</th>
                        <th scope="col">Situação</th>
                        @if ($canViewCosts)
                            <th scope="col">Custo vigente</th>
                            <th scope="col">Política interna</th>
                        @endif
                        <th scope="col"><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($skus as $sku)
                        <tr wire:key="sku-{{ $sku->id }}" @class(['bg-brand-50' => $editingSkuId === $sku->id])>
                            <td class="font-mono">{{ $sku->code }}
                                @if ($sku->gtin)<br><span class="text-xs text-slate-500">GTIN {{ $sku->gtin }}</span>@endif
                            </td>
                            <td>
                                {{ $sku->variant_label ?? '—' }}
                                @foreach ($sku->attributes as $attribute => $value)
                                    <br><span class="text-xs text-slate-500">{{ $attribute }}: {{ $value }}</span>
                                @endforeach
                            </td>
                            <td>{{ $sku->salesUnit->code }}@if ($sku->package_label) · {{ $sku->package_label }}@endif</td>
                            <td>{{ $sku->min_order_quantity->format() }}</td>
                            <td>{{ $sku->order_multiple->format() }}</td>
                            <td class="whitespace-nowrap">1 {{ $sku->salesUnit->code }} = {{ \App\Data\Catalog\DecimalInput::format($sku->conversion_factor) }} {{ $product->baseUnit->code }}</td>
                            <td><x-ui.badge :color="match ($sku->status->value) { 'active' => 'green', 'archived' => 'slate', default => 'red' }">{{ $sku->status->label() }}</x-ui.badge></td>
                            @if ($canViewCosts)
                                <td>{{ isset($costs[$sku->id]) ? $costs[$sku->id]->cost_cents->format() : '—' }}</td>
                                <td class="text-xs">
                                    @php($policy = $policies[$sku->id] ?? null)
                                    @if ($policy)
                                        @if ($policy->min_unit_price_cents !== null)Preço mín. {{ $policy->min_unit_price_cents->format() }}<br>@endif
                                        @if ($policy->min_margin_bps !== null)Margem mín. {{ \App\Domain\Shared\Percentage::ofBasisPoints($policy->min_margin_bps, allowOutOfRange: true)->format() }}@endif
                                    @else
                                        —
                                    @endif
                                </td>
                            @endif
                            <td class="text-right">
                                @if ($canEdit && $sku->status !== \App\Enums\Catalog\SkuStatus::Archived)
                                    <x-ui.button variant="secondary" wire:click="edit('{{ $sku->id }}')">Editar<span class="sr-only"> SKU {{ $sku->code }}</span></x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($canEdit)
        <form wire:submit="save" class="border-t border-slate-200 p-6" novalidate aria-labelledby="sku-form-title">
            <h3 id="sku-form-title" class="text-base font-semibold">{{ $editingSkuId ? 'Editar SKU '.$code : 'Novo SKU' }}</h3>

            <fieldset class="mt-4 grid gap-4 sm:grid-cols-3">
                <legend class="sr-only">Identificação</legend>
                <x-ui.input label="Código" name="code" wire:model="code" required maxlength="40" hint="Único no catálogo. Ex.: CAF-500-CX12." />
                <x-ui.input label="GTIN (código de barras)" name="gtin" wire:model="gtin" inputmode="numeric" maxlength="20" hint="Opcional, de 8 a 14 dígitos." />
                <x-ui.select label="Situação" name="skuStatus" wire:model="skuStatus">
                    @foreach ($statuses as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Variação" name="variantLabel" wire:model="variantLabel" maxlength="120" hint="Ex.: Pacote 500 g, torra média." />
                <div class="sm:col-span-2">
                    <x-ui.textarea label="Atributos (um por linha, Nome: Valor)" name="attributesText" wire:model="attributesText" rows="3" />
                </div>
            </fieldset>

            <fieldset class="mt-6 grid gap-4 sm:grid-cols-3">
                <legend class="text-sm font-semibold text-slate-800">Venda e embalagem</legend>
                <x-ui.select label="Unidade de venda" name="salesUnitId" wire:model.live="salesUnitId" placeholder="Selecione" required>
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}">{{ $unit->code }} — {{ $unit->name }} ({{ $unit->decimals }} casa(s) decimal(is))</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Rótulo da embalagem" name="packageLabel" wire:model.live.debounce.500ms="packageLabel" maxlength="60" hint="Ex.: Caixa com 12." />
                <x-ui.input label="Fator de conversão" name="conversionFactor" wire:model.live.debounce.500ms="conversionFactor" inputmode="decimal"
                    hint="Unidades base ({{ $product->baseUnit->code }}) em 1 unidade de venda. Até 6 casas." />
                <x-ui.input label="Quantidade mínima" name="minOrderQuantity" wire:model.live.debounce.500ms="minOrderQuantity" inputmode="decimal" required />
                <x-ui.input label="Múltiplo de venda" name="orderMultiple" wire:model.live.debounce.500ms="orderMultiple" inputmode="decimal" required hint="Ex.: 12 para caixa fechada." />
                <x-ui.input label="Peso (g)" name="weightGrams" type="number" min="0" wire:model="weightGrams" />
            </fieldset>

            <fieldset class="mt-6 rounded-md border border-slate-200 bg-slate-50 p-4">
                <legend class="px-1 text-sm font-semibold text-slate-800">Exemplo de validação</legend>
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.input label="Quantidade de teste" name="sampleQuantity" wire:model.live.debounce.400ms="sampleQuantity" inputmode="decimal"
                        hint="Simula a regra de compra com os valores do formulário." />
                    <div class="sm:col-span-2" aria-live="polite" id="sku-sample-result">
                        @if ($sample)
                            @if ($sample['valid'])
                                <x-ui.alert type="success">{{ 'Quantidade aceita.'.($sample['base'] !== null ? ' '.$sample['base'].'.' : '') }}</x-ui.alert>
                            @else
                                <x-ui.alert type="warning">
                                    <ul class="list-disc pl-4">
                                        @foreach ($sample['messages'] as $line)
                                            <li>{{ $line }}</li>
                                        @endforeach
                                    </ul>
                                    @if ($sample['suggestion'])
                                        <p class="mt-1">Sugestão: {{ $sample['suggestion'] }}.</p>
                                    @endif
                                </x-ui.alert>
                            @endif
                        @endif
                    </div>
                </div>
            </fieldset>

            @if ($canViewCosts)
                <fieldset class="mt-6 grid gap-4 sm:grid-cols-3">
                    <legend class="text-sm font-semibold text-slate-800">Dados internos (custos e margens)</legend>
                    <x-ui.input label="Custo (R$)" name="cost" wire:model="cost" inputmode="decimal" hint="Um novo registro de custo é criado apenas quando o valor muda." />
                    <x-ui.input label="Preço mínimo (R$)" name="minUnitPrice" wire:model="minUnitPrice" inputmode="decimal" hint="Vazio = sem preço mínimo." />
                    <x-ui.input label="Margem mínima (%)" name="minMargin" wire:model="minMargin" inputmode="decimal" hint="Vazio = margem padrão." />
                </fieldset>
            @endif

            <div class="mt-6 flex gap-2">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editingSkuId ? 'Salvar SKU' : 'Cadastrar SKU' }}</span>
                    <span wire:loading wire:target="save">Salvando…</span>
                </x-ui.button>
                @if ($editingSkuId)
                    <x-ui.button variant="secondary" wire:click="cancel">Cancelar</x-ui.button>
                @endif
            </div>
        </form>
    @endif
</section>

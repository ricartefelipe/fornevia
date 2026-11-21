<div>
    <nav aria-label="Trilha de navegação" class="mb-3 text-sm text-slate-600">
        <a href="{{ route('portal.catalog') }}" class="font-medium text-brand-700 hover:underline">Catálogo</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{{ $product->name }}</span>
    </nav>

    <x-ui.page-header :title="$product->name" :description="implode(' · ', array_filter([$product->brand, $product->category])) ?: null" />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if ($product->description)
                <section class="fv-card p-5" aria-labelledby="produto-descricao">
                    <h2 id="produto-descricao" class="text-lg font-semibold">Descrição</h2>
                    <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $product->description }}</p>
                </section>
            @endif

            <section class="fv-card" aria-labelledby="produto-variacoes">
                <div class="p-5 pb-0">
                    <h2 id="produto-variacoes" class="text-lg font-semibold">Variações, quantidades e preços</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        Informe a quantidade na unidade de venda para conferir a regra de compra e o preço. Preços por volume valem para todas as unidades do item.
                    </p>
                    <p wire:loading.delay class="mt-2 text-sm text-slate-600" role="status">Atualizando preços…</p>
                    <div aria-live="polite" class="mt-2">
                        @if ($cartMessage)
                            <x-ui.alert type="success">
                                {{ $cartMessage }}
                                <a href="{{ route('portal.cart') }}" class="ml-1 font-medium underline">Ver carrinho</a>
                            </x-ui.alert>
                        @endif
                    </div>
                </div>

                <div class="mt-4 overflow-x-auto" wire:loading.class="opacity-60">
                    <table class="fv-table">
                        <caption class="sr-only">Variações de {{ $product->name }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">SKU</th>
                                <th scope="col">Detalhes</th>
                                <th scope="col">Quantidade</th>
                                <th scope="col" class="text-right">Preço</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($product->skus as $sku)
                                @php($inputId = 'quantidade-'.$sku->id)
                                @php($feedback = $sku->feedback)
                                @php($invalid = $feedback !== null && ! $feedback->valid)
                                <tr wire:key="sku-{{ $sku->id }}">
                                    <td class="font-mono text-xs">{{ $sku->code }}</td>
                                    <td>
                                        @if ($sku->description() !== '')
                                            <p>{{ $sku->description() }}</p>
                                        @endif
                                        @if ($sku->attributes !== [])
                                            <dl class="mt-1 text-xs text-slate-600">
                                                @foreach ($sku->attributes as $attribute => $value)
                                                    <div><dt class="inline font-medium">{{ $attribute }}:</dt> <dd class="inline">{{ $value }}</dd></div>
                                                @endforeach
                                            </dl>
                                        @endif
                                        <p class="mt-1 text-xs text-slate-600">{{ $sku->purchaseRuleLabel() }}</p>
                                        <p class="text-xs text-slate-500">Unidade de venda: {{ $sku->unitName }} ({{ $sku->unitCode }})</p>
                                    </td>
                                    <td class="min-w-56">
                                        <label for="{{ $inputId }}" class="fv-label">Quantidade em {{ $sku->unitCode }}</label>
                                        <input id="{{ $inputId }}" name="quantidades[{{ $sku->id }}]" type="text" inputmode="decimal" autocomplete="off" maxlength="20"
                                            placeholder="{{ $sku->quantity->format() }}"
                                            aria-describedby="{{ $inputId }}-retorno"
                                            @if ($invalid) aria-invalid="true" @endif
                                            wire:model.live.debounce.500ms="quantities.{{ $sku->id }}"
                                            @class(['fv-input', 'fv-input-error' => $invalid])>
                                        <div id="{{ $inputId }}-retorno" class="mt-1 text-xs" aria-live="polite">
                                            @if ($feedback === null)
                                                <p class="text-slate-500">Preço calculado para a quantidade mínima de compra.</p>
                                            @elseif ($feedback->valid)
                                                <p class="text-emerald-700">Quantidade válida.</p>
                                            @else
                                                <ul class="space-y-0.5 text-red-700">
                                                    @foreach ($feedback->messages as $message)
                                                        <li>{{ $message }}</li>
                                                    @endforeach
                                                </ul>
                                                @if ($feedback->hasSuggestion())
                                                    <p class="mt-1 text-slate-700">
                                                        Quantidade válida mais próxima: <strong>{{ $feedback->suggestion->format() }} {{ $sku->unitCode }}</strong>.
                                                    </p>
                                                    <button type="button" class="mt-1 font-medium text-brand-700 underline hover:text-brand-800"
                                                        wire:click="applySuggestion('{{ $sku->id }}')" wire:loading.attr="disabled">
                                                        Usar {{ $feedback->suggestion->format() }} {{ $sku->unitCode }}
                                                    </button>
                                                @endif
                                            @endif
                                        </div>
                                        @if ($canAddToCart)
                                            @php($cartError = $errors->first('cart.'.$sku->id))
                                            <x-ui.button variant="secondary" class="mt-2" wire:click="addToCart('{{ $sku->id }}')" wire:loading.attr="disabled"
                                                :aria-describedby="$cartError ? $inputId.'-carrinho' : null">
                                                Adicionar ao carrinho<span class="sr-only"> {{ $sku->code }}</span>
                                            </x-ui.button>
                                            @if ($cartError)
                                                <p id="{{ $inputId }}-carrinho" class="mt-1 text-xs text-red-700" role="alert">{{ $cartError }}</p>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        @if ($sku->price)
                                            <p>
                                                <span class="font-semibold text-slate-900">{{ $sku->priceLabel() }}</span>
                                                <span class="text-xs text-slate-600">/ {{ $sku->unitCode }}</span>
                                            </p>
                                            <p class="text-xs text-slate-500">{{ $sku->priceSourceLabel() }}</p>
                                            @if ($sku->price->hasVolumeTier())
                                                <p class="text-xs text-emerald-700">Preço por volume a partir de {{ $sku->price->tierMinQuantity?->format() }} {{ $sku->unitCode }}</p>
                                            @endif
                                            <p class="mt-1 text-xs text-slate-700">
                                                @if ($sku->price->total !== null)
                                                    Total para {{ $sku->quantity->format() }} {{ $sku->unitCode }}: <strong>{{ $sku->price->total->format() }}</strong>
                                                @else
                                                    Total acima do limite de cálculo para {{ $sku->quantity->format() }} {{ $sku->unitCode }}. Reduza a quantidade.
                                                @endif
                                            </p>
                                        @else
                                            <p class="text-sm text-slate-600">{{ $sku->priceLabel() }}</p>
                                            <p class="text-xs text-slate-500">Fale com o seu representante.</p>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <aside class="space-y-6" aria-label="Imagens e documentos">
            <section class="fv-card p-5" aria-labelledby="produto-imagens">
                <h2 id="produto-imagens" class="text-lg font-semibold">Imagens</h2>
                @if ($product->images === [])
                    <p class="mt-2 text-sm text-slate-600">Nenhuma imagem disponível para este produto.</p>
                @else
                    <ul class="mt-3 grid grid-cols-2 gap-3">
                        @foreach ($product->images as $index => $image)
                            <li wire:key="imagem-{{ $image->id }}">
                                <a href="{{ route('portal.media.download', $image->id) }}" target="_blank" rel="noopener" class="block rounded-md focus-visible:outline-2">
                                    <img src="{{ route('portal.media.download', $image->id) }}" loading="lazy" width="240" height="240"
                                        alt="{{ $product->name }}, imagem {{ $index + 1 }} de {{ count($product->images) }}"
                                        class="aspect-square w-full rounded-md border border-slate-200 object-cover">
                                    <span class="sr-only">(abre em nova aba)</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="fv-card p-5" aria-labelledby="produto-documentos">
                <h2 id="produto-documentos" class="text-lg font-semibold">Documentos</h2>
                @if ($product->documents === [])
                    <p class="mt-2 text-sm text-slate-600">Nenhum documento disponível para este produto.</p>
                @else
                    <ul class="mt-3 divide-y divide-slate-100 text-sm">
                        @foreach ($product->documents as $document)
                            <li wire:key="documento-{{ $document->id }}" class="flex items-center justify-between gap-3 py-2">
                                <a href="{{ route('portal.media.download', $document->id) }}" class="min-w-0 break-words font-medium text-brand-700 hover:underline">
                                    {{ $document->name }}<span class="sr-only"> (baixar arquivo)</span>
                                </a>
                                <span class="shrink-0 text-xs text-slate-500">{{ $document->formattedSize() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </aside>
    </div>
</div>

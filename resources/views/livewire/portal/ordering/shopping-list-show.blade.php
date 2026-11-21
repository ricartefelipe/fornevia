<div>
    <nav aria-label="Trilha de navegação" class="mb-3 text-sm text-slate-600">
        <a href="{{ route('portal.lists') }}" class="font-medium text-brand-700 hover:underline">Listas de compra</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{{ $list->name }}</span>
    </nav>

    <x-ui.page-header :title="$list->name" :description="$owns
        ? ($list->is_shared ? 'Lista compartilhada com os usuários da sua empresa.' : 'Lista pessoal.')
        : 'Lista compartilhada por '.($ownerName ?? 'outro usuário da empresa').'. Você pode enviá-la ao carrinho, mas somente quem a criou pode alterá-la.'">
        <x-slot:actions>
            <x-ui.button wire:click="addToCart" wire:loading.attr="disabled" wire:target="addToCart" :disabled="$items === []">Adicionar lista ao carrinho</x-ui.button>
            @if ($owns)
                <x-ui.button variant="danger" wire:click="delete" wire:confirm="Excluir esta lista de compra e os seus itens?" wire:loading.attr="disabled">Excluir lista</x-ui.button>
            @endif
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

    @if ($owns)
        <div class="mb-6 grid gap-6 lg:grid-cols-2">
            <section class="fv-card p-5" aria-labelledby="lista-dados">
                <h2 id="lista-dados" class="text-lg font-semibold">Dados da lista</h2>
                <form wire:submit="rename" class="mt-3 space-y-3">
                    <x-ui.input label="Nome da lista" name="name" maxlength="120" autocomplete="off" wire:model="name" required />
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="shared" class="rounded border-slate-300">
                        Compartilhar com os usuários da minha empresa
                    </label>
                    <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled" wire:target="rename">Salvar</x-ui.button>
                </form>
            </section>

            <section class="fv-card p-5" aria-labelledby="lista-incluir">
                <h2 id="lista-incluir" class="text-lg font-semibold">Incluir item</h2>
                <form wire:submit="addItem" class="mt-3 grid gap-3 sm:grid-cols-2">
                    <x-ui.input label="Código do SKU" name="skuCode" maxlength="40" autocomplete="off" wire:model="skuCode" required />
                    <x-ui.input label="Quantidade na unidade de venda" name="quantity" inputmode="decimal" maxlength="20" autocomplete="off" wire:model="quantity" required
                        hint="Formato brasileiro: 1.000 = mil; 2,5 = dois e meio." />
                    <div class="sm:col-span-2">
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="addItem">Incluir na lista</x-ui.button>
                    </div>
                </form>
            </section>
        </div>
    @endif

    @if ($items === [])
        <x-ui.empty-state title="Lista sem itens" :description="$owns ? 'Inclua itens pelo código do SKU.' : 'Quem criou a lista ainda não incluiu itens.'" />
    @else
        <section class="fv-card" aria-labelledby="lista-itens">
            <h2 id="lista-itens" class="sr-only">Itens da lista</h2>
            <div class="overflow-x-auto">
                <table class="fv-table">
                    <caption class="sr-only">Itens da lista {{ $list->name }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">Item</th>
                            <th scope="col">Quantidade</th>
                            @if ($owns)
                                <th scope="col"><span class="sr-only">Ações</span></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($items as $item)
                            @php($sku = $item->sku)
                            @php($inputId = 'lista-quantidade-'.$item->id)
                            @php($error = $errors->first('quantities.'.$item->id))
                            <tr wire:key="item-{{ $item->id }}">
                                <td>
                                    @if ($sku)
                                        <p class="font-medium text-slate-900">{{ $sku->product->name }}</p>
                                        <p class="font-mono text-xs text-slate-600">{{ $sku->code }}</p>
                                        @if ($sku->variant_label || $sku->package_label)
                                            <p class="text-xs text-slate-600">{{ implode(' · ', array_filter([$sku->variant_label, $sku->package_label])) }}</p>
                                        @endif
                                    @else
                                        <p class="text-sm text-red-700">Item indisponível para a sua empresa.</p>
                                    @endif
                                </td>
                                <td class="min-w-56">
                                    @if ($owns && $sku)
                                        <form wire:submit="updateItem('{{ $item->id }}')" class="flex items-start gap-2">
                                            <div>
                                                <label for="{{ $inputId }}" class="sr-only">Quantidade de {{ $sku->code }} em {{ $sku->salesUnit->code }}</label>
                                                <input id="{{ $inputId }}" type="text" inputmode="decimal" autocomplete="off" maxlength="20"
                                                    wire:model="quantities.{{ $item->id }}"
                                                    @if ($error) aria-invalid="true" aria-describedby="{{ $inputId }}-erro" @endif
                                                    @class(['fv-input w-28', 'fv-input-error' => $error])>
                                                <span class="text-xs text-slate-600">{{ $sku->salesUnit->code }}</span>
                                            </div>
                                            <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled">
                                                Atualizar<span class="sr-only"> quantidade de {{ $sku->code }}</span>
                                            </x-ui.button>
                                        </form>
                                    @else
                                        {{ $item->quantity->format() }} {{ $sku?->salesUnit->code }}
                                    @endif
                                    @if ($error)
                                        <p id="{{ $inputId }}-erro" class="mt-1 text-xs text-red-700">{{ $error }}</p>
                                    @endif
                                </td>
                                @if ($owns)
                                    <td class="text-right">
                                        <x-ui.button variant="secondary" wire:click="removeItem('{{ $item->id }}')" wire:loading.attr="disabled">
                                            Remover<span class="sr-only"> {{ $sku?->code ?? 'item indisponível' }}</span>
                                        </x-ui.button>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($report)
        @include('livewire.portal.ordering.partials.addition-report', ['report' => $report, 'reportId' => 'lista-resultado'])
    @endif
</div>

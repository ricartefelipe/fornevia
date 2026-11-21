<div>
    <x-ui.page-header title="Movimentar estoque"
        description="Entradas, ajustes e transferências são registrados como movimentos com responsável e data; o saldo é atualizado a partir deles. As quantidades são sempre na unidade base do produto." />

    @include('livewire.staff.inventory.partials.nav', ['current' => 'inventory.operations', 'canManage' => $canManage])

    @if ($message)
        <x-ui.alert type="success" class="mb-4">{{ $message }}</x-ui.alert>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="fv-card p-6 xl:col-span-2" aria-labelledby="operation-form-title">
            <h2 id="operation-form-title" class="text-lg font-semibold">{{ $operations[$operation] ?? 'Movimentação' }}</h2>

            <fieldset class="mt-4">
                <legend class="fv-label">Operação</legend>
                <div class="mt-2 flex flex-wrap gap-4">
                    @foreach ($operations as $value => $label)
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="radio" name="operation" value="{{ $value }}" wire:model.live="operation" class="border-slate-300 text-brand-700 focus:ring-brand-600"> {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <form wire:submit="submit" class="mt-6 space-y-4" novalidate>
                @if ($selectedSku)
                    <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                        <p class="font-mono font-semibold">{{ $selectedSku->code }}</p>
                        <p>{{ $selectedSku->product->name }}{{ $selectedSku->variant_label ? ' · '.$selectedSku->variant_label : '' }} · unidade base {{ $selectedSku->product->baseUnit->code }}</p>
                        <x-ui.button variant="secondary" class="mt-2" wire:click="clearSku">Trocar SKU</x-ui.button>
                    </div>
                @else
                    <x-ui.input label="Buscar SKU por código ou nome do produto" name="skuSearch" type="search" autocomplete="off"
                        wire:model.live.debounce.400ms="skuSearch" hint="Digite ao menos 2 caracteres." maxlength="100" />
                    <p wire:loading.delay wire:target="skuSearch" class="text-xs text-slate-500" role="status">Buscando SKUs…</p>
                    @if (mb_strlen(trim($skuSearch)) >= 2)
                        @if ($skuResults->isEmpty())
                            <p class="text-sm text-slate-600" role="status">Nenhum SKU encontrado.</p>
                        @else
                            <ul class="divide-y divide-slate-100 rounded-md border border-slate-200" aria-label="SKUs encontrados">
                                @foreach ($skuResults as $result)
                                    <li class="flex items-center justify-between gap-2 px-3 py-2 text-sm" wire:key="op-sku-{{ $result->id }}">
                                        <span>
                                            <span class="font-mono">{{ $result->code }}</span> · {{ $result->product->name }}
                                            <span class="text-xs text-slate-500">({{ $result->product->baseUnit->code }}{{ $result->status->value === 'archived' ? ', arquivado' : '' }})</span>
                                        </span>
                                        <x-ui.button variant="secondary" wire:click="selectSku('{{ $result->id }}')">
                                            Selecionar<span class="sr-only"> o SKU {{ $result->code }}</span>
                                        </x-ui.button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select :label="$operation === 'transfer' ? 'Depósito de origem' : 'Depósito'" name="warehouseId" wire:model="warehouseId"
                        placeholder="Selecione" :options="$originOptions" />
                    @if ($operation === 'transfer')
                        <x-ui.select label="Depósito de destino" name="toWarehouseId" wire:model="toWarehouseId"
                            placeholder="Selecione" :options="$destinationOptions" />
                    @endif
                    @if ($operation === 'adjustment')
                        <x-ui.select label="Sentido do ajuste" name="direction" wire:model="direction"
                            :options="['out' => 'Saída (baixa: perda, avaria, contagem menor)', 'in' => 'Entrada (acréscimo: contagem maior)']" />
                    @endif
                    <x-ui.input label="Quantidade" name="quantity" wire:model="quantity" inputmode="decimal" maxlength="30" required autocomplete="off"
                        :hint="$selectedSku
                            ? 'Na unidade base '.$selectedSku->product->baseUnit->code.($selectedSku->product->baseUnit->decimals === 0 ? ', sem casas decimais.' : ', até '.$selectedSku->product->baseUnit->decimals.' casa(s) decimal(is).').' Ex.: 1.000 ou 2,5.'
                            : 'Na unidade base do produto. Ex.: 1.000 ou 2,5.'" />
                </div>

                @if ($operation === 'adjustment')
                    <x-ui.textarea label="Motivo" name="reason" wire:model="reason" rows="2" maxlength="500" required />
                    <p class="-mt-2 text-xs text-slate-500">Obrigatório. Fica registrado no movimento e na auditoria. Uma saída nunca pode deixar o físico abaixo do reservado.</p>
                @else
                    <x-ui.input label="Observação" name="reason" wire:model="reason" maxlength="500" autocomplete="off"
                        :hint="$operation === 'receipt' ? 'Opcional: documento de origem ou fornecedor (ex.: NF 12345).' : 'Opcional. Apenas o disponível da origem pode ser transferido.'" />
                @endif

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="submit">
                    {{ match ($operation) { 'adjustment' => 'Registrar ajuste', 'transfer' => 'Registrar transferência', default => 'Registrar entrada' } }}
                </x-ui.button>
            </form>
        </section>

        <section class="fv-card overflow-x-auto" aria-labelledby="sku-balances-title">
            <h2 id="sku-balances-title" class="px-6 pt-5 text-lg font-semibold">Saldo do SKU</h2>
            @if (! $selectedSku)
                <p class="px-6 py-4 text-sm text-slate-600">Selecione um SKU para ver o saldo em cada depósito.</p>
            @elseif ($balances === [])
                <p class="px-6 py-4 text-sm text-slate-600">Nenhum depósito cadastrado.</p>
            @else
                <table class="fv-table mt-3">
                    <thead>
                        <tr>
                            <th scope="col">Depósito</th>
                            <th scope="col" class="text-right">Físico</th>
                            <th scope="col" class="text-right">Reservado</th>
                            <th scope="col" class="text-right">Disponível</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($balances as $row)
                            <tr wire:key="balance-{{ $row['warehouse']->id }}">
                                <td>
                                    <span class="font-mono">{{ $row['warehouse']->code }}</span>
                                    @unless ($row['warehouse']->is_active)
                                        <span class="block text-xs text-slate-500">inativo</span>
                                    @endunless
                                </td>
                                <td class="text-right">{{ $row['balance']->onHand->format() }}</td>
                                <td class="text-right">{{ $row['balance']->reserved->format() }}</td>
                                <td class="text-right font-semibold">{{ $row['balance']->available()->format() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="px-6 py-3 text-xs text-slate-500">Quantidades em {{ $selectedSku->product->baseUnit->code }}.</p>
            @endif
        </section>
    </div>
</div>

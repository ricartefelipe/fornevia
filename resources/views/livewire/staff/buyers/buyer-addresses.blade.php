<section class="fv-card" aria-labelledby="addresses-title">
    <div class="flex items-center justify-between gap-3 px-6 pt-5">
        <h2 id="addresses-title" class="text-lg font-semibold">Endereços</h2>
        @if ($canManage && ! $formOpen)
            <x-ui.button variant="secondary" wire:click="create">Adicionar endereço</x-ui.button>
        @endif
    </div>

    @if ($message)
        <div class="px-6 pt-3"><x-ui.alert type="success">{{ $message }}</x-ui.alert></div>
    @endif

    @if ($canManage && $formOpen)
        <form wire:submit="save" class="mx-6 mt-4 grid gap-3 rounded-md border border-slate-200 p-4 sm:grid-cols-6" novalidate aria-label="{{ $editingId ? 'Editar endereço' : 'Novo endereço' }}">
            <div class="sm:col-span-2"><x-ui.select label="Tipo" name="kind" id="address-kind" wire:model="kind" :options="$kinds" /></div>
            <div class="sm:col-span-4"><x-ui.input label="Identificação" name="label" id="address-label" wire:model="label" required maxlength="60" hint="Ex.: Matriz, Obra Vila Mariana" /></div>
            <div class="sm:col-span-2"><x-ui.input label="CEP" name="postalCode" id="address-postal-code" wire:model="postalCode" required inputmode="numeric" maxlength="9" autocomplete="postal-code" /></div>
            <div class="sm:col-span-4"><x-ui.input label="Logradouro" name="street" id="address-street" wire:model="street" required maxlength="160" autocomplete="address-line1" /></div>
            <div class="sm:col-span-2"><x-ui.input label="Número" name="number" id="address-number" wire:model="number" required maxlength="20" /></div>
            <div class="sm:col-span-4"><x-ui.input label="Complemento" name="complement" id="address-complement" wire:model="complement" maxlength="80" /></div>
            <div class="sm:col-span-3"><x-ui.input label="Bairro" name="district" id="address-district" wire:model="district" required maxlength="80" /></div>
            <div class="sm:col-span-2"><x-ui.input label="Cidade" name="city" id="address-city" wire:model="city" required maxlength="120" autocomplete="address-level2" /></div>
            <div class="sm:col-span-1"><x-ui.select label="UF" name="state" id="address-state" wire:model="state" placeholder="—" :options="$states" /></div>
            <div class="sm:col-span-3"><x-ui.input label="Destinatário" name="recipient" id="address-recipient" wire:model="recipient" maxlength="120" /></div>
            <div class="sm:col-span-3"><x-ui.select label="Unidade" name="buyerUnitId" id="address-unit" wire:model="buyerUnitId" placeholder="Nenhuma" :options="$units" /></div>
            <div class="flex flex-wrap gap-4 sm:col-span-6">
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="isDefault" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600"> Padrão para o tipo
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="isActive" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600"> Ativo
                </label>
            </div>
            <p class="text-xs text-slate-500 sm:col-span-6">Marcar como padrão substitui o padrão atual do mesmo tipo. Endereços inativos não podem ser padrão.</p>
            <div class="flex gap-2 sm:col-span-6">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Salvar endereço' : 'Cadastrar endereço' }}</x-ui.button>
                <x-ui.button variant="secondary" wire:click="cancel">Cancelar</x-ui.button>
            </div>
        </form>
    @endif

    <div class="overflow-x-auto pb-2">
        @if ($addresses->isEmpty())
            <p class="px-6 py-5 text-sm text-slate-600">Nenhum endereço cadastrado.</p>
        @else
            <table class="fv-table mt-4">
                <thead><tr><th scope="col">Endereço</th><th scope="col">Tipo</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($addresses as $address)
                        <tr wire:key="ad-{{ $address->id }}">
                            <td>
                                <span class="font-medium">{{ $address->label }}</span>
                                @if ($address->unit)<span class="text-xs text-slate-500"> · {{ $address->unit->name }}</span>@endif
                                <br>
                                <span class="text-xs text-slate-600">
                                    {{ $address->street }}, {{ $address->number }}{{ $address->complement ? ' - '.$address->complement : '' }} · {{ $address->district }} · {{ $address->city }}/{{ $address->state }} · CEP {{ \App\Domain\Buyers\PostalCode::format($address->postal_code) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap">
                                {{ $address->kind->label() }}
                                @if ($address->is_default)<x-ui.badge color="brand">Padrão</x-ui.badge>@endif
                                @unless ($address->is_active)<x-ui.badge>Inativo</x-ui.badge>@endunless
                            </td>
                            <td class="text-right">
                                @if ($canManage)
                                    <x-ui.button variant="secondary" wire:click="edit('{{ $address->id }}')">Editar<span class="sr-only"> {{ $address->label }}</span></x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</section>

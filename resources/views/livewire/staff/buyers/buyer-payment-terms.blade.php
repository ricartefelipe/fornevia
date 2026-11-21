<section class="fv-card" aria-labelledby="payment-terms-title">
    <div class="flex items-center justify-between gap-3 px-6 pt-5">
        <h2 id="payment-terms-title" class="text-lg font-semibold">Condições de pagamento</h2>
        @if ($canManage && ! $editing)
            <x-ui.button variant="secondary" wire:click="startEditing">Alterar condições</x-ui.button>
        @endif
    </div>

    @if ($message)
        <div class="px-6 pt-3"><x-ui.alert type="success">{{ $message }}</x-ui.alert></div>
    @endif

    @if ($canManage && $editing)
        <form wire:submit="save" class="mx-6 my-4 space-y-3 rounded-md border border-slate-200 p-4" novalidate>
            @if ($available->isEmpty())
                <x-ui.alert type="warning">Nenhuma condição de pagamento ativa cadastrada no distribuidor.</x-ui.alert>
            @else
                <table class="fv-table">
                    <caption class="sr-only">Escolha as condições permitidas e a condição padrão</caption>
                    <thead><tr><th scope="col">Permitida</th><th scope="col">Condição</th><th scope="col">Padrão</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($available as $term)
                            <tr wire:key="pt-opt-{{ $term->id }}">
                                <td>
                                    <input type="checkbox" id="term-{{ $term->id }}" value="{{ $term->id }}" wire:model="selectedTermIds"
                                        class="rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                                </td>
                                <td>
                                    <label for="term-{{ $term->id }}" class="font-medium">{{ $term->name }}</label>
                                    <span class="font-mono text-xs text-slate-500">{{ $term->code }}</span>
                                    @if ($term->requires_credit)<x-ui.badge color="amber">A prazo</x-ui.badge>@endif
                                    @unless ($term->is_active)<x-ui.badge color="red">Inativa</x-ui.badge>@endunless
                                </td>
                                <td>
                                    <input type="radio" name="defaultTermId" id="term-default-{{ $term->id }}" value="{{ $term->id }}" wire:model="defaultTermId"
                                        class="border-slate-300 text-brand-700 focus:ring-brand-600">
                                    <label for="term-default-{{ $term->id }}" class="sr-only">Definir {{ $term->name }} como padrão</label>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            @error('selectedTermIds')<p class="text-xs text-red-700" role="alert">{{ $message }}</p>@enderror
            @error('selectedTermIds.*')<p class="text-xs text-red-700" role="alert">{{ $message }}</p>@enderror
            @error('defaultTermId')<p class="text-xs text-red-700" role="alert">{{ $message }}</p>@enderror
            <p class="text-xs text-slate-500">A condição padrão precisa estar entre as permitidas. Sem condições marcadas, a empresa fica sem condição permitida.</p>
            <div class="flex gap-2">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Salvar condições</x-ui.button>
                <x-ui.button variant="secondary" wire:click="cancel">Cancelar</x-ui.button>
            </div>
        </form>
    @else
        @if ($assigned->isEmpty())
            <p class="px-6 py-5 text-sm text-slate-600">Nenhuma condição de pagamento permitida.</p>
        @else
            <ul class="space-y-2 px-6 py-4 text-sm">
                @foreach ($assigned as $row)
                    <li wire:key="pt-{{ $row->id }}">
                        <span class="font-medium">{{ $row->paymentTerm->name }}</span>
                        <span class="font-mono text-xs text-slate-500">{{ $row->paymentTerm->code }}</span>
                        @if ($row->is_default)<x-ui.badge color="brand">Padrão</x-ui.badge>@endif
                        @if ($row->paymentTerm->requires_credit)<x-ui.badge color="amber">A prazo</x-ui.badge>@endif
                        @unless ($row->paymentTerm->is_active)<x-ui.badge color="red">Inativa</x-ui.badge>@endunless
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</section>

<div>
    <x-ui.page-header title="Unidades de medida" description="Unidades de estoque e de venda. As casas decimais definem as quantidades aceitas (ex.: UN sem decimais, KG com 3)." />

    @include('livewire.staff.catalog.partials.nav')

    @if ($message)
        <x-ui.alert type="success" class="mb-4">{{ $message }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section @class(['fv-card overflow-x-auto', 'lg:col-span-2' => $canManage, 'lg:col-span-3' => ! $canManage]) aria-labelledby="units-title">
            <h2 id="units-title" class="sr-only">Lista de unidades</h2>
            @if ($units->isEmpty())
                <div class="p-4"><x-ui.empty-state title="Nenhuma unidade cadastrada" description="Cadastre ao menos a unidade base dos produtos (ex.: UN)." /></div>
            @else
                <table class="fv-table">
                    <thead><tr><th scope="col">Código</th><th scope="col">Nome</th><th scope="col">Casas decimais</th><th scope="col">SKUs vendidos</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($units as $unit)
                            <tr wire:key="unit-{{ $unit->id }}" @class(['bg-brand-50' => $editingId === $unit->id])>
                                <td class="font-mono">{{ $unit->code }}</td>
                                <td>{{ $unit->name }}</td>
                                <td>{{ $unit->decimals }}</td>
                                <td>{{ $unit->skus_count }}</td>
                                <td class="text-right">
                                    @if ($canManage)
                                        <x-ui.button variant="secondary" wire:click="edit('{{ $unit->id }}')">Editar<span class="sr-only"> {{ $unit->code }}</span></x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-4 py-3">{{ $units->links() }}</div>
            @endif
        </section>

        @if ($canManage)
            <section class="fv-card p-6" aria-labelledby="unit-form-title">
                <h2 id="unit-form-title" class="text-lg font-semibold">{{ $editingId ? 'Editar unidade' : 'Nova unidade' }}</h2>
                <form wire:submit="save" class="mt-4 space-y-3" novalidate>
                    <x-ui.input label="Código" name="code" wire:model="code" required maxlength="10" hint="Letras e números, ex.: UN, CX, KG." />
                    <x-ui.input label="Nome" name="name" wire:model="name" required maxlength="40" />
                    <x-ui.select label="Casas decimais" name="decimals" wire:model="decimals" :options="['0' => '0 (inteiros)', '1' => '1', '2' => '2', '3' => '3']" />
                    <div class="flex gap-2">
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Salvar</x-ui.button>
                        @if ($editingId)
                            <x-ui.button variant="secondary" wire:click="cancel">Cancelar</x-ui.button>
                        @endif
                    </div>
                </form>
            </section>
        @endif
    </div>
</div>

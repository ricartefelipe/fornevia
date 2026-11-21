@php($timezone = (string) config('app.business_timezone'))
<div>
    <x-ui.page-header title="Listas de compra" description="Guarde os itens que você compra com frequência e envie a lista inteira para o carrinho com preços e disponibilidade do dia." />

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)
            <x-ui.alert type="success">{{ $message }}</x-ui.alert>
        @endif
        @if ($failure)
            <x-ui.alert type="error">{{ $failure }}</x-ui.alert>
        @endif
    </div>

    <section class="fv-card mb-6 p-5" aria-labelledby="listas-nova">
        <h2 id="listas-nova" class="text-lg font-semibold">Nova lista</h2>
        <form wire:submit="create" class="mt-3 flex flex-col gap-4 sm:flex-row sm:items-end">
            <div class="sm:w-96">
                <x-ui.input label="Nome da lista" name="name" maxlength="120" autocomplete="off" wire:model="name" required />
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" wire:model="shared" class="rounded border-slate-300">
                Compartilhar com os usuários da minha empresa
            </label>
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="create">Criar lista</x-ui.button>
        </form>
    </section>

    @if ($lists->isEmpty())
        <x-ui.empty-state title="Nenhuma lista de compra" description="Crie uma lista para repetir compras com poucos cliques." />
    @else
        <section class="fv-card" aria-labelledby="listas-existentes">
            <h2 id="listas-existentes" class="sr-only">Listas disponíveis</h2>
            <div class="overflow-x-auto">
                <table class="fv-table">
                    <caption class="sr-only">Listas de compra</caption>
                    <thead>
                        <tr>
                            <th scope="col">Lista</th>
                            <th scope="col" class="text-right">Itens</th>
                            <th scope="col">Responsável</th>
                            <th scope="col">Atualizada em</th>
                            <th scope="col"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($lists as $list)
                            @php($mine = $list->membership_id === $membershipId)
                            <tr wire:key="lista-{{ $list->id }}">
                                <td>
                                    <a href="{{ route('portal.lists.show', $list->id) }}" class="font-medium text-brand-700 hover:underline">{{ $list->name }}</a>
                                    @if ($list->is_shared)
                                        <x-ui.badge color="blue" class="ml-1">Compartilhada</x-ui.badge>
                                    @endif
                                </td>
                                <td class="text-right">{{ $list->items_count }}</td>
                                <td class="text-sm">{{ $mine ? 'Você' : ($owners[$list->membership_id] ?? '—') }}</td>
                                <td class="text-sm">{{ $list->updated_at?->setTimezone($timezone)->format('d/m/Y H:i') }}</td>
                                <td>
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <x-ui.button variant="secondary" wire:click="addToCart('{{ $list->id }}')" wire:loading.attr="disabled" :disabled="$list->items_count === 0">
                                            Adicionar ao carrinho<span class="sr-only"> os itens de {{ $list->name }}</span>
                                        </x-ui.button>
                                        @if ($mine)
                                            <x-ui.button variant="danger" wire:click="delete('{{ $list->id }}')" wire:confirm="Excluir esta lista de compra e os seus itens?" wire:loading.attr="disabled">
                                                Excluir<span class="sr-only"> {{ $list->name }}</span>
                                            </x-ui.button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($report)
        @include('livewire.portal.ordering.partials.addition-report', ['report' => $report, 'reportId' => 'listas-resultado', 'reportTitle' => 'Resultado da lista '.$reportListName])
    @endif
</div>

<section class="fv-card" aria-labelledby="contacts-title">
    <div class="flex items-center justify-between gap-3 px-6 pt-5">
        <h2 id="contacts-title" class="text-lg font-semibold">Contatos</h2>
        @if ($canManage && ! $formOpen)
            <x-ui.button variant="secondary" wire:click="create">Adicionar contato</x-ui.button>
        @endif
    </div>

    @if ($message)
        <div class="px-6 pt-3"><x-ui.alert type="success">{{ $message }}</x-ui.alert></div>
    @endif

    @if ($canManage && $formOpen)
        <form wire:submit="save" class="mx-6 mt-4 grid gap-3 rounded-md border border-slate-200 p-4 sm:grid-cols-2" novalidate aria-label="{{ $editingId ? 'Editar contato' : 'Novo contato' }}">
            <div class="sm:col-span-2"><x-ui.input label="Nome" name="name" id="contact-name" wire:model="name" required maxlength="160" /></div>
            <x-ui.input label="E-mail" name="email" id="contact-email" type="email" wire:model="email" maxlength="254" />
            <x-ui.input label="Telefone" name="phone" id="contact-phone" type="tel" wire:model="phone" maxlength="20" />
            <x-ui.input label="Cargo" name="position" id="contact-position" wire:model="position" maxlength="80" />
            <label class="flex items-center gap-2 self-end text-sm text-slate-700">
                <input type="checkbox" wire:model="isPrimary" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600"> Contato principal
            </label>
            <div class="flex gap-2 sm:col-span-2">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Salvar contato' : 'Cadastrar contato' }}</x-ui.button>
                <x-ui.button variant="secondary" wire:click="cancel">Cancelar</x-ui.button>
            </div>
        </form>
    @endif

    <div class="overflow-x-auto pb-2">
        @if ($contacts->isEmpty())
            <p class="px-6 py-5 text-sm text-slate-600">Nenhum contato cadastrado.</p>
        @else
            <table class="fv-table mt-4">
                <thead><tr><th scope="col">Nome</th><th scope="col">Contato</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($contacts as $contact)
                        <tr wire:key="ct-{{ $contact->id }}">
                            <td>
                                <span class="font-medium">{{ $contact->name }}</span>
                                @if ($contact->is_primary)<x-ui.badge color="brand">Principal</x-ui.badge>@endif
                                @if ($contact->position)<br><span class="text-xs text-slate-500">{{ $contact->position }}</span>@endif
                            </td>
                            <td class="text-xs">{{ $contact->email ?? '—' }}<br>{{ $contact->phone ?? '' }}</td>
                            <td class="whitespace-nowrap text-right">
                                @if ($canManage)
                                    <x-ui.button variant="secondary" wire:click="edit('{{ $contact->id }}')">Editar<span class="sr-only"> {{ $contact->name }}</span></x-ui.button>
                                    <x-ui.button variant="danger" wire:click="delete('{{ $contact->id }}')" wire:confirm="Remover este contato?">Remover<span class="sr-only"> {{ $contact->name }}</span></x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</section>

<div>
    <x-ui.page-header title="Equipe e permissões" description="Usuários do distribuidor, papéis e convites. Revogações têm efeito imediato." />

    @if ($message)
        <x-ui.alert type="success" class="mb-4">{{ $message }}</x-ui.alert>
    @endif

    <section class="fv-card mb-6 p-6" aria-labelledby="invite-title">
        <h2 id="invite-title" class="text-lg font-semibold">Convidar usuário</h2>
        <form wire:submit="invite" class="mt-4 grid gap-4 sm:grid-cols-4" novalidate>
            <div class="sm:col-span-2"><x-ui.input label="E-mail" name="inviteEmail" type="email" wire:model="inviteEmail" required /></div>
            <x-ui.select label="Papel" name="inviteRoleId" wire:model="inviteRoleId" placeholder="Selecione"
                :options="$roles->pluck('name', 'id')->all()" />
            <x-ui.select label="Filial" name="inviteBranchId" wire:model="inviteBranchId" placeholder="Todas"
                :options="$branches->pluck('name', 'id')->all()" />
            <div class="sm:col-span-4"><x-ui.button type="submit" wire:loading.attr="disabled">Enviar convite</x-ui.button></div>
        </form>
    </section>

    <section class="fv-card mb-6 overflow-x-auto" aria-labelledby="members-title">
        <h2 id="members-title" class="px-6 pt-5 text-lg font-semibold">Usuários</h2>
        <div class="px-6 pt-3">
            <x-ui.input label="Justificativa para revogação" name="revokeReason" wire:model="revokeReason" hint="Obrigatória para revogar um acesso; fica registrada na auditoria." />
        </div>
        <table class="fv-table mt-4">
            <thead><tr><th scope="col">Nome</th><th scope="col">Papel</th><th scope="col">Filial</th><th scope="col">Situação</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($members as $member)
                    <tr wire:key="m-{{ $member->id }}">
                        <td><span class="font-medium">{{ $member->user->name }}</span><br><span class="text-xs text-slate-500">{{ $member->user->email }}</span></td>
                        <td>
                            @if ($member->isActive())
                                <form wire:submit="changeRole('{{ $member->id }}')" class="flex items-center gap-2">
                                    <label class="sr-only" for="role-{{ $member->id }}">Papel de {{ $member->user->name }}</label>
                                    <select id="role-{{ $member->id }}" class="fv-input mt-0" wire:model="roleSelections.{{ $member->id }}">
                                        @foreach ($roles as $role)
                                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                                        @endforeach
                                    </select>
                                    <x-ui.button type="submit" variant="secondary">Salvar</x-ui.button>
                                </form>
                            @else
                                {{ $member->role->name }}
                            @endif
                        </td>
                        <td>{{ $member->branch?->name ?? 'Todas' }}</td>
                        <td>
                            <x-ui.badge :color="$member->isActive() ? 'green' : 'slate'">{{ $member->status->label() }}</x-ui.badge>
                            @if (! $member->isActive() && $member->revoke_reason)
                                <p class="mt-1 text-xs text-slate-500">{{ $member->revoke_reason }}</p>
                            @endif
                        </td>
                        <td class="text-right">
                            @if ($member->isActive())
                                <x-ui.button variant="danger" wire:click="revoke('{{ $member->id }}')" wire:confirm="Revogar o acesso deste usuário?">Revogar</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="fv-card overflow-x-auto" aria-labelledby="invitations-title">
        <h2 id="invitations-title" class="px-6 pt-5 text-lg font-semibold">Convites pendentes</h2>
        @if ($invitations->isEmpty())
            <p class="px-6 py-5 text-sm text-slate-600">Nenhum convite pendente.</p>
        @else
            <table class="fv-table mt-4">
                <thead><tr><th scope="col">E-mail</th><th scope="col">Papel</th><th scope="col">Expira em</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($invitations as $invitation)
                        <tr wire:key="i-{{ $invitation->id }}">
                            <td>{{ $invitation->email }}</td>
                            <td>{{ $invitation->role->name }}</td>
                            <td>{{ $invitation->expires_at->setTimezone(config('app.business_timezone'))->format('d/m/Y H:i') }}</td>
                            <td class="text-right"><x-ui.button variant="secondary" wire:click="cancelInvitation('{{ $invitation->id }}')">Cancelar</x-ui.button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>

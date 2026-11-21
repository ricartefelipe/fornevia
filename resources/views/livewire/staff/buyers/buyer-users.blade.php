@php($timezone = (string) config('app.business_timezone'))
<section class="fv-card" aria-labelledby="buyer-users-title">
    <h2 id="buyer-users-title" class="px-6 pt-5 text-lg font-semibold">Usuários da empresa</h2>

    @if ($message)
        <div class="px-6 pt-3"><x-ui.alert type="success">{{ $message }}</x-ui.alert></div>
    @endif

    @if ($canManage)
        <form wire:submit="invite" class="mx-6 mt-4 grid gap-3 rounded-md border border-slate-200 p-4 sm:grid-cols-3" novalidate aria-label="Convidar usuário da empresa">
            <div class="sm:col-span-2"><x-ui.input label="E-mail do convidado" name="inviteEmail" id="buyer-invite-email" type="email" wire:model="inviteEmail" required maxlength="254" /></div>
            <x-ui.select label="Papel" name="inviteRoleId" id="buyer-invite-role" wire:model="inviteRoleId" placeholder="Selecione" :options="$roles" />
            <div class="sm:col-span-3">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="invite">
                    <span wire:loading.remove wire:target="invite">Enviar convite</span>
                    <span wire:loading wire:target="invite">Enviando…</span>
                </x-ui.button>
            </div>
        </form>
    @endif

    <div class="overflow-x-auto pb-2">
        @if ($members->isEmpty())
            <p class="px-6 py-5 text-sm text-slate-600">Nenhum usuário com acesso ao portal.</p>
        @else
            @if ($canManage)
                <div class="px-6 pt-4">
                    <x-ui.input label="Justificativa para revogação" name="revokeReason" id="buyer-revoke-reason" wire:model="revokeReason" maxlength="500" hint="Obrigatória para revogar um acesso; fica registrada na auditoria." />
                </div>
            @endif
            <table class="fv-table mt-4">
                <thead><tr><th scope="col">Usuário</th><th scope="col">Papel</th><th scope="col">Situação</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($members as $member)
                        <tr wire:key="bm-{{ $member->id }}">
                            <td><span class="font-medium">{{ $member->user->name }}</span><br><span class="text-xs text-slate-500">{{ $member->user->email }}</span></td>
                            <td>{{ $member->role->name }}</td>
                            <td>
                                <x-ui.badge :color="$member->isActive() ? 'green' : 'slate'">{{ $member->status->label() }}</x-ui.badge>
                                @if (! $member->isActive() && $member->revoke_reason)
                                    <p class="mt-1 text-xs text-slate-500">{{ $member->revoke_reason }}</p>
                                @endif
                            </td>
                            <td class="text-right">
                                @if ($canManage && $member->isActive())
                                    <x-ui.button variant="danger" wire:click="revoke('{{ $member->id }}')" wire:confirm="Revogar o acesso deste usuário ao portal?">Revogar<span class="sr-only"> acesso de {{ $member->user->name }}</span></x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($invitations->isNotEmpty())
        <div class="overflow-x-auto border-t border-slate-100 pb-2">
            <h3 class="px-6 pt-4 text-sm font-semibold text-slate-700">Convites pendentes</h3>
            <table class="fv-table mt-2">
                <thead><tr><th scope="col">E-mail</th><th scope="col">Papel</th><th scope="col">Expira em</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($invitations as $invitation)
                        <tr wire:key="bi-{{ $invitation->id }}">
                            <td>{{ $invitation->email }}</td>
                            <td>{{ $invitation->role->name }}</td>
                            <td class="whitespace-nowrap">{{ $invitation->expires_at->setTimezone($timezone)->format('d/m/Y H:i') }}</td>
                            <td class="text-right">
                                @if ($canManage)
                                    <x-ui.button variant="secondary" wire:click="cancelInvitation('{{ $invitation->id }}')">Cancelar<span class="sr-only"> convite de {{ $invitation->email }}</span></x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

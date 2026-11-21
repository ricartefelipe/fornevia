@php($timezone = (string) config('app.business_timezone'))
<div>
    <x-ui.page-header title="Delegações de aprovação"
        :description="$manages
            ? 'Delegue as aprovações de um aprovador a outro usuário da empresa por um prazo, por exemplo durante férias.'
            : 'Delegue as suas aprovações a outro usuário da empresa por um prazo, por exemplo durante férias.'">
        @if ($manages)
            <x-slot:actions>
                <a href="{{ route('portal.approval-rules') }}" class="fv-btn-secondary">Regras de aprovação</a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <div aria-live="polite" class="mb-4 space-y-2">
        @if ($message)
            <x-ui.alert type="success">{{ $message }}</x-ui.alert>
        @endif
        @if ($failure)
            <x-ui.alert type="error">{{ $failure }}</x-ui.alert>
        @endif
    </div>

    <x-ui.alert type="info" class="mb-6">
        Durante a vigência, o delegado decide em nome do titular nos níveis em que o titular é aprovador, inclusive em pedidos já enviados.
        Ninguém aprova o próprio pedido, nem como delegado. Toda delegação e revogação fica registrada na auditoria.
    </x-ui.alert>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            @foreach ([
                ['id' => 'delegacoes-vigentes', 'title' => 'Vigentes', 'entries' => $active, 'empty' => 'Nenhuma delegação vigente.'],
                ['id' => 'delegacoes-futuras', 'title' => 'Futuras', 'entries' => $scheduled, 'empty' => 'Nenhuma delegação agendada.'],
                ['id' => 'delegacoes-encerradas', 'title' => 'Encerradas', 'entries' => $ended, 'empty' => 'Nenhuma delegação encerrada ou revogada.'],
            ] as $group)
                <section class="fv-card" aria-labelledby="{{ $group['id'] }}">
                    <div class="px-5 pt-4">
                        <h2 id="{{ $group['id'] }}" class="text-lg font-semibold">{{ $group['title'] }} <span class="text-sm font-normal text-slate-500">({{ count($group['entries']) }})</span></h2>
                        @if ($group['id'] === 'delegacoes-encerradas')
                            <p class="mt-1 text-xs text-slate-500">As {{ $maxEnded }} mais recentes.</p>
                        @endif
                    </div>
                    @if ($group['entries'] === [])
                        <p class="px-5 py-4 text-sm text-slate-600">{{ $group['empty'] }}</p>
                    @else
                        <div class="overflow-x-auto p-2">
                            <table class="fv-table">
                                <caption class="sr-only">Delegações {{ mb_strtolower($group['title']) }}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Titular</th>
                                        <th scope="col">Delegado</th>
                                        <th scope="col">Vigência</th>
                                        <th scope="col">Motivo</th>
                                        <th scope="col">Situação</th>
                                        <th scope="col"><span class="sr-only">Ações</span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($group['entries'] as $entry)
                                        <tr wire:key="delegacao-{{ $entry->id }}">
                                            <td class="text-sm">{{ $entry->delegatorName }}</td>
                                            <td class="text-sm">{{ $entry->delegateName }}</td>
                                            <td class="whitespace-nowrap text-sm">{{ $entry->period->label() }}</td>
                                            <td class="text-xs">
                                                {{ $entry->reason }}
                                                <span class="block text-slate-500">Registrada por {{ $entry->createdByName }} em {{ $entry->createdAt->setTimezone($timezone)->format('d/m/Y H:i') }}</span>
                                            </td>
                                            <td class="text-xs">
                                                <x-ui.badge :color="$entry->stateColor()">{{ $entry->stateLabel() }}</x-ui.badge>
                                                @if ($entry->revokedAt)
                                                    <span class="mt-1 block text-slate-600">Em {{ $entry->revokedAt->setTimezone($timezone)->format('d/m/Y H:i') }}</span>
                                                @endif
                                            </td>
                                            <td class="text-right">
                                                @if ($entry->canRevoke)
                                                    <x-ui.button variant="danger" wire:click="revoke('{{ $entry->id }}')" wire:loading.attr="disabled"
                                                        wire:confirm="Revogar esta delegação? O delegado deixa de decidir em nome do titular imediatamente.">
                                                        Revogar<span class="sr-only"> a delegação de {{ $entry->delegatorName }} para {{ $entry->delegateName }}</span>
                                                    </x-ui.button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @endforeach
        </div>

        <section class="fv-card h-fit p-5" aria-labelledby="delegacao-nova">
            <h2 id="delegacao-nova" class="text-lg font-semibold">Nova delegação</h2>
            @if (! $canDelegate)
                <x-ui.alert type="info" class="mt-3">Somente usuários ativos com permissão de aprovar compras podem delegar as próprias aprovações.</x-ui.alert>
            @else
                <form wire:submit="save" class="mt-3 space-y-3" novalidate>
                    @if ($manages)
                        <x-ui.select label="Titular (quem delega)" name="delegatorId" wire:model.live="delegatorId" :options="$delegatorOptions" placeholder="Escolha o aprovador" required />
                    @else
                        <p class="text-sm text-slate-700"><span class="font-medium">Titular:</span> você ({{ $ownName }})</p>
                    @endif
                    <x-ui.select label="Delegado (quem decide no lugar)" name="delegateId" wire:model="delegateId" :options="$delegateOptions" placeholder="Escolha o delegado" required />
                    @if ($delegateOptions === [])
                        <p class="text-xs text-slate-500">Não há outro usuário ativo da empresa com permissão de aprovar compras.</p>
                    @endif
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.input label="Primeiro dia" name="firstDay" type="date" wire:model="firstDay" required />
                        <x-ui.input label="Último dia" name="lastDay" type="date" wire:model="lastDay" required />
                    </div>
                    <p class="text-xs text-slate-500">A delegação começa hoje ou depois, dura até {{ $maxDays }} dias e vale até o fim do último dia.</p>
                    <x-ui.textarea label="Motivo" name="reason" wire:model="reason" rows="2" maxlength="500"
                        :aria-describedby="$errors->has('reason') ? null : 'delegacao-motivo-ajuda'"></x-ui.textarea>
                    @unless ($errors->has('reason'))
                        <p id="delegacao-motivo-ajuda" class="-mt-2 text-xs text-slate-500">Obrigatório (ao menos 5 caracteres); fica registrado com o seu nome.</p>
                    @endunless
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Registrar delegação</x-ui.button>
                </form>
            @endif
        </section>
    </div>
</div>

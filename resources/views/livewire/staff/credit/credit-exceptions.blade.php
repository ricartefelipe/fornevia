@php($timezone = (string) config('app.business_timezone'))
<section class="fv-card" aria-labelledby="credit-exceptions-title">
    <div class="px-6 pt-5">
        <h2 id="credit-exceptions-title" class="text-lg font-semibold">Exceções temporárias de limite</h2>
        <p class="mt-1 text-sm text-slate-600">Valores somados ao limite durante a vigência. Somente exceções vigentes contam no disponível; encerradas e revogadas deixam de contar.</p>
    </div>

    @if ($message)
        <div class="px-6 pt-4"><x-ui.alert type="success">{{ $message }}</x-ui.alert></div>
    @endif

    <div class="grid gap-6 p-6 xl:grid-cols-3">
        <div class="overflow-x-auto xl:col-span-2">
            @if ($exceptions === null || $exceptions->isEmpty())
                <x-ui.empty-state title="Nenhuma exceção registrada" description="Exceções concedidas aparecem aqui com vigência, responsável e justificativa." />
            @else
                @if ($canManage)
                    <div class="mb-3">
                        <x-ui.input label="Justificativa para revogação" name="revokeReason" wire:model="revokeReason" maxlength="500"
                            hint="Obrigatória para revogar; registrada na auditoria." />
                    </div>
                @endif
                <table class="fv-table">
                    <thead>
                        <tr>
                            <th scope="col" class="text-right">Valor</th>
                            <th scope="col">Vigência</th>
                            <th scope="col">Concessão</th>
                            <th scope="col">Situação</th>
                            <th scope="col"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($exceptions as $entry)
                            <tr wire:key="credit-exception-{{ $entry->id }}">
                                <td class="whitespace-nowrap text-right font-medium">{{ $entry->amount->format() }}</td>
                                <td class="whitespace-nowrap">{{ $entry->period->label() }}</td>
                                <td class="text-xs">
                                    {{ $entry->grantedBy }}
                                    <span class="block text-slate-600">{{ $entry->reason }}</span>
                                </td>
                                <td class="text-xs">
                                    <x-ui.badge :color="$entry->stateColor()">{{ $entry->stateLabel() }}</x-ui.badge>
                                    @if ($entry->revokedAt)
                                        <span class="mt-1 block text-slate-600">
                                            Em {{ $entry->revokedAt->setTimezone($timezone)->format('d/m/Y H:i') }} por {{ $entry->revokedBy ?? 'Não identificado' }}@if ($entry->revocationReason): {{ $entry->revocationReason }}@endif
                                        </span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if ($canManage && $entry->isOpen())
                                        <x-ui.button variant="danger" wire:click="revoke('{{ $entry->id }}')" wire:confirm="Revogar esta exceção de limite?">
                                            Revogar<span class="sr-only"> a exceção de {{ $entry->amount->format() }} ({{ $entry->period->label() }})</span>
                                        </x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="pt-4">{{ $exceptions->links() }}</div>
            @endif
        </div>

        <div>
            @if ($canManage)
                <h3 class="text-base font-semibold">Conceder exceção</h3>
                <form wire:submit="grant" class="mt-3 space-y-3" novalidate>
                    <x-ui.input label="Valor (R$)" name="amount" wire:model="amount" inputmode="decimal" maxlength="25" required />
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.input label="Primeiro dia" name="firstDay" type="date" wire:model="firstDay" required />
                        <x-ui.input label="Último dia" name="lastDay" type="date" wire:model="lastDay" required />
                    </div>
                    <p class="text-xs text-slate-500">A vigência começa hoje ou depois e dura até {{ $maxDays }} dias; vale até o fim do último dia.</p>
                    <x-ui.textarea label="Justificativa" name="reason" wire:model="reason" rows="2" maxlength="500"
                        :aria-describedby="$errors->has('reason') ? null : 'exception-reason-hint'"></x-ui.textarea>
                    @unless ($errors->has('reason'))
                        <p id="exception-reason-hint" class="-mt-2 text-xs text-slate-500">Obrigatória (mínimo de {{ \App\Domain\Buyers\Justification::MIN_LENGTH }} caracteres); você fica registrado como responsável.</p>
                    @endunless
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="grant">Conceder exceção</x-ui.button>
                </form>
            @else
                <x-ui.alert type="info">Conceder e revogar exceções exige a permissão de gerenciar limites e exceções de crédito.</x-ui.alert>
            @endif
        </div>
    </div>
</section>

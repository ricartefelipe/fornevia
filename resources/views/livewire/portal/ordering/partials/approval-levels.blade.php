{{--
    Níveis da aprovação interna do pedido (somente leitura): quórum,
    situação, aprovadores retratados na submissão e decisões, inclusive as
    invalidadas pela reavaliação. Parâmetros: $levels (OrderShow::approvalLevels),
    $timezone e $levelsLabel (nome acessível da lista).
--}}
<ol class="mt-3 space-y-4" aria-label="{{ $levelsLabel ?? 'Níveis de aprovação' }}">
    @foreach ($levels as $level)
        @php($statusColor = match ($level['status']) {
            \App\Enums\Approvals\ApprovalStepStatus::Approved => 'green',
            \App\Enums\Approvals\ApprovalStepStatus::Rejected => 'red',
            default => 'amber',
        })
        <li wire:key="nivel-{{ $level['id'] }}" class="rounded-md border border-slate-200 p-4">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="font-semibold text-slate-900">Nível {{ $level['number'] }}</h3>
                <x-ui.badge :color="$statusColor">{{ $level['status']->label() }}</x-ui.badge>
                @if ($level['current'])
                    <x-ui.badge color="blue">Em análise</x-ui.badge>
                @endif
                <span class="text-sm text-slate-700">
                    {{ $level['approvals'] }} de {{ $level['required'] }} {{ $level['required'] === 1 ? 'aprovação necessária' : 'aprovações necessárias' }}
                </span>
                @if ($level['decidedAt'])
                    <span class="text-xs text-slate-600">
                        · concluído em <time datetime="{{ $level['decidedAt']->toIso8601String() }}">{{ $level['decidedAt']->setTimezone($timezone)->format('d/m/Y H:i') }}</time>
                    </span>
                @endif
            </div>
            <p class="mt-1 text-sm text-slate-700">
                <span class="text-slate-600">Aprovadores:</span>
                {{ $level['approvers'] === [] ? 'nenhum aprovador definido' : implode(', ', $level['approvers']) }}
            </p>

            @if ($level['decisions'] === [])
                <p class="mt-2 text-sm text-slate-600">Nenhuma decisão registrada neste nível.</p>
            @else
                <ul class="mt-2 space-y-2 border-l border-slate-200 pl-3 text-sm" aria-label="Decisões do nível {{ $level['number'] }}">
                    @foreach ($level['decisions'] as $decision)
                        <li wire:key="decisao-{{ $decision['id'] }}" @class(['text-slate-500' => $decision['invalidatedAt']])>
                            <span class="font-medium">{{ $decision['decision']->label() }}</span>
                            por {{ $decision['actor'] }}
                            @if ($decision['principal'])
                                em nome de {{ $decision['principal'] }} (delegação)
                            @endif
                            · <time datetime="{{ $decision['at']->toIso8601String() }}">{{ $decision['at']->setTimezone($timezone)->format('d/m/Y H:i') }}</time>
                            @if ($decision['comment'])
                                <span class="block text-slate-700">Comentário: {{ $decision['comment'] }}</span>
                            @endif
                            @if ($decision['invalidatedAt'])
                                <span class="block text-xs">
                                    Invalidada em <time datetime="{{ $decision['invalidatedAt']->toIso8601String() }}">{{ $decision['invalidatedAt']->setTimezone($timezone)->format('d/m/Y H:i') }}</time>
                                    pela reavaliação do pedido; não conta para o quórum.
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </li>
    @endforeach
</ol>

@php($timezone = (string) config('app.business_timezone'))
<section class="fv-card" aria-labelledby="history-title">
    <div class="flex items-baseline justify-between gap-3 px-6 pt-5">
        <h2 id="history-title" class="text-lg font-semibold">Histórico</h2>
        <p class="text-xs text-slate-500">Últimos {{ \App\Livewire\Staff\Buyers\BuyerHistory::LIMIT }} registros da empresa e dos seus cadastros.</p>
    </div>

    @if ($entries->isEmpty())
        <p class="px-6 py-5 text-sm text-slate-600">Nenhum registro no histórico.</p>
    @else
        <ol class="divide-y divide-slate-100 px-6 py-2" aria-label="Registros do histórico, do mais recente para o mais antigo">
            @foreach ($entries as $entry)
                <li wire:key="hl-{{ $entry['log']->id }}" class="py-3 text-sm">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <span class="font-medium">{{ $entry['label'] }}</span>
                        <time datetime="{{ $entry['log']->created_at->toIso8601String() }}" class="text-xs text-slate-500">
                            {{ $entry['log']->created_at->setTimezone($timezone)->format('d/m/Y H:i') }}
                        </time>
                    </div>
                    <p class="text-xs text-slate-600">Por {{ $entry['log']->actor?->name ?? 'Sistema' }}@if ($entry['summary']) · {{ $entry['summary'] }}@endif</p>
                    @if ($entry['log']->reason)
                        <p class="mt-1 text-xs text-slate-700"><span class="font-medium">Justificativa:</span> {{ $entry['log']->reason }}</p>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</section>

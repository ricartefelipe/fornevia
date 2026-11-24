<div>
    <x-ui.page-header title="Indicadores" :description="'De '.$periodFrom->format('d/m/Y').' a '.$periodUntil->format('d/m/Y').'.'" />

    <div class="fv-card mb-6 grid max-w-xl grid-cols-2 gap-3 p-4">
        <x-ui.input label="De" name="from" type="date" wire:model.live="from" />
        <x-ui.input label="Até" name="until" type="date" wire:model.live="until" />
    </div>

    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="fv-card p-5">
            <dt class="text-sm text-slate-600">Ticket médio</dt>
            <dd class="mt-1 text-2xl font-semibold">{{ $data['average_ticket']?->format() ?? '—' }}</dd>
            <dd class="mt-1 text-xs text-slate-600">{{ $data['revenue']->format() }} em {{ $data['orders'] }} pedidos confirmados ÷ quantidade de pedidos</dd>
        </div>
        <div class="fv-card p-5">
            <dt class="text-sm text-slate-600">Conversão de propostas</dt>
            <dd class="mt-1 text-2xl font-semibold">{{ $data['conversion']?->format() ?? '—' }}</dd>
            <dd class="mt-1 text-xs text-slate-600">{{ $data['accepted'] }} aceitas ÷ {{ $data['proposals'] }} propostas enviadas</dd>
        </div>
        <div class="fv-card p-5">
            <dt class="text-sm text-slate-600">Margem</dt>
            <dd class="mt-1 text-2xl font-semibold">{{ $data['margin']?->format() ?? '—' }}</dd>
            <dd class="mt-1 text-xs text-slate-600">(receita − custo) ÷ receita, nos itens com custo registrado ({{ $data['margin_revenue']->format() }})</dd>
        </div>
        <div class="fv-card p-5">
            <dt class="text-sm text-slate-600">Inadimplência</dt>
            <dd class="mt-1 text-2xl font-semibold">{{ $data['delinquency']?->format() ?? '—' }}</dd>
            <dd class="mt-1 text-xs text-slate-600">{{ $data['overdue']->format() }} vencidos em aberto ÷ {{ $data['due']->format() }} com vencimento no período</dd>
        </div>
    </dl>
</div>

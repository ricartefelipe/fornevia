<div>
    <x-ui.page-header title="Painel" description="Visão geral da operação comercial." />

    <section aria-labelledby="dash-plan" class="fv-card mb-6 p-6">
        <h2 id="dash-plan" class="text-sm font-semibold text-slate-700">Assinatura do Fornevia</h2>
        <p class="mt-1 text-lg font-semibold">{{ $subscription->plan->name }}
            <x-ui.badge :color="$subscription->status->value === 'active' ? 'green' : 'amber'">{{ $subscription->status->label() }}</x-ui.badge>
        </p>
        @if ($subscription->trial_ends_at)
            <p class="mt-1 text-sm text-slate-600">Avaliação até {{ $subscription->trial_ends_at->setTimezone(config('app.business_timezone'))->format('d/m/Y') }}.</p>
        @endif
    </section>

    <dl class="grid gap-4 sm:grid-cols-3">
        <div class="fv-card p-5">
            <dt class="text-sm text-slate-600">Usuários da equipe</dt>
            <dd class="mt-1 text-2xl font-semibold">{{ $staffCount }}<span class="text-sm font-normal text-slate-500"> / {{ $subscription->plan->max_staff_users ?? '∞' }}</span></dd>
        </div>
        <div class="fv-card p-5">
            <dt class="text-sm text-slate-600">Empresas compradoras</dt>
            <dd class="mt-1 text-2xl font-semibold">{{ $buyerCount }}</dd>
        </div>
        <div class="fv-card p-5">
            <dt class="text-sm text-slate-600">Filiais</dt>
            <dd class="mt-1 text-2xl font-semibold">{{ $branchCount }}<span class="text-sm font-normal text-slate-500"> / {{ $subscription->plan->max_branches ?? '∞' }}</span></dd>
        </div>
    </dl>
</div>

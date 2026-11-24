<div>
    <x-ui.page-header title="Painel" description="Visão geral da operação comercial." />

    <section aria-labelledby="dash-plan" class="fv-hero mb-6 overflow-hidden rounded-2xl p-6 text-white shadow-lg shadow-brand-900/20">
        <h2 id="dash-plan" class="text-sm font-medium text-brand-200">Assinatura do Fornevia</h2>
        <p class="mt-1 text-xl font-semibold">{{ $subscription->plan->name }}
            <x-ui.badge :color="$subscription->status->value === 'active' ? 'green' : 'amber'">{{ $subscription->status->label() }}</x-ui.badge>
        </p>
        @if ($subscription->trial_ends_at)
            <p class="mt-1 text-sm text-brand-100">Avaliação até {{ $subscription->trial_ends_at->setTimezone(config('app.business_timezone'))->format('d/m/Y') }}.</p>
        @endif
    </section>

    <dl class="grid gap-4 sm:grid-cols-3">
        @foreach ([
            ['users', 'Usuários da equipe', $staffCount, $subscription->plan->max_staff_users ?? '∞'],
            ['building', 'Empresas compradoras', $buyerCount, null],
            ['pin', 'Filiais', $branchCount, $subscription->plan->max_branches ?? '∞'],
        ] as [$icon, $label, $value, $limit])
            <div class="fv-card grid grid-cols-[auto_1fr] items-center gap-x-4 p-5">
                <dt class="contents">
                    <span class="fv-kpi-icon row-span-2"><x-ui.icon :name="$icon" /></span>
                    <span class="text-sm text-slate-500">{{ $label }}</span>
                </dt>
                <dd class="col-start-2 mt-0.5 text-2xl font-semibold tracking-tight text-slate-900">{{ $value }}@if ($limit !== null)<span class="text-sm font-normal text-slate-500"> / {{ $limit }}</span>@endif</dd>
            </div>
        @endforeach
    </dl>
</div>

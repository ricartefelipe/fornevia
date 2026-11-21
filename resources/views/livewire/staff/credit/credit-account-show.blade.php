@php($reasonHint = 'Obrigatória (mínimo de '.\App\Domain\Buyers\Justification::MIN_LENGTH.' caracteres); fica registrada no histórico e na auditoria.')
<div>
    <nav aria-label="Trilha de navegação" class="mb-3 text-sm">
        <a href="{{ route('credit.index') }}" class="text-brand-700 hover:underline">Crédito comercial</a>
        <span class="text-slate-400" aria-hidden="true">/</span>
        <span class="text-slate-600" aria-current="page">{{ $company->trade_name }}</span>
    </nav>

    <x-ui.page-header :title="$company->trade_name" :description="$company->legal_name.' · CNPJ '.\App\Domain\Shared\Cnpj::format($company->document)">
        <x-slot:actions>
            @if ($account)
                <x-ui.badge :color="$blocked ? 'red' : 'green'" class="text-sm">Crédito {{ mb_strtolower($account->status->label()) }}</x-ui.badge>
            @else
                <x-ui.badge color="slate" class="text-sm">Sem conta de crédito</x-ui.badge>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($message)
        <x-ui.alert type="success" class="mb-4">{{ $message }}</x-ui.alert>
    @endif

    @error('account')
        <x-ui.alert type="error" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    @if (! $account)
        <section class="fv-card p-6" aria-labelledby="open-account-title">
            <h2 id="open-account-title" class="text-lg font-semibold">Conta de crédito comercial</h2>
            <p class="mt-2 text-sm text-slate-600">Esta empresa ainda não tem conta de crédito: pedidos com condição de pagamento a prazo são recusados na confirmação.</p>

            @if ($canManage)
                <form wire:submit="open" class="mt-4 grid gap-4 sm:max-w-xl" novalidate>
                    <x-ui.input label="Limite inicial (R$)" name="openLimit" wire:model="openLimit" inputmode="decimal" maxlength="25" required
                        hint="Exemplo: 50.000,00. Use 0,00 para abrir a conta sem limite." />
                    <x-ui.input label="Tolerância de vencidos (dias)" name="openTolerance" type="number" min="0" max="{{ \App\Actions\Credit\CreditAccounts::MAX_TOLERANCE_DAYS }}"
                        wire:model="openTolerance" required hint="Dias após o vencimento em que títulos em aberto ainda não bloqueiam compras a prazo." />
                    <x-ui.textarea label="Justificativa" name="openReason" wire:model="openReason" rows="2" maxlength="500"
                        :aria-describedby="$errors->has('openReason') ? null : 'open-reason-hint'"></x-ui.textarea>
                    @unless ($errors->has('openReason'))
                        <p id="open-reason-hint" class="-mt-3 text-xs text-slate-500">{{ $reasonHint }}</p>
                    @endunless
                    <div>
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="open">
                            <span wire:loading.remove wire:target="open">Abrir conta de crédito</span>
                            <span wire:loading wire:target="open">Abrindo…</span>
                        </x-ui.button>
                    </div>
                </form>
            @else
                <x-ui.alert type="info" class="mt-4">Abrir a conta exige a permissão de gerenciar limites e exceções de crédito.</x-ui.alert>
            @endif
        </section>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            <section class="fv-card p-6 lg:col-span-2" aria-labelledby="position-title">
                <h2 id="position-title" class="text-lg font-semibold">Posição de crédito</h2>
                <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-slate-500">Limite concedido</dt><dd class="text-base font-medium">{{ $position->limit->format() }}</dd></div>
                    <div><dt class="text-slate-500">Exceções vigentes</dt><dd class="text-base font-medium">{{ $position->activeExceptions->format() }}</dd></div>
                    <div><dt class="text-slate-500">Comprometido</dt><dd class="text-base font-medium">{{ $position->committed->format() }}</dd><dd class="text-xs text-slate-500">Pedidos confirmados não faturados</dd></div>
                    <div><dt class="text-slate-500">Recebível</dt><dd class="text-base font-medium">{{ $position->receivable->format() }}</dd><dd class="text-xs text-slate-500">Faturas em aberto</dd></div>
                    <div><dt class="text-slate-500">Exposição</dt><dd class="text-base font-medium">{{ $position->exposure()->format() }}</dd><dd class="text-xs text-slate-500">Comprometido + recebível</dd></div>
                    <div>
                        <dt class="text-slate-500">Disponível</dt>
                        <dd @class(['text-base font-semibold', 'text-red-700' => ! $position->available()->isPositive(), 'text-emerald-800' => $position->available()->isPositive()])>{{ $position->available()->format() }}</dd>
                        <dd class="text-xs text-slate-500">Limite + exceções − exposição</dd>
                    </div>
                </dl>
                <p class="mt-4 text-xs text-slate-500">É o mesmo cálculo aplicado na confirmação de pedidos a prazo, com a conta bloqueada contra alterações simultâneas.</p>

                <div class="mt-4">
                    @switch($decision)
                        @case(\App\Domain\Credit\CreditDecision::Approved)
                            <x-ui.alert type="success">Novos pedidos a prazo são aceitos até {{ $position->available()->format() }}.</x-ui.alert>
                            @break
                        @case(\App\Domain\Credit\CreditDecision::Blocked)
                            <x-ui.alert type="error">Novos pedidos a prazo são recusados: o crédito está bloqueado.</x-ui.alert>
                            @break
                        @case(\App\Domain\Credit\CreditDecision::Overdue)
                            <x-ui.alert type="warning">Novos pedidos a prazo são recusados: há títulos vencidos há mais de {{ $account->overdue_tolerance_days }} dia(s).</x-ui.alert>
                            @break
                        @default
                            <x-ui.alert type="warning">Novos pedidos a prazo são recusados: não há limite disponível.</x-ui.alert>
                    @endswitch
                </div>
            </section>

            <section class="fv-card p-6" aria-labelledby="status-title">
                <h2 id="status-title" class="text-lg font-semibold">Situação</h2>
                <p class="mt-2 text-sm">
                    <x-ui.badge :color="$blocked ? 'red' : 'green'">{{ $account->status->label() }}</x-ui.badge>
                    <span class="ml-1 text-slate-600">Tolerância de vencidos: {{ $account->overdue_tolerance_days }} dia(s).</span>
                </p>
                @if ($blocked && $account->blocked_reason)
                    <div class="mt-3 rounded-md bg-slate-50 p-3 text-sm">
                        <p><span class="font-medium">Motivo do bloqueio:</span> {{ $account->blocked_reason }}</p>
                    </div>
                @endif

                @if ($canManage)
                    <form wire:submit="{{ $blocked ? 'unblock' : 'block' }}" class="mt-4 space-y-3" novalidate>
                        <x-ui.textarea :label="$blocked ? 'Justificativa do desbloqueio' : 'Motivo do bloqueio'" name="statusReason" wire:model="statusReason" rows="2" maxlength="500"
                            :aria-describedby="$errors->has('statusReason') ? null : 'status-reason-hint'"></x-ui.textarea>
                        @unless ($errors->has('statusReason'))
                            <p id="status-reason-hint" class="-mt-2 text-xs text-slate-500">{{ $reasonHint }}</p>
                        @endunless
                        @if ($blocked)
                            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="unblock">Desbloquear crédito</x-ui.button>
                        @else
                            <x-ui.button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="block"
                                wire:confirm="Bloquear o crédito? Novos pedidos a prazo desta empresa serão recusados.">Bloquear crédito</x-ui.button>
                        @endif
                    </form>
                @endif
            </section>
        </div>

        @if ($canManage)
            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <section class="fv-card p-6" aria-labelledby="limit-title">
                    <h2 id="limit-title" class="text-lg font-semibold">Alterar limite</h2>
                    <p class="mt-1 text-sm text-slate-600">Reduzir o limite abaixo da exposição não cancela pedidos já confirmados; novos pedidos a prazo ficam recusados até haver saldo disponível.</p>
                    <form wire:submit="changeLimit" class="mt-4 space-y-3" novalidate>
                        <x-ui.input label="Novo limite (R$)" name="newLimit" wire:model="newLimit" inputmode="decimal" maxlength="25" required />
                        <x-ui.textarea label="Justificativa" name="limitReason" wire:model="limitReason" rows="2" maxlength="500"
                            :aria-describedby="$errors->has('limitReason') ? null : 'limit-reason-hint'"></x-ui.textarea>
                        @unless ($errors->has('limitReason'))
                            <p id="limit-reason-hint" class="-mt-2 text-xs text-slate-500">{{ $reasonHint }}</p>
                        @endunless
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="changeLimit">Alterar limite</x-ui.button>
                    </form>
                </section>

                <section class="fv-card p-6" aria-labelledby="tolerance-title">
                    <h2 id="tolerance-title" class="text-lg font-semibold">Tolerância de vencidos</h2>
                    <p class="mt-1 text-sm text-slate-600">Títulos vencidos há mais dias que a tolerância bloqueiam novas compras a prazo.</p>
                    <form wire:submit="changeTolerance" class="mt-4 space-y-3" novalidate>
                        <x-ui.input label="Tolerância (dias)" name="tolerance" type="number" min="0" max="{{ \App\Actions\Credit\CreditAccounts::MAX_TOLERANCE_DAYS }}" wire:model="tolerance" required />
                        <x-ui.textarea label="Justificativa" name="toleranceReason" wire:model="toleranceReason" rows="2" maxlength="500"
                            :aria-describedby="$errors->has('toleranceReason') ? null : 'tolerance-reason-hint'"></x-ui.textarea>
                        @unless ($errors->has('toleranceReason'))
                            <p id="tolerance-reason-hint" class="-mt-2 text-xs text-slate-500">{{ $reasonHint }}</p>
                        @endunless
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="changeTolerance">Alterar tolerância</x-ui.button>
                    </form>
                </section>
            </div>
        @else
            <x-ui.alert type="info" class="mt-6">Seu perfil permite consultar o crédito. Alterações de limite, situação, tolerância e exceções exigem a permissão de gerenciar limites e exceções de crédito.</x-ui.alert>
        @endif

        <div class="mt-6">
            @livewire(\App\Livewire\Staff\Credit\CreditExceptions::class, ['buyerId' => $company->id], key('credit-exceptions-'.$company->id))
        </div>

        <div class="mt-6">
            @livewire(\App\Livewire\Staff\Credit\CreditHistory::class, ['buyerId' => $company->id], key('credit-history-'.$company->id))
        </div>
    @endif
</div>

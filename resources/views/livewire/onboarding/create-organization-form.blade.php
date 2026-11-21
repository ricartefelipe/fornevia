<div class="mx-auto max-w-3xl">
    <x-ui.page-header title="Cadastrar distribuidora" description="Crie a organização e comece o período de avaliação. Você será o proprietário da conta." />

    <form wire:submit="save" class="fv-card space-y-6 p-6" novalidate>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.input label="Razão social" name="legalName" wire:model="legalName" required />
            <x-ui.input label="Nome fantasia" name="tradeName" wire:model="tradeName" required />
            <x-ui.input label="CNPJ" name="document" wire:model="document" inputmode="numeric" hint="Somente a matriz. Filiais são cadastradas depois." required />
            <x-ui.input label="E-mail comercial" name="email" type="email" wire:model="email" />
            <x-ui.input label="Telefone" name="phone" wire:model="phone" />
        </div>

        <fieldset>
            <legend class="fv-label">Plano após o período de avaliação</legend>
            @error('planCode')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
            <div class="mt-2 grid gap-3 sm:grid-cols-3">
                @forelse ($plans as $plan)
                    <label class="fv-card flex cursor-pointer flex-col gap-1 p-4 has-[:checked]:border-brand-600 has-[:checked]:ring-1 has-[:checked]:ring-brand-600">
                        <span class="flex items-center gap-2">
                            <input type="radio" name="planCode" value="{{ $plan->code }}" wire:model="planCode" class="text-brand-700 focus:ring-brand-600">
                            <span class="font-semibold">{{ $plan->name }}</span>
                        </span>
                        <span class="text-sm text-slate-700">R$ {{ number_format($plan->monthly_price_cents / 100, 2, ',', '.') }}/mês</span>
                        <span class="text-xs text-slate-500">
                            {{ $plan->max_staff_users ? $plan->max_staff_users.' usuários' : 'Usuários ilimitados' }} ·
                            {{ $plan->max_branches ? $plan->max_branches.' filiais' : 'filiais ilimitadas' }} ·
                            {{ $plan->max_orders_per_month ? number_format($plan->max_orders_per_month, 0, ',', '.').' pedidos/mês' : 'pedidos ilimitados' }}
                        </span>
                        <span class="text-xs text-slate-500">Avaliação de {{ $plan->trial_days }} dias</span>
                    </label>
                @empty
                    <x-ui.empty-state title="Nenhum plano disponível" description="A plataforma ainda não publicou planos." class="sm:col-span-3" />
                @endforelse
            </div>
        </fieldset>

        <div class="flex items-center gap-3">
            <x-ui.button type="submit" wire:loading.attr="disabled">Cadastrar e iniciar avaliação</x-ui.button>
            <span wire:loading wire:target="save" class="text-sm text-slate-600">Criando organização...</span>
        </div>
    </form>
</div>

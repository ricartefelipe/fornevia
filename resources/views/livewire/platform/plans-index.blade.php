<div>
    <x-ui.page-header title="Planos da plataforma" description="Mensalidade, implantação, limites e módulos. Valores comerciais são hipóteses configuráveis.">
        <x-slot:actions><x-ui.button variant="secondary" wire:click="create">Novo plano</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    @if ($message)<x-ui.alert type="success" class="mb-4">{{ $message }}</x-ui.alert>@endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="fv-card overflow-x-auto lg:col-span-2" aria-label="Planos">
            <table class="fv-table">
                <thead><tr><th scope="col">Plano</th><th scope="col">Mensalidade</th><th scope="col">Limites</th><th scope="col">Módulos</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($plans as $plan)
                        <tr wire:key="p-{{ $plan->id }}">
                            <td><span class="font-medium">{{ $plan->name }}</span> <span class="font-mono text-xs text-slate-500">{{ $plan->code }}</span>
                                @unless ($plan->is_active)<x-ui.badge>Inativo</x-ui.badge>@endunless</td>
                            <td>R$ {{ number_format($plan->monthly_price_cents / 100, 2, ',', '.') }}</td>
                            <td class="text-xs">{{ $plan->max_staff_users ?? '∞' }} usuários · {{ $plan->max_branches ?? '∞' }} filiais · {{ $plan->max_orders_per_month ?? '∞' }} pedidos/mês</td>
                            <td class="text-xs">{{ implode(', ', $plan->modules) ?: '—' }}</td>
                            <td class="text-right"><x-ui.button variant="secondary" wire:click="edit('{{ $plan->id }}')">Editar</x-ui.button></td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state title="Nenhum plano cadastrado" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="fv-card p-6" aria-labelledby="plan-form">
            <h2 id="plan-form" class="text-lg font-semibold">{{ $editingId ? 'Editar plano' : 'Novo plano' }}</h2>
            <form wire:submit="save" class="mt-4 space-y-3" novalidate>
                <x-ui.input label="Código" name="code" wire:model="code" />
                <x-ui.input label="Nome" name="name" wire:model="name" />
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.input label="Mensalidade (R$)" name="monthlyPrice" wire:model="monthlyPrice" inputmode="decimal" />
                    <x-ui.input label="Implantação (R$)" name="setupFee" wire:model="setupFee" inputmode="decimal" />
                    <x-ui.input label="Usuários da equipe" name="maxStaffUsers" wire:model="maxStaffUsers" inputmode="numeric" hint="Vazio = ilimitado" />
                    <x-ui.input label="Filiais" name="maxBranches" wire:model="maxBranches" inputmode="numeric" />
                    <x-ui.input label="Pedidos por mês" name="maxOrdersPerMonth" wire:model="maxOrdersPerMonth" inputmode="numeric" />
                    <x-ui.input label="Dias de avaliação" name="trialDays" type="number" wire:model="trialDays" />
                </div>
                <fieldset>
                    <legend class="fv-label">Módulos incluídos</legend>
                    @foreach ($availableModules as $module)
                        <label class="mt-1 flex items-center gap-2 text-sm"><input type="checkbox" value="{{ $module->value }}" wire:model="modules" class="rounded border-slate-300 text-brand-700"> {{ $module->label() }}</label>
                    @endforeach
                </fieldset>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="isPublic" class="rounded border-slate-300 text-brand-700"> Disponível para contratação</label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="isActive" class="rounded border-slate-300 text-brand-700"> Ativo</label>
                <x-ui.button type="submit">Salvar</x-ui.button>
            </form>
        </section>
    </div>
</div>

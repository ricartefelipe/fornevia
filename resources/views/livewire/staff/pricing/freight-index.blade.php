<div>
    <x-ui.page-header title="Frete" description="Frete do distribuidor por UF e condições negociadas. O valor é calculado no checkout e exibido ao comprador como frete do distribuidor, não como cotação de transportadora." />

    <div aria-live="polite" class="mb-4">
        @if ($message)<x-ui.alert type="success">{{ $message }}</x-ui.alert>@endif
    </div>

    <section class="fv-card mb-6 overflow-x-auto" aria-labelledby="frete-regras">
        <h2 id="frete-regras" class="px-5 pt-5 text-lg font-semibold">Regras por região</h2>
        <p class="px-5 text-sm text-slate-700">Vale a regra ativa de maior prioridade que atende a UF do endereço de entrega; sem UFs, a regra atende todo o país.</p>
        <table class="fv-table mt-3">
            <caption class="sr-only">Regras de frete</caption>
            <thead><tr><th scope="col">Nome</th><th scope="col">UFs</th><th scope="col" class="text-right">Valor por pedido</th><th scope="col" class="text-right">Isento a partir de</th><th scope="col" class="text-right">Prioridade</th><th scope="col">Situação</th>@if ($canManage)<th scope="col"><span class="sr-only">Ações</span></th>@endif</tr></thead>
            <tbody>
                @forelse ($rules as $rule)
                    <tr wire:key="regra-{{ $rule->id }}">
                        <td class="font-medium">{{ $rule->name }}</td>
                        <td>{{ $rule->states === [] ? 'Todas' : implode(', ', $rule->states) }}</td>
                        <td class="text-right">{{ $rule->amount_cents->format() }}</td>
                        <td class="text-right">{{ $rule->free_from_cents?->format() ?? '—' }}</td>
                        <td class="text-right">{{ $rule->priority }}</td>
                        <td>{{ $rule->is_active ? 'Ativa' : 'Inativa' }}</td>
                        @if ($canManage)<td class="text-right"><x-ui.button variant="secondary" wire:click="edit('{{ $rule->id }}')">Editar</x-ui.button></td>@endif
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-slate-600">Nenhuma regra cadastrada: o checkout não cobra frete.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($canManage)
            <form wire:submit="save" class="grid gap-3 border-t border-slate-200 p-5 sm:grid-cols-3" novalidate>
                <h3 class="font-semibold sm:col-span-3">{{ $editingId ? 'Editar regra' : 'Nova regra' }}</h3>
                <x-ui.input label="Nome" name="name" maxlength="80" wire:model="name" required />
                <x-ui.input label="UFs (vazio = todas)" name="states" maxlength="120" wire:model="states" hint="Por exemplo: SP, RJ, MG" />
                <x-ui.input label="Valor por pedido" name="amount" inputmode="decimal" maxlength="20" wire:model="amount" required />
                <x-ui.input label="Isento a partir do subtotal (opcional)" name="freeFrom" inputmode="decimal" maxlength="20" wire:model="freeFrom" />
                <x-ui.input label="Prioridade" name="priority" inputmode="numeric" maxlength="4" wire:model="priority" />
                <label class="flex items-center gap-2 self-end text-sm text-slate-700"><input type="checkbox" wire:model="isActive" class="rounded border-slate-300"> Regra ativa</label>
                <div class="flex gap-3 sm:col-span-3">
                    <x-ui.button type="submit" wire:loading.attr="disabled">Salvar regra</x-ui.button>
                    @if ($editingId)<x-ui.button type="button" variant="secondary" wire:click="cancel">Cancelar</x-ui.button>@endif
                </div>
            </form>
        @endif
    </section>

    <section class="fv-card overflow-x-auto" aria-labelledby="frete-negociado">
        <h2 id="frete-negociado" class="px-5 pt-5 text-lg font-semibold">Condições negociadas</h2>
        <table class="fv-table mt-3">
            <caption class="sr-only">Condições de frete negociadas por empresa</caption>
            <thead><tr><th scope="col">Empresa</th><th scope="col">Condição</th><th scope="col" class="text-right">Valor</th></tr></thead>
            <tbody>
                @forelse ($conditions as $condition)
                    <tr wire:key="condicao-{{ $condition->id }}">
                        <td>{{ $condition->trade_name }}</td>
                        <td>{{ $condition->kind->label() }}</td>
                        <td class="text-right">{{ $condition->kind === \App\Enums\Fulfillment\FreightConditionKind::Fixed ? $condition->amount_cents->format() : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-slate-600">Nenhuma condição negociada.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($canManage)
            <form wire:submit="saveCondition" class="grid gap-3 border-t border-slate-200 p-5 sm:grid-cols-4" novalidate>
                <x-ui.input label="Buscar empresa" name="companySearch" type="search" maxlength="100" wire:model.live.debounce.400ms="companySearch" />
                <x-ui.select label="Empresa compradora" name="companyId" :options="$companies" placeholder="Escolha a empresa" wire:model="companyId" required />
                <x-ui.select label="Condição" name="conditionKind" :options="$kinds" wire:model.live="conditionKind" />
                <x-ui.input label="Valor fixo por pedido" name="conditionAmount" inputmode="decimal" maxlength="20" wire:model="conditionAmount" />
                <div class="sm:col-span-4"><x-ui.button type="submit" wire:loading.attr="disabled">Salvar condição</x-ui.button></div>
            </form>
        @endif
    </section>
</div>

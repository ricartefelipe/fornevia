{{-- Campos do cabeçalho da tabela de preço (criação e edição). --}}
<x-ui.input label="Código" name="code" wire:model="code" maxlength="30" autocomplete="off" required
    hint="Letras, números, ponto, hífen ou sublinhado; gravado em maiúsculas." />
<x-ui.input label="Nome" name="name" wire:model="name" maxlength="120" required />
<x-ui.select label="Escopo" name="scope" wire:model.live="scope" :options="$scopeOptions" />

@if ($scope === 'group')
    <x-ui.select label="Grupo de clientes" name="customerGroupId" wire:model="customerGroupId" placeholder="Selecione"
        :options="$groupOptions" />
    @if ($groupOptions === [])
        <p class="text-xs text-slate-600">Nenhum grupo de clientes cadastrado.</p>
    @endif
@elseif ($scope === 'customer')
    <x-ui.input label="Buscar empresa compradora" name="companySearch" type="search" autocomplete="off"
        wire:model.live.debounce.400ms="companySearch" hint="Nome fantasia, razão social ou CNPJ." />
    <x-ui.select label="Empresa compradora" name="buyerCompanyId" wire:model="buyerCompanyId" placeholder="Selecione"
        :options="$companyOptions" />
    <p wire:loading.delay wire:target="companySearch" class="text-xs text-slate-500" role="status">Buscando empresas…</p>
    @if ($companyOptions === [])
        <p class="text-xs text-slate-600">Nenhuma empresa encontrada para a busca.</p>
    @endif
@else
    <p class="text-xs text-slate-600">A tabela padrão vale para todas as empresas compradoras.</p>
@endif

<div class="grid grid-cols-2 gap-3">
    <x-ui.input label="Início da vigência" name="validFrom" type="date" wire:model="validFrom" required />
    <x-ui.input label="Término da vigência" name="validUntil" type="date" wire:model="validUntil" hint="Vazio: sem término." />
</div>
<x-ui.input label="Prioridade" name="priority" type="number" step="1" wire:model="priority"
    hint="Desempate entre tabelas do mesmo escopo: a maior prioridade vence." />

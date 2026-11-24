<div>
    <x-ui.page-header title="Empresas compradoras" description="Clientes B2B do distribuidor: dados cadastrais, situação, grupo e representante responsável.">
        <x-slot:actions>
            <a href="{{ route('buyers.groups') }}" class="fv-btn-secondary">Grupos de clientes</a>
            @if ($canManage && ! $creating)
                <x-ui.button wire:click="openForm">Nova empresa</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($portfolioOnly)
        <x-ui.alert type="info" class="mb-4">Você vê as empresas da sua carteira.</x-ui.alert>
    @endif

    @if ($canManage && $creating)
        <section class="fv-card mb-6 p-6" aria-labelledby="buyer-form-title">
            <h2 id="buyer-form-title" class="text-lg font-semibold">Nova empresa compradora</h2>
            <form wire:submit="save" class="mt-4 space-y-4" novalidate>
                @include('livewire.staff.buyers.partials.company-form-fields')
                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Cadastrar empresa</span>
                        <span wire:loading wire:target="save">Salvando…</span>
                    </x-ui.button>
                    <x-ui.button variant="secondary" wire:click="closeForm">Cancelar</x-ui.button>
                </div>
            </form>
        </section>
    @endif

    <section class="fv-card mb-4 p-4" aria-labelledby="buyer-filters-title">
        <h2 id="buyer-filters-title" class="sr-only">Filtros</h2>
        <div class="grid gap-4 sm:grid-cols-4" role="search">
            <div class="sm:col-span-2">
                <x-ui.input label="Buscar por nome ou CNPJ" name="search" type="search" wire:model.live.debounce.400ms="search" maxlength="100" />
            </div>
            <x-ui.select label="Situação" name="status" wire:model.live="status" placeholder="Todas" :options="\App\Livewire\Staff\Buyers\BuyerOptions::statuses()" />
            <div class="flex items-end">
                @if ($filtered)
                    <x-ui.button variant="secondary" wire:click="clearFilters">Limpar filtros</x-ui.button>
                @endif
            </div>
        </div>
    </section>

    <section class="fv-card overflow-x-auto" aria-labelledby="buyers-title">
        <h2 id="buyers-title" class="sr-only">Lista de empresas compradoras</h2>
        <p class="px-4 pt-3 text-sm text-slate-500" wire:loading wire:target="search,status,clearFilters,gotoPage,nextPage,previousPage" role="status">Carregando…</p>
        <table class="fv-table">
            <thead>
                <tr>
                    <th scope="col">Empresa</th>
                    <th scope="col">CNPJ</th>
                    <th scope="col">Grupo</th>
                    <th scope="col">Representante</th>
                    <th scope="col">Situação</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($companies as $company)
                    <tr wire:key="bc-{{ $company->id }}">
                        <td>
                            <span class="font-medium">{{ $company->trade_name }}</span><br>
                            <span class="text-xs text-slate-500">{{ $company->legal_name }}</span>
                        </td>
                        <td class="whitespace-nowrap font-mono text-xs">{{ \App\Domain\Shared\Cnpj::format($company->document) }}</td>
                        <td>{{ $company->customerGroup?->name ?? '—' }}</td>
                        <td>{{ $company->salesRep?->user->name ?? '—' }}</td>
                        <td><x-ui.badge :color="\App\Livewire\Staff\Buyers\BuyerOptions::statusColor($company->status)">{{ $company->status->label() }}</x-ui.badge></td>
                        <td class="text-right">
                            <a href="{{ route('buyers.show', ['buyer' => $company->id]) }}" class="font-medium text-brand-700 hover:underline">
                                Abrir<span class="sr-only"> {{ $company->trade_name }}</span>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            @if ($filtered)
                                <x-ui.empty-state title="Nenhuma empresa encontrada" description="Revise a busca ou a situação selecionada." />
                            @else
                                <x-ui.empty-state title="Nenhuma empresa compradora cadastrada" description="Cadastre a primeira empresa para definir condições, endereços e usuários." />
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>
    <div class="mt-4">{{ $companies->links() }}</div>
</div>

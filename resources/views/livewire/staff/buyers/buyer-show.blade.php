@php($timezone = (string) config('app.business_timezone'))
<div>
    <nav aria-label="Trilha de navegação" class="mb-3 text-sm">
        <a href="{{ route('buyers.index') }}" class="text-brand-700 hover:underline">Empresas compradoras</a>
        <span class="text-slate-400" aria-hidden="true">/</span>
        <span class="text-slate-600" aria-current="page">{{ $company->trade_name }}</span>
    </nav>

    <x-ui.page-header :title="$company->trade_name" :description="$company->legal_name.' · CNPJ '.\App\Domain\Shared\Cnpj::format($company->document)">
        <x-slot:actions>
            <x-ui.badge :color="\App\Livewire\Staff\Buyers\BuyerOptions::statusColor($company->status)" class="text-sm">{{ $company->status->label() }}</x-ui.badge>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($message)
        <x-ui.alert type="success" class="mb-4">{{ $message }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="fv-card p-6 lg:col-span-2" aria-labelledby="profile-title">
            <div class="flex items-center justify-between gap-3">
                <h2 id="profile-title" class="text-lg font-semibold">Dados cadastrais</h2>
                @if ($canManage && ! $editing)
                    <x-ui.button variant="secondary" wire:click="startEditing">Editar dados</x-ui.button>
                @endif
            </div>

            @if ($canManage && $editing)
                <form wire:submit="saveProfile" class="mt-4 space-y-4" novalidate>
                    @include('livewire.staff.buyers.partials.company-form-fields')
                    <p class="text-xs text-slate-500">A troca de grupo de clientes altera as tabelas de preço aplicáveis à empresa.</p>
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveProfile">
                            <span wire:loading.remove wire:target="saveProfile">Salvar alterações</span>
                            <span wire:loading wire:target="saveProfile">Salvando…</span>
                        </x-ui.button>
                        <x-ui.button variant="secondary" wire:click="cancelEditing">Cancelar</x-ui.button>
                    </div>
                </form>
            @else
                <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">Razão social</dt><dd class="font-medium">{{ $company->legal_name }}</dd></div>
                    <div><dt class="text-slate-500">Nome fantasia</dt><dd class="font-medium">{{ $company->trade_name }}</dd></div>
                    <div><dt class="text-slate-500">CNPJ</dt><dd class="font-mono">{{ \App\Domain\Shared\Cnpj::format($company->document) }}</dd></div>
                    <div><dt class="text-slate-500">Inscrição estadual</dt><dd>{{ $company->state_registration ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">E-mail</dt><dd>{{ $company->email ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">Telefone</dt><dd>{{ $company->phone ?? '—' }}</dd></div>
                    <div>
                        <dt class="text-slate-500">Grupo de clientes</dt>
                        <dd>{{ $company->customerGroup ? $company->customerGroup->name.' ('.$company->customerGroup->code.')' : 'Sem grupo' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Representante responsável</dt>
                        <dd>
                            @if ($company->salesRep)
                                {{ $company->salesRep->user->name }} · {{ $company->salesRep->role->name }}
                                @unless ($company->salesRep->isActive())
                                    <x-ui.badge color="amber">Acesso revogado</x-ui.badge>
                                @endunless
                            @else
                                Sem representante
                            @endif
                        </dd>
                    </div>
                    <div class="sm:col-span-2"><dt class="text-slate-500">Observações</dt><dd class="whitespace-pre-line">{{ $company->notes ?? '—' }}</dd></div>
                </dl>
            @endif
        </section>

        <section class="fv-card p-6" aria-labelledby="status-title">
            <h2 id="status-title" class="text-lg font-semibold">Situação</h2>
            <p class="mt-2 text-sm">
                <x-ui.badge :color="\App\Livewire\Staff\Buyers\BuyerOptions::statusColor($company->status)">{{ $company->status->label() }}</x-ui.badge>
                <span class="ml-1 text-slate-600">{{ $company->status->canPurchase() ? 'Pode comprar no portal.' : 'Compras no portal suspensas.' }}</span>
            </p>
            @if ($lastStatusChange)
                <div class="mt-3 rounded-md bg-slate-50 p-3 text-sm">
                    <p class="text-slate-500">Última alteração em {{ $lastStatusChange->created_at->setTimezone($timezone)->format('d/m/Y H:i') }} por {{ $lastStatusChange->actor?->name ?? 'Sistema' }}</p>
                    <p class="mt-1"><span class="font-medium">Justificativa:</span> {{ $lastStatusChange->reason }}</p>
                </div>
            @endif

            @if ($canManage)
                <form wire:submit="changeStatus" class="mt-4 space-y-3" novalidate>
                    <x-ui.select label="Nova situação" name="newStatus" wire:model="newStatus" placeholder="Selecione" :options="$statusOptions" />
                    <x-ui.textarea label="Justificativa" name="statusReason" wire:model="statusReason" rows="2" maxlength="500" :aria-describedby="$errors->has('statusReason') ? null : 'status-reason-hint'"></x-ui.textarea>
                    @unless ($errors->has('statusReason'))
                        <p id="status-reason-hint" class="-mt-2 text-xs text-slate-500">Obrigatória (mínimo de 5 caracteres); fica registrada no histórico.</p>
                    @endunless
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="changeStatus">Alterar situação</x-ui.button>
                </form>
            @endif
        </section>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        @livewire(\App\Livewire\Staff\Buyers\BuyerContacts::class, ['buyerId' => $company->id], key('contacts-'.$company->id))
        @livewire(\App\Livewire\Staff\Buyers\BuyerAddresses::class, ['buyerId' => $company->id], key('addresses-'.$company->id))
        @livewire(\App\Livewire\Staff\Buyers\BuyerUnits::class, ['buyerId' => $company->id], key('units-'.$company->id))
        @livewire(\App\Livewire\Staff\Buyers\BuyerCostCenters::class, ['buyerId' => $company->id], key('cost-centers-'.$company->id))
        @livewire(\App\Livewire\Staff\Buyers\BuyerPaymentTerms::class, ['buyerId' => $company->id], key('payment-terms-'.$company->id))
        @livewire(\App\Livewire\Staff\Buyers\BuyerUsers::class, ['buyerId' => $company->id], key('users-'.$company->id))
    </div>

    <div class="mt-6">
        @livewire(\App\Livewire\Staff\Buyers\BuyerHistory::class, ['buyerId' => $company->id], key('history-'.$company->id))
    </div>
</div>

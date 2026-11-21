<div>
    <x-ui.page-header title="Organizações" description="Distribuidores contratantes, plano e situação da assinatura." />
    @if ($message)<x-ui.alert type="success" class="mb-4">{{ $message }}</x-ui.alert>@endif
    <div class="mb-4 max-w-xl"><x-ui.input label="Justificativa para suspensão ou reativação" name="reason" wire:model="reason" /></div>
    <section class="fv-card overflow-x-auto" aria-label="Organizações">
        <table class="fv-table">
            <thead><tr><th scope="col">Organização</th><th scope="col">Plano</th><th scope="col">Assinatura</th><th scope="col">Acesso</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($organizations as $organization)
                    @php($subscription = $subscriptions->get($organization->id))
                    <tr wire:key="o-{{ $organization->id }}">
                        <td><span class="font-medium">{{ $organization->trade_name }}</span><br><span class="text-xs text-slate-500">{{ \App\Domain\Shared\Cnpj::format($organization->document) }}</span></td>
                        <td>{{ $subscription?->plan->name ?? '—' }}</td>
                        <td>{{ $subscription?->status->label() ?? 'Sem assinatura' }}</td>
                        <td><x-ui.badge :color="$organization->allowsAccess() ? 'green' : 'red'">{{ $organization->status->label() }}</x-ui.badge></td>
                        <td class="text-right">
                            @if ($organization->allowsAccess())
                                <x-ui.button variant="danger" wire:click="suspend('{{ $organization->id }}')">Suspender</x-ui.button>
                            @else
                                <x-ui.button variant="secondary" wire:click="reactivate('{{ $organization->id }}')">Reativar</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-ui.empty-state title="Nenhuma organização" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
    <div class="mt-4">{{ $organizations->links() }}</div>
</div>

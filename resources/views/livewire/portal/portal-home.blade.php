<div>
    <x-ui.page-header :title="$company->trade_name" :description="'Compras com as condições negociadas com '.$membership->tenant->trade_name.'.'" />

    <div class="grid gap-4 sm:grid-cols-2">
        <section class="fv-card p-5" aria-labelledby="portal-company">
            <h2 id="portal-company" class="text-sm font-semibold text-slate-700">Sua empresa</h2>
            <p class="mt-2 font-medium">{{ $company->legal_name }}</p>
            <p class="text-sm text-slate-600">CNPJ {{ \App\Domain\Shared\Cnpj::format($company->document) }}</p>
            <p class="mt-2"><x-ui.badge :color="$company->status->canPurchase() ? 'green' : 'red'">{{ $company->status->label() }}</x-ui.badge></p>
        </section>
        <section class="fv-card p-5" aria-labelledby="portal-rep">
            <h2 id="portal-rep" class="text-sm font-semibold text-slate-700">Representante responsável</h2>
            @if ($company->salesRep)
                <p class="mt-2 font-medium">{{ $company->salesRep->user->name }}</p>
                <p class="text-sm text-slate-600">{{ $company->salesRep->user->email }}</p>
            @else
                <p class="mt-2 text-sm text-slate-600">Nenhum representante designado.</p>
            @endif
        </section>
    </div>

    <x-ui.alert type="info" class="mt-6">
        Aprovações internas da sua empresa não garantem estoque nem crédito no distribuidor: essas condições são verificadas na confirmação do pedido.
    </x-ui.alert>
</div>

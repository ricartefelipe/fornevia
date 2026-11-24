<div>
    <x-ui.page-header :title="$company->trade_name" :description="'Compras com as condições negociadas com '.$membership->tenant->trade_name.'.'" />

    @inject('menu', 'App\Support\Navigation\Menu')
    @php($shortcuts = collect($menu->items())->keyBy('route'))
    @php($tiles = [
        ['portal.catalog', 'squares', 'Catálogo', 'Produtos e preços negociados'],
        ['portal.quick-order', 'bolt', 'Pedido rápido', 'Pelo código do SKU'],
        ['portal.cart', 'cart', 'Carrinho', 'Revise e confirme o pedido'],
        ['portal.orders', 'bag', 'Pedidos', 'Acompanhe entregas e faturas'],
    ])
    <nav aria-label="Atalhos" class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($tiles as [$route, $icon, $label, $hint])
            @if ($shortcuts->has($route))
                <a href="{{ route($route) }}" class="fv-card group flex items-center gap-4 p-5 transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-lg hover:shadow-brand-900/5">
                    <span class="fv-kpi-icon"><x-ui.icon :name="$icon" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-semibold text-slate-900">{{ $label }}</span>
                        <span class="block text-sm text-slate-500">{{ $hint }}</span>
                    </span>
                    <x-ui.icon name="arrow-right" class="size-5 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-brand-500" />
                </a>
            @endif
        @endforeach
    </nav>

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

@php($icons = [
    'dashboard' => 'home', 'buyers.index' => 'building', 'catalog.products' => 'squares', 'pricing.lists' => 'tag',
    'quotes.index' => 'document', 'orders.index' => 'bag', 'inventory.index' => 'cube', 'credit.index' => 'card',
    'fulfillment.index' => 'truck', 'returns.index' => 'return', 'finance.index' => 'banknotes', 'reports.indicators' => 'chart',
    'integrations.index' => 'link', 'audit.index' => 'shield', 'team.index' => 'users', 'branches.index' => 'pin',
    'subscription.show' => 'sparkles',
    'portal.home' => 'home', 'portal.catalog' => 'squares', 'portal.cart' => 'cart', 'portal.quick-order' => 'bolt',
    'portal.lists' => 'list', 'portal.quotes' => 'document', 'portal.approvals' => 'badge-check', 'portal.approval-rules' => 'sliders',
    'portal.delegations' => 'refresh', 'portal.orders' => 'bag', 'portal.returns' => 'return', 'portal.invoices' => 'banknotes',
    'portal.budgets' => 'wallet', 'portal.users' => 'users',
])
<ul class="space-y-1 px-3 py-4">
    @foreach ($items as $item)
        <li>
            <a href="{{ route($item['route']) }}" class="fv-nav-link" @if (request()->routeIs(\App\Support\Navigation\Menu::activePattern($item['route']))) aria-current="page" @endif>
                <x-ui.icon :name="$icons[$item['route']] ?? 'squares'" />
                <span>{{ $item['label'] }}</span>
            </a>
        </li>
    @endforeach
    @if (auth()->user()?->is_platform_admin)
        <li class="px-3 pt-5 pb-1 text-[11px] font-semibold uppercase tracking-wider text-brand-300/80">Plataforma</li>
        <li><a href="{{ route('platform.plans') }}" class="fv-nav-link" @if (request()->routeIs('platform.plans')) aria-current="page" @endif><x-ui.icon name="sparkles" /><span>Planos</span></a></li>
        <li><a href="{{ route('platform.organizations') }}" class="fv-nav-link" @if (request()->routeIs('platform.organizations')) aria-current="page" @endif><x-ui.icon name="building" /><span>Organizações</span></a></li>
    @endif
</ul>

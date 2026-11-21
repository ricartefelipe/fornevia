<ul class="space-y-1 px-3 py-4">
    @foreach ($items as $item)
        <li>
            <a href="{{ route($item['route']) }}" class="fv-nav-link" @if (request()->routeIs(\App\Support\Navigation\Menu::activePattern($item['route']))) aria-current="page" @endif>
                {{ $item['label'] }}
            </a>
        </li>
    @endforeach
    @if (auth()->user()?->is_platform_admin)
        <li class="pt-4 text-xs font-semibold uppercase tracking-wide text-brand-300">Plataforma</li>
        <li><a href="{{ route('platform.plans') }}" class="fv-nav-link" @if (request()->routeIs('platform.plans')) aria-current="page" @endif>Planos</a></li>
        <li><a href="{{ route('platform.organizations') }}" class="fv-nav-link" @if (request()->routeIs('platform.organizations')) aria-current="page" @endif>Organizações</a></li>
    @endif
</ul>
